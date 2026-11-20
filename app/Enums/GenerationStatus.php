<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Saved by us, not yet accepted by Pixelforge. */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /** Position in the lifecycle. A generation only ever moves to a later stage. */
    public function stage(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    /** @return list<self> */
    public static function inProgress(): array
    {
        return [self::Pending, self::Queued, self::Processing];
    }
}
