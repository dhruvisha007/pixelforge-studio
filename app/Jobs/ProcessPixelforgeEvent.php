<?php

namespace App\Jobs;

use App\Generations\GenerationUpdater;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Applies one stored webhook event. Runs on the queue so the webhook endpoint can
 * answer Pixelforge within its 10 second limit. Safe to run more than once.
 */
class ProcessPixelforgeEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(GenerationUpdater $updater): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        // Already handled (e.g. the same event was queued twice).
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $object = $event->payload['data']['object'] ?? null;

        // "ping" (the dashboard's test button) has no generation, so it is just marked done.
        if (str_starts_with($event->type, 'generation.') && is_array($object)) {
            $generation = Generation::findForPixelforgeObject($object);

            if ($generation !== null) {
                // Goes by the generation's status in the event, not the event type, so
                // a late "processing" event after "succeeded" changes nothing.
                $updater->applyPixelforgeState($generation, $object);
            } else {
                Log::warning('Pixelforge sent an event for a generation we do not know.', ['event' => $event->event_id, 'pixelforge_id' => $object['id'] ?? null]);
            }
        }

        $event->forceFill(['processed_at' => now()])->save();
    }
}
