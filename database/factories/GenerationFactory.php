<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_min_charge_nanodollars' => 58_000_000,
            'estimated_max_charge_nanodollars' => 87_000_000,
        ];
    }

    /** Accepted by Pixelforge and waiting in its queue. */
    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Queued,
            'pixelforge_id' => 'gen_'.Str::upper(Str::random(26)),
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'accepted_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn () => [
            'status' => GenerationStatus::Processing,
            'started_at' => now(),
        ]);
    }
}
