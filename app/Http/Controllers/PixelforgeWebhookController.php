<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPixelforgeEvent;
use App\Models\WebhookEvent;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Pixelforge events. Pixelforge needs a 2xx within 10 seconds, so we
 * only verify, store and queue the event here; the work happens in a job.
 */
class PixelforgeWebhookController
{
    public function __invoke(Request $request, WebhookSignature $signature): Response
    {
        // Verify the raw body, before any JSON decoding.
        $payload = $request->getContent();

        if (! $signature->isValid($payload, $request->header('Pixelforge-Signature'))) {
            return response('Invalid Pixelforge signature.', 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response('Malformed event.', 400);
        }

        // Pixelforge delivers at least once. The same event id is only stored once.
        WebhookEvent::recordOnce($event);

        $stored = WebhookEvent::query()->where('event_id', $event['id'])->first(['id', 'processed_at']);

        // Queue it unless it is already done. Queueing an unprocessed event twice is
        // harmless (processing is idempotent), and it means a redelivery recovers an
        // event whose first job was lost.
        if ($stored->processed_at === null) {
            ProcessPixelforgeEvent::dispatch($stored->id);
        }

        return response()->noContent();
    }
}
