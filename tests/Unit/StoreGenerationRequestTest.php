<?php

use App\Http\Requests\StoreGenerationRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

function validateGenerationPayload(array $payload): Illuminate\Validation\Validator
{
    $request = new StoreGenerationRequest;

    $request->merge($payload);

    return Validator::make(
        $request->all(),
        $request->rules()
    );
}

it('accepts a valid generation request', function () {
    $validator = validateGenerationPayload([
        'prompt' => 'A lighthouse beside the ocean at sunset',
        'size' => '1024x1024',
        'quality' => 'medium',
    ]);

    expect($validator->passes())->toBeTrue();
});

it('rejects a missing prompt', function () {
    $validator = validateGenerationPayload([]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('prompt'))->toBeTrue();
});

it('rejects a prompt longer than 4000 characters', function () {
    $validator = validateGenerationPayload([
        'prompt' => str_repeat('a', 4001),
    ]);

    expect($validator->errors()->has('prompt'))->toBeTrue();
});

it('rejects an unsupported image size', function () {
    $validator = validateGenerationPayload([
        'prompt' => 'A lighthouse',
        'size' => '2048x2048',
    ]);

    expect($validator->errors()->has('size'))->toBeTrue();
});

it('rejects an unsupported quality', function () {
    $validator = validateGenerationPayload([
        'prompt' => 'A lighthouse',
        'quality' => 'ultra',
    ]);

    expect($validator->errors()->has('quality'))->toBeTrue();
});

it('rejects more than four reference images', function () {
    $validator = validateGenerationPayload([
        'prompt' => 'A lighthouse',
        'reference_images' => [
            'https://example.com/1.png',
            'https://example.com/2.png',
            'https://example.com/3.png',
            'https://example.com/4.png',
            'https://example.com/5.png',
        ],
    ]);

    expect($validator->errors()->has('reference_images'))->toBeTrue();
});

it('rejects a reference image using an unsupported URL scheme', function () {
    $validator = validateGenerationPayload([
        'prompt' => 'A lighthouse',
        'reference_images' => [
            'ftp://example.com/image.png',
        ],
    ]);

    expect($validator->errors()->has('reference_images.0'))->toBeTrue();
});

it('rejects more than twenty metadata entries', function () {
    $metadata = [];

    for ($i = 1; $i <= 21; $i++) {
        $metadata["key_{$i}"] = 'value';
    }

    $validator = validateGenerationPayload([
        'prompt' => 'A lighthouse',
        'metadata' => $metadata,
    ]);

    expect($validator->errors()->has('metadata'))->toBeTrue();
});

it('rejects metadata values longer than 500 characters', function () {
    $validator = validateGenerationPayload([
        'prompt' => 'A lighthouse',
        'metadata' => [
            'source' => str_repeat('a', 501),
        ],
    ]);

    expect($validator->errors()->has('metadata.source'))->toBeTrue();
});

it('accepts valid reference images and metadata', function () {
    $validator = validateGenerationPayload([
        'prompt' => 'A lighthouse',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [
            'https://example.com/reference.png',
        ],
        'metadata' => [
            'source' => 'promptedit',
        ],
    ]);

    expect($validator->passes())->toBeTrue();
});
