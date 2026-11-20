<?php

namespace Tests\Feature;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\CatchUpWithPixelforge;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class GenerateTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePixelforge();
        Queue::fake();
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ];
    }

    #[Test]
    public function guests_are_sent_to_the_login_page(): void
    {
        $this->get('/studio')->assertRedirect('/login');
        $this->post('/generations', $this->form())->assertRedirect('/login');
    }

    #[Test]
    public function the_studio_page_loads(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->create();

        $this->actingAs($user)->get('/studio')->assertOk();
    }

    #[Test]
    public function watching_images_in_progress_catches_up_with_pixelforge_at_most_every_30_seconds(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/studio')->assertOk();
        Queue::assertNotPushed(CatchUpWithPixelforge::class); // nothing in progress

        Generation::factory()->for($user)->processing()->create();
        $this->actingAs($user)->get('/studio')->assertOk();
        $this->actingAs($user)->get('/studio')->assertOk();
        Queue::assertPushed(CatchUpWithPixelforge::class, 1);

        $this->travel(31)->seconds();
        $this->actingAs($user)->get('/studio')->assertOk();
        Queue::assertPushed(CatchUpWithPixelforge::class, 2);
    }

    #[Test]
    public function the_estimate_shows_the_price_range_before_submitting(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/generations/estimate', $this->form())
            ->assertOk()
            ->assertJson([
                'min_nanodollars' => 357_781_250, // $0.286225 + 25%
                'max_nanodollars' => 467_343_750, // $0.373875 + 25%
                'min' => '$0.3577',
                'max' => '$0.4674',
            ]);
    }

    #[Test]
    public function the_estimate_works_while_the_form_is_half_filled(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/generations/estimate', $this->form(['prompt' => '', 'reference_images' => ['https://exa', '']]))
            ->assertOk()
            ->assertJsonPath('tokens.max.input_image_tokens', 765);
    }

    #[Test]
    public function submitting_saves_a_pending_generation_and_queues_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', $this->form())->assertSessionHasNoErrors();

        $generation = $user->generations()->sole();
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(467_343_750, $generation->estimated_max_charge_nanodollars);
        $this->assertSame(['https://example.com/a.png', 'https://example.com/b.png'], $generation->reference_images);
        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id);
    }

    #[Test]
    public function the_most_an_image_can_cost_is_held_until_it_finishes(): void
    {
        $user = User::factory()->create(); // $1.00 welcome credit

        $this->actingAs($user)->post('/generations', $this->form());

        $this->assertSame(1_000_000_000, $user->balance());
        $this->assertSame(467_343_750, $user->reservedCredit());
        $this->assertSame(532_656_250, $user->availableCredit());
    }

    #[Test]
    public function users_cannot_start_an_image_they_might_not_be_able_to_pay_for(): void
    {
        $user = User::factory()->create();
        $user->creditTransactions()->create([
            'type' => CreditTransactionType::Charge,
            'amount_nanodollars' => -800_000_000,
            'description' => 'Earlier images',
        ]);

        $this->actingAs($user)->post('/generations', $this->form())->assertSessionHasErrors('credits');

        $this->assertSame(0, $user->generations()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_form_is_validated_like_pixelforge_validates_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', $this->form(['prompt' => '']))->assertSessionHasErrors('prompt');
        $this->actingAs($user)->post('/generations', $this->form(['prompt' => str_repeat('a', 4001)]))->assertSessionHasErrors('prompt');
        $this->actingAs($user)->post('/generations', $this->form(['size' => '512x512']))->assertSessionHasErrors('size');
        $this->actingAs($user)->post('/generations', $this->form(['quality' => 'ultra']))->assertSessionHasErrors('quality');
        $this->actingAs($user)->post('/generations', $this->form(['reference_images' => array_fill(0, 5, 'https://example.com/a.png')]))->assertSessionHasErrors('reference_images');
        $this->actingAs($user)->post('/generations', $this->form(['reference_images' => ['ftp://example.com/a.png']]))->assertSessionHasErrors('reference_images.0');

        $this->assertSame(0, $user->generations()->count());
    }

    #[Test]
    public function users_only_see_their_own_images(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->actingAs(User::factory()->create())
            ->get("/generations/{$generation->uuid}/image")
            ->assertNotFound();
    }
}
