<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Money is stored as whole nanodollars (1 USD = 1,000,000,000) in integer columns.
 *
 * Pixelforge reports money with exactly nine decimal places, so nanodollars hold
 * every provider amount exactly, and integers never drift the way floats do.
 */
final class Money
{
    public const SCALE = 9;

    /** "0.046125000" (or "5.00") => 46125000 */
    public static function fromUsd(string $usd): int
    {
        return BigDecimal::of($usd)
            ->toScale(self::SCALE, RoundingMode::UNNECESSARY)
            ->getUnscaledValue()
            ->toInt();
    }

    /** 46125000 => "0.046125000" */
    public static function toUsd(int $nanodollars): string
    {
        return (string) BigDecimal::ofUnscaledValue($nanodollars, self::SCALE);
    }

    /**
     * For display only: 58437500 => "$0.0584". Never use the result in calculations.
     */
    public static function format(int $nanodollars, int $decimals = 4, RoundingMode $rounding = RoundingMode::HALF_UP): string
    {
        $value = BigDecimal::ofUnscaledValue($nanodollars, self::SCALE)->toScale($decimals, $rounding);

        return ($value->isNegative() ? '-' : '').'$'.$value->abs();
    }
}
