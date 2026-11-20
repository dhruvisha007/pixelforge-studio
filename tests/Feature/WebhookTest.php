<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Generations\GenerationUpdater;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->fakePixelforge($this->fakeImageResponse());
    }

    #[Test]
    public function a_succeeded_event_records_usage_and_charges_cost_plus_25_percent_once(): void
    {
        $generation = Generation::factory()->processing()->create();
        $event = $this->webhookEvent($this->remoteGeneration($generation, 'succeeded'));

        $this->sendWebhook($event)->assertNoContent();

        $generation->refresh();
        // 12 × $5 + 1,100 × $40 + 1,500 × $12.50 per 1M tokens = $0.06281
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(62_810_000, $generation->provider_cost_nanodollars);
        $this->assertSame(78_512_500, $generation->charge_nanodollars);
        $this->assertSame(1500, $generation->reasoning_tokens);
        $this->assertSame(-78_512_500, $generation->charge()->value('amount_nanodollars'));
        // We keep our own copy of the image because Pixelforge's URL expires.
        $this->assertNotNull($generation->image_path);
        Storage::disk('local')->assertExists($generation->image_path);
    }

    #[Test]
    public function a_redelivered_event_is_stored_and_charged_only_once(): void
    {
        $generation = Generation::factory()->processing()->create();
        $event = $this->webhookEvent($this->remoteGeneration($generation, 'succeeded'));

        $this->sendWebhook($event)->assertNoContent();
        $this->sendWebhook($event)->assertNoContent();
        $this->sendWebhook($event)->assertNoContent();

        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(1, CreditTransaction::query()->where('generation_id', $generation->id)->count());
    }

    #[Test]
    public function two_different_success_events_for_the_same_generation_charge_once(): void
    {
        $generation = Generation::factory()->processing()->create();
        $object = $this->remoteGeneration($generation, 'succeeded');

        $this->sendWebhook($this->webhookEvent($object))->assertNoContent();
        $this->sendWebhook($this->webhookEvent($object))->assertNoContent();

        $this->assertSame(2, WebhookEvent::query()->count());
        $this->assertSame(1, CreditTransaction::query()->where('generation_id', $generation->id)->count());
    }

    #[Test]
    public function a_late_processing_event_never_moves_a_finished_generation_back(): void
    {
        $generation = Generation::factory()->queued()->create();

        $this->sendWebhook($this->webhookEvent($this->remoteGeneration($generation, 'succeeded')));
        $this->sendWebhook($this->webhookEvent($this->remoteGeneration($generation, 'processing')));

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
    }

    #[Test]
    public function a_late_success_event_does_not_overturn_a_failure_from_pixelforge(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->sendWebhook($this->webhookEvent($this->remoteGeneration($generation, 'failed')));
        $this->sendWebhook($this->webhookEvent($this->remoteGeneration($generation, 'succeeded')));

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertSame(0, CreditTransaction::query()->where('generation_id', $generation->id)->count());
    }

    #[Test]
    public function a_failed_generation_is_free(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->sendWebhook($this->webhookEvent($this->remoteGeneration($generation, 'failed')))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->charge_nanodollars);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertSame(0, CreditTransaction::query()->where('generation_id', $generation->id)->count());
    }

    #[Test]
    public function a_webhook_that_arrives_before_the_create_response_is_matched_by_metadata(): void
    {
        $generation = Generation::factory()->create(); // pending, no Pixelforge id yet
        $remote = $this->remoteGeneration($generation, 'processing');

        $this->sendWebhook($this->webhookEvent($remote))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertSame($remote['id'], $generation->pixelforge_id);

        // Then our create request returns, still saying "queued": nothing goes backwards,
        // and the job does not send the generation a second time.
        Http::fake([self::BASE.'/generations' => Http::response($remote + ['status' => 'queued'], 202)]);
        (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationUpdater::class));

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    #[Test]
    public function pixelforge_wins_over_a_failure_we_recorded_before_it_confirmed_the_generation(): void
    {
        // Every create request timed out, so we gave up, but one did get through.
        $generation = Generation::factory()->create([
            'status' => GenerationStatus::Failed,
            'error_code' => 'connection_failed',
            'charge_nanodollars' => 0,
        ]);

        $this->sendWebhook($this->webhookEvent($this->remoteGeneration($generation, 'succeeded')))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(78_512_500, $generation->charge_nanodollars);
        $this->assertSame(1, CreditTransaction::query()->where('generation_id', $generation->id)->count());
    }

    #[Test]
    public function requests_with_a_bad_or_old_signature_are_rejected(): void
    {
        $generation = Generation::factory()->processing()->create();
        $event = $this->webhookEvent($this->remoteGeneration($generation, 'succeeded'));

        $this->sendWebhook($event, secret: 'whsec_wrong')->assertStatus(400);
        $this->sendWebhook($event, timestamp: time() - 301)->assertStatus(400);
        $this->postJson('/webhooks/pixelforge', $event)->assertStatus(400);

        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
    }

    #[Test]
    public function ping_and_unknown_generation_events_are_acknowledged(): void
    {
        $this->sendWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => now()->toIso8601ZuluString(), 'data' => ['object' => []]])
            ->assertNoContent();

        $stranger = Generation::factory()->make(['uuid' => 'not-ours', 'pixelforge_id' => 'gen_unknown']);
        $this->sendWebhook($this->webhookEvent($this->remoteGeneration($stranger, 'succeeded')))->assertNoContent();

        $this->assertSame(2, WebhookEvent::query()->whereNotNull('processed_at')->count());
    }
}
