<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mirrors Pixelforge's own validation so mistakes show on the form, not as a failed generation. */
class StoreGenerationRequest extends FormRequest
{
    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    protected function prepareForValidation(): void
    {
        // Ignore empty reference-image rows the form may send.
        $this->merge([
            'reference_images' => array_values(array_filter(
                array_map('trim', (array) $this->input('reference_images', [])),
                fn ($url) => $url !== '',
            )),
        ]);
    }

    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(self::SIZES)],
            'quality' => ['required', Rule::in(self::QUALITIES)],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['url:http,https', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add at most 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
        ];
    }
}
