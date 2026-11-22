<?php

use App\Services\Pixelforge\GenerationCostCalculator;

it('calculates provider cost and customer price from token usage', function () {
    $calculator = new GenerationCostCalculator;

    $result = $calculator->calculate(
        usage: [
            'input_text_tokens' => 13,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 732,
        ],
        rates: [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    );

    expect($result['provider_cost'])->toBe('0.053215000')
        ->and($result['customer_price'])->toBe('0.066518750');
});

it('calculates zero cost when usage is zero', function () {
    $calculator = new GenerationCostCalculator;

    $result = $calculator->calculate(
        usage: [
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ],
        rates: [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    );

    expect($result['provider_cost'])->toBe('0.000000000')
        ->and($result['customer_price'])->toBe('0.000000000');
});

it('rejects missing token usage fields', function () {
    $calculator = new GenerationCostCalculator;

    $calculator->calculate(
        usage: [
            'input_text_tokens' => 13,
            'output_tokens' => 1100,
            'reasoning_tokens' => 732,
        ],
        rates: [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    );
})->throws(InvalidArgumentException::class);

it('rejects negative token usage', function () {
    $calculator = new GenerationCostCalculator;

    $calculator->calculate(
        usage: [
            'input_text_tokens' => -13,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 732,
        ],
        rates: [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    );
})->throws(InvalidArgumentException::class);

it('rejects malformed pricing rates', function () {
    $calculator = new GenerationCostCalculator;

    $calculator->calculate(
        usage: [
            'input_text_tokens' => 13,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 732,
        ],
        rates: [
            'input_text_tokens' => 'five dollars',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    );
})->throws(InvalidArgumentException::class);

it('rejects negative pricing rates', function () {
    $calculator = new GenerationCostCalculator;

    $calculator->calculate(
        usage: [
            'input_text_tokens' => 13,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 732,
        ],
        rates: [
            'input_text_tokens' => '-5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    );
})->throws(InvalidArgumentException::class);

it('rejects missing pricing rate fields', function () {
    $calculator = new GenerationCostCalculator;

    $calculator->calculate(
        usage: [
            'input_text_tokens' => 13,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 732,
        ],
        rates: [
            'input_text_tokens' => '5.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    );
})->throws(InvalidArgumentException::class);

it('rejects fractional token counts', function () {
    $calculator = new GenerationCostCalculator;

    $calculator->calculate(
        usage: [
            'input_text_tokens' => 13.5,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 732,
        ],
        rates: [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    );
})->throws(InvalidArgumentException::class);

it('accepts pricing rates with nine decimal places', function () {
    $calculator = new GenerationCostCalculator;

    $result = $calculator->calculate(
        usage: [
            'input_text_tokens' => 1,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ],
        rates: [
            'input_text_tokens' => '5.123456789',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    );

    expect($result['provider_cost'])->toBe('0.000005123')
        ->and($result['customer_price'])->toBe('0.000006403');
});

it('rejects pricing rates with more than nine decimal places', function () {
    $calculator = new GenerationCostCalculator;

    $calculator->calculate(
        usage: [
            'input_text_tokens' => 1,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ],
        rates: [
            'input_text_tokens' => '5.1234567891',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    );
})->throws(InvalidArgumentException::class);
