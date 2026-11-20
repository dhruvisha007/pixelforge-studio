<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Generations\GenerationUpdater;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;

/**
 * Webhook delivery is not guaranteed, so we poll any generation Pixelforge has not
 * told us about for a while. Also works without webhooks at all (local development).
 */
class SyncGenerations extends Command
{
    protected $signature = 'generations:sync {--limit=10 : Most generations to poll in one run}';

    protected $description = 'Poll Pixelforge for generations whose webhooks are late or missing';

    public function handle(PixelforgeClient $pixelforge, GenerationUpdater $updater): int
    {
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('pixelforge_id')
            ->where('last_synced_at', '<', now()->subSeconds(config('pixelforge.poll_after_seconds')))
            ->orderBy('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($stale as $generation) {
            try {
                $updater->applyPixelforgeState($generation, $pixelforge->generation($generation->pixelforge_id));
            } catch (PixelforgeException $e) {
                if ($e->isTemporary()) {
                    $this->warn("Pixelforge is busy ({$e->errorCode}); trying again next run.");
                    break;
                }

                report($e);
            }
        }

        $this->failLostSubmissions($updater);

        $this->info("Polled {$stale->count()} generation(s).");

        return self::SUCCESS;
    }

    /**
     * The submit job gives up after 10 minutes. Anything still pending well after
     * that lost its job (e.g. a crashed worker). Pixelforge never confirmed it, so
     * nothing was billed.
     */
    private function failLostSubmissions(GenerationUpdater $updater): void
    {
        Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('created_at', '<', now()->subMinutes(20))
            ->each(fn (Generation $generation) => $updater->failBeforeAccepted(
                $generation,
                'submission_lost',
                'We could not send this to Pixelforge. Please try again.',
            ));
    }
}
