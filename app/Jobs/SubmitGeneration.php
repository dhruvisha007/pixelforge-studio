<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Generations\GenerationUpdater;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a pending generation to Pixelforge.
 *
 * Every attempt sends the same parameters with the generation's uuid as the
 * Idempotency-Key, so a retry after a timeout returns the generation Pixelforge
 * already created instead of creating (and billing) a second one.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Give up after this many real failures (overloads, network errors). */
    public const MAX_FAILURES = 5;

    /** Errors that mean "wait your turn", not "something is wrong". */
    private const WAIT_CODES = ['concurrency_limit_exceeded', 'rate_limit_exceeded'];

    public int $maxExceptions = 3;

    public function __construct(public readonly int $generationId) {}

    /** Waiting for a free slot can take a while ([slow] images take up to 90 s), but not forever. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(PixelforgeClient $pixelforge, GenerationUpdater $updater): void
    {
        $generation = Generation::query()->find($this->generationId);

        // Already sent (its webhook may even have beaten our create request), or given up on.
        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        // Pixelforge allows 3 generations in flight per account. Wait locally for a
        // free slot instead of spending our 60 requests a minute on 429 responses.
        if ($this->inFlightAtPixelforge() >= config('pixelforge.max_concurrent_generations')) {
            $this->release(5);

            return;
        }

        try {
            $remote = $pixelforge->createGeneration($generation->pixelforgePayload(), $generation->uuid);
        } catch (PixelforgeException $e) {
            $this->handleError($generation, $e, $updater);

            return;
        }

        $updater->applyPixelforgeState($generation, $remote);
    }

    /** Called by the queue when retryUntil() has passed, or after too many unexpected exceptions. */
    public function failed(?Throwable $exception): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation !== null) {
            app(GenerationUpdater::class)->failBeforeAccepted(
                $generation,
                'submission_timed_out',
                'Pixelforge is too busy right now. Please try again in a minute.',
            );
        }
    }

    private function handleError(Generation $generation, PixelforgeException $e, GenerationUpdater $updater): void
    {
        if (in_array($e->errorCode, self::WAIT_CODES, true)) {
            $this->release($e->retryAfter ?? 5);

            return;
        }

        $generation->increment('submit_failures');

        if ($e->isTemporary() && $generation->submit_failures < self::MAX_FAILURES) {
            // Back off further each time: 2, 4, 8, 16 seconds, or longer if Pixelforge asks.
            $this->release(max($e->retryAfter ?? 0, 2 ** $generation->submit_failures));

            return;
        }

        Log::warning('Giving up on sending a generation to Pixelforge.', [
            'generation' => $generation->uuid,
            'code' => $e->errorCode,
            'message' => $e->getMessage(),
            // If this is false, Pixelforge may have created it anyway. Its webhook then
            // overrides our failure (see GenerationUpdater::canMoveTo()).
            'nothing_created' => $e->definitelyNothingCreated(),
        ]);

        $updater->failBeforeAccepted($generation, $e->errorCode, $e->userMessage());
    }

    private function inFlightAtPixelforge(): int
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            // Ignore any we have lost track of; the sync command catches up with those.
            ->where('accepted_at', '>', now()->subMinutes(10))
            ->count();
    }
}
