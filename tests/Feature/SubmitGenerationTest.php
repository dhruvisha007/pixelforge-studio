<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Generations\GenerationUpdater;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private function runJob(Generation $generation): SubmitGeneration
    {
        $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();
        $job->handle(app(PixelforgeClient::class), app(GenerationUpdater::class));

        return $job;
    }

    private function errorResponse(int $status, string $code, ?int $retryAfter = null): PromiseInterface
    {
        return Http::response(
            ['error' => ['type' => 'x', 'code' => $code, 'message' => "Pixelforge said {$code}."]],
            $status,
            $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter],
        );
    }

    #[Test]
    public function it_sends_the_generation_with_an_idempotency_key_and_saves_pixelforges_id(): void
    {
        $generation = Generation::factory()->create(['reference_images' => ['https://example.com/style.png']]);
        $remote = $this->remoteGeneration($generation, 'queued');
        Http::fake([self::BASE.'/generations' => Http::response($remote, 202)]);

        $this->runJob($generation)->assertNotReleased();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->uuid)
            && $request->hasHeader('Authorization', 'Bearer pf_test_key')
            && $request->data() === [
                'prompt' => $generation->prompt,
                'size' => '1024x1024',
                'quality' => 'medium',
                'metadata' => ['generation_uuid' => $generation->uuid],
                'reference_images' => ['https://example.com/style.png'],
            ]);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame($remote['id'], $generation->pixelforge_id);
        $this->assertSame(self::RATES, $generation->rates);
    }

    #[Test]
    public function a_rejected_prompt_fails_straight_away_and_costs_nothing(): void
    {
        $generation = Generation::factory()->create(['prompt' => 'something [blocked]']);
        Http::fake([self::BASE.'/generations' => $this->errorResponse(400, 'content_policy_violation')]);

        $this->runJob($generation)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame(0, $generation->charge_nanodollars);
        $this->assertStringContainsString('content policy', $generation->error_message);
    }

    #[Test]
    public function an_overload_is_retried_after_retry_after_with_growing_backoff(): void
    {
        $generation = Generation::factory()->create();
        Http::fake([self::BASE.'/generations' => $this->errorResponse(503, 'overloaded', 2)]);

        $this->runJob($generation)->assertReleased(delay: 2);
        $this->assertSame(1, $generation->refresh()->submit_failures);

        $this->runJob($generation)->assertReleased(delay: 4);
        $this->runJob($generation)->assertReleased(delay: 8);
        $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
    }

    #[Test]
    public function it_gives_up_after_a_few_failures(): void
    {
        $generation = Generation::factory()->create(['submit_failures' => SubmitGeneration::MAX_FAILURES - 1]);
        Http::fake([self::BASE.'/generations' => $this->errorResponse(503, 'overloaded', 2)]);

        $this->runJob($generation)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->charge_nanodollars);
    }

    #[Test]
    public function hitting_the_concurrency_limit_waits_without_counting_as_a_failure(): void
    {
        $generation = Generation::factory()->create();
        Http::fake([self::BASE.'/generations' => $this->errorResponse(429, 'concurrency_limit_exceeded', 5)]);

        $this->runJob($generation)->assertReleased(delay: 5);

        $this->assertSame(0, $generation->refresh()->submit_failures);
        $this->assertSame(GenerationStatus::Pending, $generation->status);
    }

    #[Test]
    public function it_waits_locally_while_three_generations_are_already_in_flight(): void
    {
        Generation::factory()->count(3)->processing()->create();
        $generation = Generation::factory()->create();
        Http::fake();

        $this->runJob($generation)->assertReleased(delay: 5);

        Http::assertNothingSent();
    }

    #[Test]
    public function the_daily_limit_fails_with_a_clear_message(): void
    {
        $generation = Generation::factory()->create();
        Http::fake([self::BASE.'/generations' => $this->errorResponse(429, 'daily_limit_exceeded')]);

        $this->runJob($generation)->assertNotReleased();

        $this->assertStringContainsString('midnight UTC', $generation->refresh()->error_message);
    }

    #[Test]
    public function a_timeout_is_retried_with_the_same_idempotency_key(): void
    {
        $generation = Generation::factory()->create();
        Http::fake([self::BASE.'/generations' => Http::sequence()
            ->pushFailedConnection('timed out')
            // The first request did get through, so the retry returns that generation.
            ->push($this->remoteGeneration($generation, 'processing'), 202, ['Idempotent-Replayed' => 'true']),
        ]);

        $this->runJob($generation)->assertReleased(delay: 2);
        $this->runJob($generation)->assertNotReleased();

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $generation->uuid));
    }

    #[Test]
    public function when_the_job_runs_out_of_time_the_generation_fails_for_free(): void
    {
        $generation = Generation::factory()->create();

        (new SubmitGeneration($generation->id))->failed(null);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('submission_timed_out', $generation->error_code);
    }
}
