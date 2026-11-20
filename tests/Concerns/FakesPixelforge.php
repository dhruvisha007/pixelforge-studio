<?php

namespace Tests\Concerns;

use App\Models\Generation;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Builds Pixelforge responses and webhooks shaped exactly like the API docs. */
trait FakesPixelforge
{
    protected const BASE = 'https://pixelforge.promptedit.dev/v1';

    protected const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /** The GET /v1/pricing response from the docs. */
    protected function pricingPayload(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => self::RATES,
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    /** @param  array<string, mixed>  $more  extra URL patterns => responses */
    protected function fakePixelforge(array $more = []): Factory
    {
        return Http::fake($more + [
            self::BASE.'/pricing' => Http::response($this->pricingPayload()),
        ]);
    }

    /** A Pixelforge generation object for one of our generations. */
    protected function remoteGeneration(Generation $generation, string $status, array $overrides = []): array
    {
        $object = [
            'id' => $generation->pixelforge_id ?? 'gen_'.Str::upper(Str::random(26)),
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images,
            'metadata' => ['generation_uuid' => $generation->uuid],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => self::RATES,
            'error' => null,
            'created_at' => now()->subSeconds(20)->toIso8601ZuluString(),
            'started_at' => null,
            'completed_at' => null,
        ];

        if (in_array($status, ['processing', 'succeeded', 'failed'], true)) {
            $object['started_at'] = now()->subSeconds(15)->toIso8601ZuluString();
        }

        if ($status === 'succeeded') {
            $object['usage'] = ['input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1500, 'total_tokens' => 2612];
            $object['image_url'] = 'https://pixelforge.promptedit.dev/images/'.$object['id'].'.png?expires=1&signature=abc';
            $object['image_url_expires_at'] = now()->addDay()->toIso8601ZuluString();
            $object['completed_at'] = now()->toIso8601ZuluString();
        }

        if ($status === 'failed') {
            $object['error'] = ['code' => 'generation_failed', 'message' => 'The model could not produce an image. You were not charged.'];
            $object['completed_at'] = now()->toIso8601ZuluString();
        }

        return array_replace($object, $overrides);
    }

    protected function webhookEvent(array $object, ?string $id = null): array
    {
        return [
            'id' => $id ?? 'evt_'.Str::upper(Str::random(26)),
            'object' => 'event',
            'type' => 'generation.'.$object['status'],
            'created_at' => now()->toIso8601ZuluString(),
            'data' => ['object' => $object],
        ];
    }

    protected function sendWebhook(array $event, ?int $timestamp = null, string $secret = 'whsec_test'): TestResponse
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature = WebhookSignature::sign($payload, $secret, $timestamp);

        return $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
        ], content: $payload);
    }

    /** A fake PNG for the image download. */
    protected function fakeImageResponse(): array
    {
        return ['pixelforge.promptedit.dev/images/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png'])];
    }
}
