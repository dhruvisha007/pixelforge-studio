<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property GenerationStatus $status
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'estimated_min_charge_nanodollars' => 'integer',
            'estimated_max_charge_nanodollars' => 'integer',
            'provider_cost_nanodollars' => 'integer',
            'charge_nanodollars' => 'integer',
            'image_url_expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    /** Keep the integer primary key; generate a UUID for the public "uuid" column only. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function charge(): HasOne
    {
        return $this->hasOne(CreditTransaction::class);
    }

    /** Waiting for us to send it, or waiting at Pixelforge. */
    public function scopeInProgress(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::inProgress());
    }

    /**
     * Find the generation a Pixelforge generation object belongs to. We match on the
     * uuid we put in its metadata first, because a webhook can arrive before our
     * POST /v1/generations call has returned and told us Pixelforge's id.
     */
    public static function findForPixelforgeObject(array $object): ?self
    {
        $uuid = $object['metadata']['generation_uuid'] ?? null;

        if (is_string($uuid) && Str::isUuid($uuid)) {
            return self::query()->where('uuid', $uuid)->first();
        }

        return isset($object['id']) ? self::query()->where('pixelforge_id', $object['id'])->first() : null;
    }

    /**
     * Pixelforge has accepted this generation. Anything we mark failed ourselves
     * (before Pixelforge accepted it) has no Pixelforge id.
     */
    public function isKnownToPixelforge(): bool
    {
        return $this->pixelforge_id !== null;
    }

    /** The POST /v1/generations body. Must be identical on every retry, or the idempotency key is rejected. */
    public function pixelforgePayload(): array
    {
        $payload = [
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'metadata' => ['generation_uuid' => $this->uuid],
        ];

        if ($this->reference_images !== []) {
            $payload['reference_images'] = array_values($this->reference_images);
        }

        return $payload;
    }

    public function hasViewableImage(): bool
    {
        if ($this->status !== GenerationStatus::Succeeded) {
            return false;
        }

        return $this->image_path !== null
            || ($this->image_url !== null && $this->image_url_expires_at?->isFuture());
    }
}
