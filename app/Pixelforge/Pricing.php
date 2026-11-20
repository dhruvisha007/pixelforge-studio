<?php

namespace App\Pixelforge;

use InvalidArgumentException;

/**
 * The GET /v1/pricing response: per-1M-token rates plus the constants needed to
 * estimate how many tokens a generation will use.
 */
final class Pricing
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * @param  array<string, string>  $rates  USD per 1M tokens, as exact decimal strings
     * @param  array<string, mixed>  $tokens
     */
    public function __construct(
        public readonly array $rates,
        public readonly array $tokens,
    ) {
        foreach (self::TOKEN_TYPES as $type) {
            if (! isset($rates[$type]) || ! is_string($rates[$type])) {
                throw new InvalidArgumentException("Pixelforge pricing has no rate for {$type}.");
            }
        }

        foreach (['characters_per_text_token', 'text_token_variance', 'per_reference_image', 'output', 'reasoning'] as $key) {
            if (! isset($tokens[$key])) {
                throw new InvalidArgumentException("Pixelforge pricing has no tokens.{$key}.");
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['rates'] ?? [], $data['tokens'] ?? []);
    }

    public function charactersPerTextToken(): int
    {
        return (int) $this->tokens['characters_per_text_token'];
    }

    /** As a decimal string ("0.15") so estimates never touch floating point maths. */
    public function textTokenVariance(): string
    {
        return (string) $this->tokens['text_token_variance'];
    }

    public function tokensPerReferenceImage(): int
    {
        return (int) $this->tokens['per_reference_image'];
    }

    public function outputTokens(string $quality, string $size): int
    {
        return (int) ($this->tokens['output'][$quality][$size]
            ?? throw new InvalidArgumentException("Pixelforge pricing has no output tokens for {$quality} {$size}."));
    }

    /** @return array{min: int, max: int} */
    public function reasoningTokens(string $quality): array
    {
        $range = $this->tokens['reasoning'][$quality]
            ?? throw new InvalidArgumentException("Pixelforge pricing has no reasoning range for {$quality}.");

        return ['min' => (int) $range['min'], 'max' => (int) $range['max']];
    }
}
