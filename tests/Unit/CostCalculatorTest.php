<?php

namespace Tests\Unit;

use App\Billing\CostCalculator;
use App\Pixelforge\Pricing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\FakesPixelforge;

class CostCalculatorTest extends TestCase
{
    use FakesPixelforge;

    private CostCalculator $costs;

    private Pricing $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->costs = new CostCalculator(markupPercent: 25);
        $this->pricing = Pricing::fromArray($this->pricingPayload());
    }

    #[Test]
    public function the_most_a_generation_can_cost_matches_the_worked_example_in_the_docs(): void
    {
        // High quality 1536x1024, 400-character prompt, 2 reference images.
        $estimate = $this->costs->estimate($this->pricing, str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame([
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'input_text_tokens' => 115,
            'reasoning_tokens' => 8000,
        ], $estimate->maxTokens);
        $this->assertSame(373_875_000, $estimate->maxProviderCost); // $0.373875
        $this->assertSame(467_343_750, $estimate->maxCharge); // + 25%
    }

    #[Test]
    public function the_cheapest_case_uses_the_fewest_text_and_reasoning_tokens(): void
    {
        $estimate = $this->costs->estimate($this->pricing, str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame(85, $estimate->minTokens['input_text_tokens']); // 100 - 15%
        $this->assertSame(1000, $estimate->minTokens['reasoning_tokens']);
        // 85 × $5 + 1,530 × $10 + 6,450 × $40 + 1,000 × $12.50, per 1M tokens
        $this->assertSame(286_225_000, $estimate->minProviderCost);
    }

    #[Test]
    public function text_tokens_count_unicode_characters_not_bytes(): void
    {
        // 5 characters but 10 bytes: ceil(5 / 4) = 2 estimated tokens.
        $estimate = $this->costs->estimate($this->pricing, 'ééééé', '1024x1024', 'low', 0);

        $this->assertSame(1, $estimate->minTokens['input_text_tokens']); // floor(2 × 0.85)
        $this->assertSame(3, $estimate->maxTokens['input_text_tokens']); // ceil(2 × 1.15)
    }

    #[Test]
    public function an_empty_prompt_has_no_text_tokens(): void
    {
        $estimate = $this->costs->estimate($this->pricing, '', '1024x1024', 'low', 0);

        $this->assertSame(0, $estimate->maxTokens['input_text_tokens']);
        $this->assertSame(280 * 40_000, $estimate->minProviderCost); // output only, no reasoning at low minimum
    }

    #[Test]
    public function provider_cost_of_the_succeeded_generation_in_the_docs(): void
    {
        $usage = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411];

        // 0.000060 + 0.007650 + 0.258000 + 0.039800 = $0.305510
        $this->assertSame(305_510_000, $this->costs->providerCost($usage, self::RATES));
    }

    #[Test]
    public function provider_cost_matches_the_usage_report_example_in_the_docs(): void
    {
        $day = ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950];

        $this->assertSame(169_080_000, $this->costs->providerCost($day, self::RATES)); // "0.169080000"
    }

    #[Test]
    public function the_markup_is_exact_and_any_fraction_of_a_nanodollar_rounds_up(): void
    {
        $this->assertSame(381_887_500, $this->costs->userCharge(305_510_000));
        $this->assertSame(10, $this->costs->userCharge(8));
        $this->assertSame(13, $this->costs->userCharge(10)); // 12.5 -> 13
        $this->assertSame(0, $this->costs->userCharge(0));
    }

    #[Test]
    public function it_refuses_usage_it_has_no_rate_for(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->costs->providerCost(['output_tokens' => 10], ['input_text_tokens' => '5.00']);
    }
}
