<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The generate form. Mirrors Pixelforge's own limits so it never rejects what we send. */
class GenerationRequest extends FormRequest
{
    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    public const MAX_PROMPT_LENGTH = 4000;

    public const MAX_REFERENCE_IMAGES = 4;

    public function rules(): array
    {
        // The live estimate runs while the user is still typing: an empty prompt or a
        // half-typed URL is fine there, only the number of reference images matters.
        if ($this->routeIs('generations.estimate')) {
            return [
                'prompt' => ['nullable', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
                'size' => ['required', Rule::in(self::SIZES)],
                'quality' => ['required', Rule::in(self::QUALITIES)],
                'reference_images' => ['present', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
                'reference_images.*' => ['string'],
            ];
        }

        return [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::in(self::SIZES)],
            'quality' => ['required', Rule::in(self::QUALITIES)],
            'reference_images' => ['present', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add up to '.self::MAX_REFERENCE_IMAGES.' reference images.',
            'reference_images.*.url' => 'Reference images must be http or https URLs.',
            'reference_images.*.required' => 'Fill in or remove the empty reference image.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Laravel's TrimStrings middleware already trims; drop blank reference rows.
        $this->merge([
            'reference_images' => array_values(array_filter(
                (array) $this->input('reference_images', []),
                fn ($url) => filled($url),
            )),
        ]);
    }

    /** @return list<string> */
    public function referenceImages(): array
    {
        return array_values($this->validated('reference_images'));
    }

    public static function options(): array
    {
        return [
            'sizes' => self::SIZES,
            'qualities' => self::QUALITIES,
            'max_prompt_length' => self::MAX_PROMPT_LENGTH,
            'max_reference_images' => self::MAX_REFERENCE_IMAGES,
        ];
    }
}
