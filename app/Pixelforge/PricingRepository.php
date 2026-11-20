<?php

namespace App\Pixelforge;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Pixelforge's current rates. The docs say to read them from GET /v1/pricing
 * rather than hardcode them, so we cache the response for a few minutes and
 * keep the last good copy in case Pixelforge cannot be reached.
 *
 * These rates are only used for estimates. Actual costs always use the rates
 * on the generation itself, which Pixelforge fixes when it is created.
 */
class PricingRepository
{
    private const FRESH_KEY = 'pixelforge.pricing';

    private const LAST_KNOWN_KEY = 'pixelforge.pricing.last_known';

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly int $cacheSeconds,
    ) {}

    public function current(): Pricing
    {
        $data = Cache::get(self::FRESH_KEY);

        if ($data === null) {
            $data = $this->fetch();
        }

        return Pricing::fromArray($data);
    }

    private function fetch(): array
    {
        try {
            $data = $this->client->pricing();
            Pricing::fromArray($data); // Throws if the response is not what we expect.
            Cache::forever(self::LAST_KNOWN_KEY, $data);
            Cache::put(self::FRESH_KEY, $data, $this->cacheSeconds);

            return $data;
        } catch (Throwable $e) {
            report($e);

            $lastKnown = Cache::get(self::LAST_KNOWN_KEY) ?? throw new PricingUnavailableException(previous: $e);

            // Try Pixelforge again in a minute rather than on every request.
            Cache::put(self::FRESH_KEY, $lastKnown, 60);

            return $lastKnown;
        }
    }
}
