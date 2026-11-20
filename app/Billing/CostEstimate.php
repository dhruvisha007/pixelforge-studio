<?php

namespace App\Billing;

use App\Support\Money;
use Brick\Math\RoundingMode;

/** The range a generation's price will fall in. Amounts are nanodollars. */
final class CostEstimate
{
    /**
     * @param  array<string, int>  $minTokens
     * @param  array<string, int>  $maxTokens
     */
    public function __construct(
        public readonly array $minTokens,
        public readonly array $maxTokens,
        public readonly int $minProviderCost,
        public readonly int $maxProviderCost,
        public readonly int $minCharge,
        public readonly int $maxCharge,
    ) {}

    /** What the generate form shows. Rounded outwards, so the real price is always inside the range shown. */
    public function toArray(): array
    {
        return [
            'min' => Money::format($this->minCharge, rounding: RoundingMode::FLOOR),
            'max' => Money::format($this->maxCharge, rounding: RoundingMode::CEILING),
            'min_nanodollars' => $this->minCharge,
            'max_nanodollars' => $this->maxCharge,
            'tokens' => ['min' => $this->minTokens, 'max' => $this->maxTokens],
        ];
    }
}
