<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Generations\GenerationUpdater;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class CreditsAndReportTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    #[Test]
    public function new_users_start_with_a_dollar_and_can_buy_more(): void
    {
        $user = User::factory()->create();
        $this->assertSame(1_000_000_000, $user->balance());

        $this->actingAs($user)->post('/credits/purchase', ['pack' => '5.00'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/credits/purchase', ['pack' => '1000.00'])->assertSessionHasErrors('pack');

        $this->assertSame(6_000_000_000, $user->balance());
        $this->actingAs($user)->get('/credits')->assertOk();
    }

    #[Test]
    public function the_sync_command_polls_generations_whose_webhooks_are_late(): void
    {
        Queue::fake();
        $stale = Generation::factory()->processing()->create(['last_synced_at' => now()->subMinutes(2)]);
        $fresh = Generation::factory()->processing()->create(['last_synced_at' => now()]);
        $lost = Generation::factory()->create(['created_at' => now()->subMinutes(30)]);

        $this->fakePixelforge([
            self::BASE.'/generations/'.$stale->pixelforge_id => Http::response($this->remoteGeneration($stale, 'succeeded')),
        ]);

        Artisan::call('generations:sync');

        $this->assertSame(GenerationStatus::Succeeded, $stale->refresh()->status);
        $this->assertSame(78_512_500, $stale->charge_nanodollars);
        $this->assertSame(GenerationStatus::Processing, $fresh->refresh()->status);
        $this->assertSame(GenerationStatus::Failed, $lost->refresh()->status);
        Http::assertSentCount(1);
    }

    #[Test]
    public function only_admins_can_see_the_report(): void
    {
        $this->fakePixelforge();

        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
    }

    #[Test]
    public function the_report_shows_spend_cost_and_profit_and_checks_them_against_pixelforge(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->forceFill(['is_admin' => true])->save();
        $alice = User::factory()->create(['name' => 'Alice']);

        $updater = app(GenerationUpdater::class);
        foreach ([$alice, $alice, $admin] as $user) {
            $generation = Generation::factory()->for($user)->processing()->create();
            $updater->applyPixelforgeState($generation, $this->remoteGeneration($generation, 'succeeded'));
        }
        $failed = Generation::factory()->for($alice)->processing()->create();
        $updater->applyPixelforgeState($failed, $this->remoteGeneration($failed, 'failed'));

        $today = now('UTC')->toDateString();
        $this->fakePixelforge([
            self::BASE.'/usage*' => Http::response([
                'object' => 'usage',
                'data' => [['date' => $today, 'generations' => 3, 'input_text_tokens' => 36, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 4500, 'cost_usd' => '0.188430000']],
                'total' => [],
            ]),
        ]);

        $this->actingAs($admin)->get('/admin/report')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/Report')
                ->where('report.totals.succeeded', 3)
                ->where('report.totals.failed', 1)
                ->where('report.totals.provider_cost', '0.188430000') // 3 × $0.06281
                ->where('report.totals.charged', '0.235537500') // 3 × $0.0785125
                ->where('report.totals.profit', '0.047107500')
                ->where('report.days.0.date', $today)
                ->where('report.days.0.pixelforge.matches', true)
                ->where('report.users.0.user', fn ($label) => str_contains($label, 'Alice'))
                ->where('report.users.0.succeeded', 2)
            );
    }
}
