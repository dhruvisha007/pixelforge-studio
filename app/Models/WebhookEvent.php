<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Store an event unless we already have it. Returns false for a duplicate.
     * The unique event_id makes this safe even when two deliveries race.
     */
    public static function recordOnce(array $event): bool
    {
        $now = now();

        return self::query()->insertOrIgnore([
            'event_id' => $event['id'],
            'type' => $event['type'],
            'pixelforge_generation_id' => $event['data']['object']['id'] ?? null,
            'payload' => json_encode($event, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }
}
