<?php

namespace App\Pixelforge;

/**
 * Checks the Pixelforge-Signature header: "t=<unix timestamp>,v1=<hex HMAC-SHA256
 * of '<timestamp>.<raw body>' keyed with the webhook signing secret>".
 */
final class WebhookSignature
{
    public function __construct(
        private readonly ?string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function isValid(string $payload, ?string $header, ?int $now = null): bool
    {
        if (blank($this->secret) || blank($header)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        // An old timestamp means a replayed request: every real attempt is signed fresh.
        if ($timestamp === null || abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = self::sign($payload, $this->secret, $timestamp);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
