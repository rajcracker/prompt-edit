<?php

namespace App\Pixelforge;

use App\Support\Money;

/**
 * Turns token counts and Pixelforge's per-1M-token rates into exact nanodollar amounts.
 *
 * The user pays Pixelforge's cost plus the markup. The markup is applied to the
 * exact (unrounded) provider cost and the result is rounded *up* to the next
 * nanodollar, i.e. at most $0.000000001 in our favour per generation.
 */
class CostCalculator
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    private const TOKENS_PER_RATE_UNIT = 1_000_000;

    public function __construct(private readonly int $markupPercent) {}

    /**
     * Σ tokens × (rate in nanodollars per 1M tokens). Divide by 1M to get nanodollars.
     *
     * @param  array<string, int>  $tokens
     * @param  array<string, string>  $rates
     */
    private function scaledProviderCost(array $tokens, array $rates): int
    {
        $sum = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $sum += (int) ($tokens[$type] ?? 0) * Money::toNanos((string) ($rates[$type] ?? '0'));
        }

        return $sum;
    }

    /** What Pixelforge charges us, in nanodollars. */
    public function providerCost(array $tokens, array $rates): int
    {
        $scaled = $this->scaledProviderCost($tokens, $rates);

        // Pixelforge rates are quoted so this divides exactly; round half up just in case.
        return intdiv($scaled + intdiv(self::TOKENS_PER_RATE_UNIT, 2), self::TOKENS_PER_RATE_UNIT);
    }

    /** What we charge the user (provider cost + markup), in nanodollars, rounded up. */
    public function userCost(array $tokens, array $rates): int
    {
        $scaled = $this->scaledProviderCost($tokens, $rates);

        return Money::ceilDiv($scaled * (100 + $this->markupPercent), 100 * self::TOKENS_PER_RATE_UNIT);
    }

    /**
     * Estimate a generation before it runs, using the published token rules.
     *
     * "expected" uses the text-token estimate and the middle of the reasoning range;
     * "max" uses +15% text tokens and the most reasoning tokens possible, so the real
     * cost can never exceed it (at unchanged rates). We reserve the max from the balance.
     *
     * @return array{expected: array, max: array, expected_cost_nanos: int, max_cost_nanos: int}
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages, array $pricing): array
    {
        $t = $pricing['tokens'];
        $textEstimate = Money::ceilDiv(mb_strlen($prompt), (int) $t['characters_per_text_token']);
        // ceil(estimate × (1 + variance)), done in integers: variance 0.15 → 115 / 100.
        $variancePercent = (int) round(((float) $t['text_token_variance']) * 100);
        $textMax = Money::ceilDiv($textEstimate * (100 + $variancePercent), 100);

        $imageTokens = $referenceImages * (int) $t['per_reference_image'];
        $outputTokens = (int) $t['output'][$quality][$size];
        $reasoning = $t['reasoning'][$quality];

        $expected = [
            'input_text_tokens' => $textEstimate,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => intdiv((int) $reasoning['min'] + (int) $reasoning['max'], 2),
        ];

        $max = [
            'input_text_tokens' => $textMax,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => (int) $reasoning['max'],
        ];

        return [
            'expected' => $expected,
            'max' => $max,
            'expected_cost_nanos' => $this->userCost($expected, $pricing['rates']),
            'max_cost_nanos' => $this->userCost($max, $pricing['rates']),
        ];
    }
}
