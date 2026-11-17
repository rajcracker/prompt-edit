<?php

use App\Pixelforge\CostCalculator;
use App\Support\Money;

$rates = [
    'input_text_tokens' => '5.00',
    'input_image_tokens' => '10.00',
    'output_tokens' => '40.00',
    'reasoning_tokens' => '12.50',
];

$pricing = [
    'rates' => $rates,
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

it('converts dollars to nanodollars and back exactly', function () {
    expect(Money::toNanos('12.50'))->toBe(12_500_000_000)
        ->and(Money::toNanos('0.046125000'))->toBe(46_125_000)
        ->and(Money::toDecimal(46_125_000))->toBe('0.046125000')
        ->and(Money::toDecimal(-1))->toBe('-0.000000001');
});

it('matches the worked maximum-cost example from the docs', function () use ($rates) {
    $calculator = new CostCalculator(markupPercent: 25);
    $tokens = ['input_text_tokens' => 115, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 8000];

    expect(Money::toDecimal($calculator->providerCost($tokens, $rates)))->toBe('0.373875000')
        // + 25%: 0.373875 × 1.25 = 0.46734375
        ->and(Money::toDecimal($calculator->userCost($tokens, $rates)))->toBe('0.467343750');
});

it('prices the succeeded generation from the docs', function () use ($rates) {
    $calculator = new CostCalculator(markupPercent: 25);
    $usage = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184];

    // 12×5 + 765×10 + 6450×40 + 3184×12.5 = 305,510 per 1M tokens
    expect(Money::toDecimal($calculator->providerCost($usage, $rates)))->toBe('0.305510000')
        ->and(Money::toDecimal($calculator->userCost($usage, $rates)))->toBe('0.381887500');
});

it('rounds the marked-up price up to the next nanodollar', function () use ($rates) {
    $calculator = new CostCalculator(markupPercent: 25);
    // 1 reasoning token = 12,500 nanodollars → +25% = 15,625 exactly.
    $oneReasoning = ['input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 1];
    expect($calculator->userCost($oneReasoning, $rates))->toBe(15_625);

    // $0.000003 per 1M tokens × 1,000 tokens = 3 nanodollars; +25% = 3.75 → 4.
    $tiny = ['input_text_tokens' => '0.000003', 'input_image_tokens' => '0', 'output_tokens' => '0', 'reasoning_tokens' => '0'];
    $tokens = ['input_text_tokens' => 1000, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0];
    expect($calculator->providerCost($tokens, $tiny))->toBe(3)
        ->and($calculator->userCost($tokens, $tiny))->toBe(4);
});

it('estimates the expected and the maximum cost before generating', function () use ($pricing) {
    $calculator = new CostCalculator(markupPercent: 25);
    $estimate = $calculator->estimate(str_repeat('a', 400), '1536x1024', 'high', 2, $pricing);

    expect($estimate['max'])->toBe(['input_text_tokens' => 115, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 8000])
        ->and($estimate['expected']['reasoning_tokens'])->toBe(4500)
        ->and($estimate['max_cost_nanos'])->toBe(467_343_750)
        ->and($estimate['expected_cost_nanos'])->toBeLessThan($estimate['max_cost_nanos']);
});

it('counts unicode characters, not bytes', function () use ($pricing) {
    $estimate = (new CostCalculator(25))->estimate('ééééé', '1024x1024', 'low', 0, $pricing);

    expect($estimate['expected']['input_text_tokens'])->toBe(2); // ceil(5 / 4)
});
