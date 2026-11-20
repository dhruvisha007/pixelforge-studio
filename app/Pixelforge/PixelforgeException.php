<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

final class PixelforgeException extends RuntimeException
{
    /** Errors Pixelforge documents as temporary: wait for Retry-After, then try again. */
    private const TEMPORARY_CODES = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'];

    public function __construct(
        string $message,
        public readonly ?int $status,
        public readonly string $errorCode,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error');
        $retryAfter = $response->header('Retry-After');

        return new self(
            message: is_array($error) ? (string) ($error['message'] ?? 'Unknown Pixelforge error.') : "Pixelforge returned HTTP {$response->status()}.",
            status: $response->status(),
            errorCode: is_array($error) ? (string) ($error['code'] ?? 'unknown') : 'unknown',
            retryAfter: is_numeric($retryAfter) ? max(0, (int) $retryAfter) : null,
        );
    }

    public static function connectionFailed(ConnectionException $e): self
    {
        return new self('Could not reach Pixelforge: '.$e->getMessage(), null, 'connection_failed', previous: $e);
    }

    public static function missingApiKey(): self
    {
        return new self('PIXELFORGE_API_KEY is not set.', null, 'missing_api_key');
    }

    /** Worth retrying later: rate or concurrency limits, overloads, network failures and server errors. */
    public function isTemporary(): bool
    {
        if (in_array($this->errorCode, self::TEMPORARY_CODES, true)) {
            return true;
        }

        return $this->errorCode === 'connection_failed' || ($this->status !== null && $this->status >= 500);
    }

    /**
     * Whether we know for sure Pixelforge did not create a generation. A timeout or a
     * 500 is ambiguous: the request may have gone through before it broke.
     */
    public function definitelyNothingCreated(): bool
    {
        if ($this->errorCode === 'missing_api_key') {
            return true;
        }

        return $this->status !== null && ($this->status < 500 || $this->status === 503);
    }

    /** A message we can show the person who asked for the image. */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Pixelforge\'s content policy rejected this prompt. Try wording it differently.',
            'daily_limit_exceeded' => 'We have reached today\'s image limit. It resets at midnight UTC.',
            'validation_failed' => 'Pixelforge rejected the request: '.$this->getMessage(),
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded', 'connection_failed' => 'Pixelforge is too busy right now. Please try again in a minute.',
            default => 'Image generation is not available right now. Please try again later.',
        };
    }
}
