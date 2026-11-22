<?php

use App\Services\Generations\GenerationReservationCalculator;

function reservationPricingRates(): array
{
    return [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];
}

function reservationTokenPricing(): array
{
    return [
        'characters_per_text_token' => 4,
        'text_token_variance' => 0.15,
        'per_reference_image' => 765,
        'output' => [
            'low' => [
                '1024x1024' => 280,
                '1536x1024' => 420,
                '1024x1536' => 420,
            ],
            'medium' => [
                '1024x1024' => 1100,
                '1536x1024' => 1650,
                '1024x1536' => 1650,
            ],
            'high' => [
                '1024x1024' => 4300,
                '1536x1024' => 6450,
                '1024x1536' => 6450,
            ],
        ],
        'reasoning' => [
            'low' => ['min' => 0, 'max' => 500],
            'medium' => ['min' => 200, 'max' => 2000],
            'high' => ['min' => 1000, 'max' => 8000],
        ],
    ];
}

it('calculates the maximum customer reservation from the Pixelforge example', function () {
    $calculator = app(GenerationReservationCalculator::class);

    $reservation = $calculator->calculate(
        prompt: str_repeat('a', 400),
        size: '1536x1024',
        quality: 'high',
        referenceImageCount: 2,
        rates: reservationPricingRates(),
        tokenPricing: reservationTokenPricing(),
    );

    expect($reservation)->toBe('0.467343750');
});

it('calculates reservations without reference images', function () {
    $calculator = app(GenerationReservationCalculator::class);

    $reservation = $calculator->calculate(
        prompt: 'A mountain landscape',
        size: '1024x1024',
        quality: 'low',
        referenceImageCount: 0,
        rates: reservationPricingRates(),
        tokenPricing: reservationTokenPricing(),
    );

    expect($reservation)->toBe('0.021850000');
});

it('uses the maximum reasoning tokens for the requested quality', function () {
    $calculator = app(GenerationReservationCalculator::class);

    $low = $calculator->calculate(
        prompt: 'Test',
        size: '1024x1024',
        quality: 'low',
        referenceImageCount: 0,
        rates: reservationPricingRates(),
        tokenPricing: reservationTokenPricing(),
    );

    $high = $calculator->calculate(
        prompt: 'Test',
        size: '1024x1024',
        quality: 'high',
        referenceImageCount: 0,
        rates: reservationPricingRates(),
        tokenPricing: reservationTokenPricing(),
    );

    expect(bccomp($high, $low, 9))->toBe(1);
});

it('rejects unsupported generation sizes', function () {
    $calculator = app(GenerationReservationCalculator::class);

    $calculator->calculate(
        prompt: 'Test',
        size: '2048x2048',
        quality: 'low',
        referenceImageCount: 0,
        rates: reservationPricingRates(),
        tokenPricing: reservationTokenPricing(),
    );
})->throws(InvalidArgumentException::class);

it('rejects unsupported generation quality', function () {
    $calculator = app(GenerationReservationCalculator::class);

    $calculator->calculate(
        prompt: 'Test',
        size: '1024x1024',
        quality: 'ultra',
        referenceImageCount: 0,
        rates: reservationPricingRates(),
        tokenPricing: reservationTokenPricing(),
    );
})->throws(InvalidArgumentException::class);

it('rejects invalid reference image counts', function () {
    $calculator = app(GenerationReservationCalculator::class);

    $calculator->calculate(
        prompt: 'Test',
        size: '1024x1024',
        quality: 'low',
        referenceImageCount: -1,
        rates: reservationPricingRates(),
        tokenPricing: reservationTokenPricing(),
    );
})->throws(InvalidArgumentException::class);

it('rejects incomplete token pricing configuration', function () {
    $calculator = app(GenerationReservationCalculator::class);

    $calculator->calculate(
        prompt: 'Test',
        size: '1024x1024',
        quality: 'low',
        referenceImageCount: 0,
        rates: reservationPricingRates(),
        tokenPricing: [],
    );
})->throws(InvalidArgumentException::class);
