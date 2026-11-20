<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case Welcome = 'welcome';
    case Purchase = 'purchase';
    case Charge = 'charge';

    public function label(): string
    {
        return match ($this) {
            self::Welcome => 'Welcome credit',
            self::Purchase => 'Credits bought',
            self::Charge => 'Image generation',
        };
    }
}
