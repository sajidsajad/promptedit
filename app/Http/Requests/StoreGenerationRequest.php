<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'size' => $this->input('size', '1024x1024'),
            'quality' => $this->input('quality', 'medium'),
        ]);
    }

    public function rules(): array
    {
        return [
            'prompt' => [
                'required',
                'string',
                'max:4000',
            ],

            'size' => [
                'required',
                'string',
                Rule::in([
                    '1024x1024',
                    '1536x1024',
                    '1024x1536',
                ]),
            ],

            'quality' => [
                'required',
                'string',
                Rule::in([
                    'low',
                    'medium',
                    'high',
                ]),
            ],

            'reference_images' => [
                'sometimes',
                'array',
                'max:4',
            ],

            'reference_images.*' => [
                'required',
                'string',
                'url:http,https',
            ],

            'metadata' => [
                'sometimes',
                'array',
                'max:20',
            ],

            'metadata.*' => [
                'string',
                'max:500',
            ],
        ];
    }
}
