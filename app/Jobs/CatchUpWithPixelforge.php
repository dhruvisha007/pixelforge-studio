<?php

namespace App\Jobs;

use App\Generations\PixelforgePoller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The same catch-up as the generations:sync command, queued from the studio page
 * while someone is watching images in progress. On a host that sleeps when idle
 * (no always-on scheduler) this is what still rescues a lost webhook.
 */
class CatchUpWithPixelforge implements ShouldQueue
{
    use Queueable;

    public function handle(PixelforgePoller $poller): void
    {
        $poller->pollStale();
        $poller->failLostSubmissions();
    }
}
