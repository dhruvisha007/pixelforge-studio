<?php

namespace App\Billing;

use App\Pixelforge\Pricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * All money maths in one place. Amounts are integer nanodollars (see App\Support\Money).
 */
final class CostCalculator
{
    public function __construct(private readonly int $markupPercent) {}

    /**
     * What Pixelforge charges for these token counts at these rates (USD per 1M tokens).
     *
     * A rate of $5.00 per 1M tokens is $0.000005 = 5,000 nanodollars per token, so
     * each token type costs count × rate × 1,000 nanodollars. With rates of up to three
     * decimal places that is always a whole number, so the result is exact.
     *
     * @param  array<string, int>  $tokens  keyed like Pixelforge's usage object
     * @param  array<string, string>  $rates  keyed like Pixelforge's rates object
     */
    public function providerCost(array $tokens, array $rates): int
    {
        $total = BigDecimal::zero();

        foreach (Pricing::TOKEN_TYPES as $type) {
            $count = (int) ($tokens[$type] ?? 0);

            if ($count < 0) {
                throw new InvalidArgumentException("Negative {$type}.");
            }

            if ($count === 0) {
                continue;
            }

            $rate = $rates[$type] ?? throw new InvalidArgumentException("No rate for {$type}.");

            $total = $total->plus(BigDecimal::of($rate)->multipliedBy($count)->multipliedBy(1000));
        }

        // Only rounds if Pixelforge ever publishes a rate with more than three decimals.
        return $total->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    /**
     * What the user pays: the provider cost plus the markup, rounded up to the next
     * whole nanodollar ($0.000000001) so a fraction of a nanodollar never costs us money.
     */
    public function userCharge(int $providerCost): int
    {
        return BigDecimal::of($providerCost)
            ->multipliedBy(100 + $this->markupPercent)
            ->dividedBy(100, 0, RoundingMode::CEILING)
            ->toInt();
    }

    /**
     * The cheapest and most expensive this generation can turn out to be.
     *
     * Known up front: output tokens (exact, by quality and size) and image tokens
     * (exactly 765 per reference image). Only known afterwards: the real text token
     * count (within ±15% of ceil(characters / 4)) and reasoning tokens (within a
     * range set by quality).
     */
    public function estimate(Pricing $pricing, string $prompt, string $size, string $quality, int $referenceImages): CostEstimate
    {
        $textEstimate = intdiv(mb_strlen($prompt, 'UTF-8') + $pricing->charactersPerTextToken() - 1, $pricing->charactersPerTextToken());
        $variance = BigDecimal::of($pricing->textTokenVariance());
        $reasoning = $pricing->reasoningTokens($quality);

        $fixed = [
            'input_image_tokens' => $referenceImages * $pricing->tokensPerReferenceImage(),
            'output_tokens' => $pricing->outputTokens($quality, $size),
        ];

        $minTokens = $fixed + [
            'input_text_tokens' => BigDecimal::of($textEstimate)->multipliedBy(BigDecimal::one()->minus($variance))->toScale(0, RoundingMode::FLOOR)->toInt(),
            'reasoning_tokens' => $reasoning['min'],
        ];

        $maxTokens = $fixed + [
            'input_text_tokens' => BigDecimal::of($textEstimate)->multipliedBy(BigDecimal::one()->plus($variance))->toScale(0, RoundingMode::CEILING)->toInt(),
            'reasoning_tokens' => $reasoning['max'],
        ];

        $minProviderCost = $this->providerCost($minTokens, $pricing->rates);
        $maxProviderCost = $this->providerCost($maxTokens, $pricing->rates);

        return new CostEstimate(
            minTokens: $minTokens,
            maxTokens: $maxTokens,
            minProviderCost: $minProviderCost,
            maxProviderCost: $maxProviderCost,
            minCharge: $this->userCharge($minProviderCost),
            maxCharge: $this->userCharge($maxProviderCost),
        );
    }
}
