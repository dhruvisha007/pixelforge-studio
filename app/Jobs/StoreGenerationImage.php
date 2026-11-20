<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Pixelforge's signed image URLs stop working 24 hours after the generation
 * completes, so we keep our own copy.
 */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    public function __construct(public readonly int $generationId) {}

    public function handle(): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->image_path !== null || $generation->image_url === null) {
            return;
        }

        if ($generation->image_url_expires_at?->isPast()) {
            return; // Too late: the URL no longer works.
        }

        $response = Http::timeout(30)->get($generation->image_url)->throw();

        if (! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            throw new RuntimeException("Pixelforge returned a non-image for generation {$generation->uuid}.");
        }

        $path = "generations/{$generation->uuid}.png";
        Storage::disk(config('studio.image_disk'))->put($path, $response->body());

        $generation->forceFill(['image_path' => $path])->save();
    }
}
