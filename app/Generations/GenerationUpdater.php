<?php

namespace App\Generations;

use App\Billing\CostCalculator;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\CreditTransaction;
use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * The only place a generation's status changes, and the only place it is charged.
 *
 * Webhooks, polls and the create response all hand their copy of the Pixelforge
 * generation object to applyPixelforgeState(). It can be called any number of
 * times, in any order: a generation only ever moves forward, and it is charged
 * exactly once, when it first reaches "succeeded".
 */
final class GenerationUpdater
{
    public function __construct(private readonly CostCalculator $costs) {}

    /** @param  array<string, mixed>  $remote  a Pixelforge generation object */
    public function applyPixelforgeState(Generation $generation, array $remote): Generation
    {
        $incoming = GenerationStatus::tryFrom((string) ($remote['status'] ?? ''));

        if ($incoming === null || $incoming === GenerationStatus::Pending) {
            Log::warning('Ignoring a Pixelforge generation with an unknown status.', ['generation' => $generation->uuid, 'status' => $remote['status'] ?? null]);

            return $generation;
        }

        $justSucceeded = false;

        $generation = DB::transaction(function () use ($generation, $remote, $incoming, &$justSucceeded) {
            // A webhook, a poll and the create response can arrive at the same moment.
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->getKey());

            if ($generation->isKnownToPixelforge() && $generation->pixelforge_id !== ($remote['id'] ?? null)) {
                Log::error('A Pixelforge generation does not match ours.', ['generation' => $generation->uuid, 'pixelforge_id' => $remote['id'] ?? null]);

                return $generation;
            }

            if ($this->canMoveTo($generation, $incoming)) {
                $this->moveTo($generation, $incoming, $remote);
                $justSucceeded = $incoming === GenerationStatus::Succeeded;
            } elseif ($incoming === GenerationStatus::Succeeded && $generation->status === GenerationStatus::Succeeded && isset($remote['image_url'])) {
                // The same result again. Nothing to charge, but the signed image URL may be newer.
                $generation->image_url = $remote['image_url'];
            }

            $generation->pixelforge_id ??= $remote['id'] ?? null;
            $generation->rates ??= $remote['rates'] ?? null;
            $generation->accepted_at ??= $this->time($remote['created_at'] ?? null);
            $generation->started_at ??= $this->time($remote['started_at'] ?? null);
            $generation->last_synced_at = now();
            $generation->save();

            return $generation;
        });

        if ($justSucceeded) {
            StoreGenerationImage::dispatch($generation->id);
        }

        return $generation;
    }

    /**
     * For generations Pixelforge never accepted: rejected at submission, or we
     * gave up trying to send it. Nothing was billed, so nothing is charged.
     */
    public function failBeforeAccepted(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->getKey());

            if ($generation->status !== GenerationStatus::Pending) {
                return; // Pixelforge accepted it after all.
            }

            $generation->fill([
                'status' => GenerationStatus::Failed,
                'error_code' => $code,
                'error_message' => $message,
                'provider_cost_nanodollars' => 0,
                'charge_nanodollars' => 0,
                'completed_at' => now(),
            ])->save();
        });
    }

    private function canMoveTo(Generation $generation, GenerationStatus $incoming): bool
    {
        if ($generation->status->isFinal()) {
            // A result from Pixelforge never changes. Only a failure we recorded ourselves,
            // before Pixelforge confirmed the generation, gives way to what Pixelforge says
            // (e.g. every create request timed out, but one of them did get through).
            return ! $generation->isKnownToPixelforge();
        }

        return $incoming->stage() > $generation->status->stage();
    }

    private function moveTo(Generation $generation, GenerationStatus $status, array $remote): void
    {
        $generation->status = $status;

        match ($status) {
            GenerationStatus::Succeeded => $this->recordSuccess($generation, $remote),
            GenerationStatus::Failed => $this->recordFailure($generation, $remote),
            default => null,
        };
    }

    private function recordSuccess(Generation $generation, array $remote): void
    {
        $usage = $remote['usage'] ?? null;
        $rates = $remote['rates'] ?? $generation->rates;

        if (! is_array($usage) || ! is_array($rates)) {
            // Throwing rolls the transaction back; the next webhook retry or poll tries again.
            throw new UnexpectedValueException("Pixelforge reported generation {$generation->uuid} succeeded without usage or rates.");
        }

        $providerCost = $this->costs->providerCost($usage, $rates);
        $charge = $this->costs->userCharge($providerCost);

        if ($charge > $generation->estimated_max_charge_nanodollars) {
            Log::warning('A generation cost more than its estimate.', ['generation' => $generation->uuid, 'charge' => $charge, 'estimate' => $generation->estimated_max_charge_nanodollars]);
        }

        $generation->fill([
            'input_text_tokens' => (int) ($usage['input_text_tokens'] ?? 0),
            'input_image_tokens' => (int) ($usage['input_image_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($usage['reasoning_tokens'] ?? 0),
            'provider_cost_nanodollars' => $providerCost,
            'charge_nanodollars' => $charge,
            'image_url' => $remote['image_url'] ?? null,
            'image_url_expires_at' => $this->time($remote['image_url_expires_at'] ?? null),
            'completed_at' => $this->time($remote['completed_at'] ?? null) ?? now(),
            'error_code' => null,
            'error_message' => null,
        ]);

        // The unique generation_id on credit_transactions is a second guard: the
        // database refuses a second charge row even if this code ever ran twice.
        CreditTransaction::query()->create([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => CreditTransactionType::Charge,
            'amount_nanodollars' => -$charge,
            'description' => 'Image: '.Str::limit($generation->prompt, 60),
        ]);
    }

    private function recordFailure(Generation $generation, array $remote): void
    {
        $code = (string) ($remote['error']['code'] ?? 'generation_failed');

        $generation->fill([
            'error_code' => $code,
            'error_message' => match ($code) {
                'generation_failed' => 'Pixelforge could not create an image from this prompt.',
                'internal_error' => 'Something went wrong at Pixelforge. Trying again usually works.',
                default => (string) ($remote['error']['message'] ?? 'The image could not be generated.'),
            },
            'provider_cost_nanodollars' => 0,
            'charge_nanodollars' => 0,
            'completed_at' => $this->time($remote['completed_at'] ?? null) ?? now(),
        ]);
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
