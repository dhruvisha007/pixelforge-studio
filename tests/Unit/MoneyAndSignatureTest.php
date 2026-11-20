<?php

namespace Tests\Unit;

use App\Pixelforge\WebhookSignature;
use App\Support\Money;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoneyAndSignatureTest extends TestCase
{
    #[Test]
    public function money_converts_exactly_between_dollars_and_nanodollars(): void
    {
        $this->assertSame(46_125_000, Money::fromUsd('0.046125000'));
        $this->assertSame(5_000_000_000, Money::fromUsd('5.00'));
        $this->assertSame('0.046125000', Money::toUsd(46_125_000));
        $this->assertSame('-0.000000013', Money::toUsd(-13));
    }

    #[Test]
    public function money_formats_for_display(): void
    {
        $this->assertSame('$0.0584', Money::format(58_437_500));
        $this->assertSame('$0.0585', Money::format(58_437_500, rounding: RoundingMode::CEILING));
        $this->assertSame('-$0.0584', Money::format(-58_437_500));
        $this->assertSame('$1.00', Money::format(1_000_000_000, 2));
    }

    #[Test]
    public function a_correctly_signed_recent_webhook_is_valid(): void
    {
        $verifier = new WebhookSignature('whsec_test', 300);
        $payload = '{"id":"evt_1"}';
        $signature = WebhookSignature::sign($payload, 'whsec_test', 1_762_265_009);

        $this->assertTrue($verifier->isValid($payload, "t=1762265009,v1={$signature}", now: 1_762_265_100));
    }

    #[Test]
    public function tampered_wrongly_signed_or_old_webhooks_are_rejected(): void
    {
        $verifier = new WebhookSignature('whsec_test', 300);
        $payload = '{"id":"evt_1"}';
        $signature = WebhookSignature::sign($payload, 'whsec_test', 1_000_000);

        $this->assertFalse($verifier->isValid('{"id":"evt_2"}', "t=1000000,v1={$signature}", now: 1_000_000));
        $this->assertFalse($verifier->isValid($payload, 't=1000000,v1='.WebhookSignature::sign($payload, 'whsec_other', 1_000_000), now: 1_000_000));
        $this->assertFalse($verifier->isValid($payload, "t=1000000,v1={$signature}", now: 1_000_301), 'replayed after 5 minutes');
        $this->assertFalse($verifier->isValid($payload, "v1={$signature}", now: 1_000_000), 'no timestamp');
        $this->assertFalse($verifier->isValid($payload, null, now: 1_000_000));
        $this->assertFalse((new WebhookSignature(null))->isValid($payload, "t=1000000,v1={$signature}", now: 1_000_000), 'no secret configured');
    }

    #[Test]
    public function any_matching_v1_signature_is_accepted(): void
    {
        $verifier = new WebhookSignature('whsec_test', 300);
        $payload = '{}';
        $signature = WebhookSignature::sign($payload, 'whsec_test', 1_000_000);

        $this->assertTrue($verifier->isValid($payload, "t=1000000,v1=deadbeef,v1={$signature}", now: 1_000_000));
    }
}
