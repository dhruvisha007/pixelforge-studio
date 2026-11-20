<?php

namespace App\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;

/**
 * The fallback for late or lost webhooks: asks Pixelforge directly about any
 * generation it has gone quiet on. Runs from the scheduler where there is one
 * (generations:sync) and from the studio page while someone is watching
 * (CatchUpWithPixelforge), so it also works on hosts that sleep when idle.
 */
final class PixelforgePoller
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly GenerationUpdater $updater,
    ) {}

    /** @return int how many generations were polled */
    public function pollStale(int $limit = 10): int
    {
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('pixelforge_id')
            ->where('last_synced_at', '<', now()->subSeconds(config('pixelforge.poll_after_seconds')))
            ->orderBy('last_synced_at')
            ->limit($limit)
            ->get();

        $polled = 0;

        foreach ($stale as $generation) {
            try {
                $this->updater->applyPixelforgeState($generation, $this->pixelforge->generation($generation->pixelforge_id));
                $polled++;
            } catch (PixelforgeException $e) {
                // Rate limited or overloaded: stop here, the next run picks up the rest.
                if ($e->isTemporary()) {
                    break;
                }

                report($e);
            }
        }

        return $polled;
    }

    /**
     * The submit job gives up after 10 minutes. Anything still pending well after
     * that lost its job (e.g. a crashed worker). Pixelforge never confirmed it, so
     * nothing was billed.
     *
     * @return int how many generations were failed
     */
    public function failLostSubmissions(): int
    {
        $lost = Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('created_at', '<', now()->subMinutes(20))
            ->get();

        foreach ($lost as $generation) {
            $this->updater->failBeforeAccepted($generation, 'submission_lost', 'We could not send this to Pixelforge. Please try again.');
        }

        return $lost->count();
    }
}
