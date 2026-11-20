<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Money;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the history cards get. Money is pre-formatted here so the browser never
 * does maths with it; the exact amount is included for the hover tooltip.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $succeeded = $this->status === GenerationStatus::Succeeded;

        return [
            // The public uuid, never the auto-increment id (which would reveal how many images exist).
            'id' => $this->uuid,
            'status' => $this->status->value,
            'is_final' => $this->status->isFinal(),
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            // Rounded outwards, so the real price is always inside the range shown.
            'estimate' => [
                'min' => Money::format($this->estimated_min_charge_nanodollars, rounding: RoundingMode::FLOOR),
                'max' => Money::format($this->estimated_max_charge_nanodollars, rounding: RoundingMode::CEILING),
            ],
            'charge' => $this->charge_nanodollars === null ? null : [
                'display' => Money::format($this->charge_nanodollars),
                'exact' => Money::toUsd($this->charge_nanodollars),
            ],
            'tokens' => $succeeded ? [
                'input_text' => $this->input_text_tokens,
                'input_image' => $this->input_image_tokens,
                'output' => $this->output_tokens,
                'reasoning' => $this->reasoning_tokens,
            ] : null,
            // Always our own route: it serves the stored copy and checks who is asking.
            'image' => $succeeded ? [
                'url' => $this->hasViewableImage() ? route('generations.image', $this->resource) : null,
                'expired' => ! $this->hasViewableImage(),
            ] : null,
            'error' => $this->error_code === null ? null : [
                'code' => $this->error_code,
                'message' => $this->error_message,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
