<?php

namespace App\Console\Commands;

use App\Generations\PixelforgePoller;
use Illuminate\Console\Command;

/**
 * Webhook delivery is not guaranteed, so the scheduler runs this every 15 seconds
 * to poll any generation Pixelforge has not told us about for a while. Also works
 * without webhooks at all (local development).
 */
class SyncGenerations extends Command
{
    protected $signature = 'generations:sync {--limit=10 : Most generations to poll in one run}';

    protected $description = 'Poll Pixelforge for generations whose webhooks are late or missing';

    public function handle(PixelforgePoller $poller): int
    {
        $polled = $poller->pollStale((int) $this->option('limit'));
        $failed = $poller->failLostSubmissions();

        $this->info("Polled {$polled} generation(s), failed {$failed} lost submission(s).");

        return self::SUCCESS;
    }
}
