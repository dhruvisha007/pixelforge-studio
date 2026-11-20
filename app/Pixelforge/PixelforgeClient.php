<?php

namespace App\Pixelforge;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A thin wrapper around the Pixelforge REST API. Every failure becomes a
 * PixelforgeException so callers can decide whether to retry or give up.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /** GET /v1/pricing (no authentication). */
    public function pricing(): array
    {
        return $this->send(fn () => $this->request(authenticated: false)->get('pricing'));
    }

    /**
     * POST /v1/generations. Retrying with the same idempotency key and parameters
     * returns the generation that was already created instead of making a new one.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function createGeneration(array $parameters, string $idempotencyKey): array
    {
        return $this->send(fn () => $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $parameters));
    }

    /** GET /v1/generations/{id} */
    public function generation(string $id): array
    {
        return $this->send(fn () => $this->request()->get('generations/'.rawurlencode($id)));
    }

    /** GET /v1/usage: what Pixelforge billed this API key per UTC day (dates inclusive). */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->request()->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    private function request(bool $authenticated = true): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout($this->timeoutSeconds);

        if (! $authenticated) {
            return $request;
        }

        if (blank($this->apiKey)) {
            throw PixelforgeException::missingApiKey();
        }

        return $request->withToken($this->apiKey);
    }

    /** @param  Closure(): Response  $request */
    private function send(Closure $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw PixelforgeException::connectionFailed($e);
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json() ?? [];
    }
}
