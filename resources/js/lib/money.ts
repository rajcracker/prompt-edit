/**
 * Client-side mirror of App\Pixelforge\CostCalculator, used for the live estimate
 * while the user types. All maths is on BigInt nanodollars (1e-9 USD), never floats.
 * The server recomputes everything; this is only for display.
 */

export type TokenType = 'input_text_tokens' | 'input_image_tokens' | 'output_tokens' | 'reasoning_tokens';
export type Tokens = Record<TokenType, number>;

export interface Pricing {
    rates: Record<TokenType, string>;
    tokens: {
        characters_per_text_token: number;
        text_token_variance: number;
        per_reference_image: number;
        output: Record<string, Record<string, number>>;
        reasoning: Record<string, { min: number; max: number }>;
    };
}

const NANOS = 1_000_000_000n;
const TOKEN_TYPES: TokenType[] = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

export function toNanos(usd: string): bigint {
    const match = usd.trim().match(/^(-)?(\d+)(?:\.(\d{1,9}))?$/);
    if (!match) throw new Error(`Not a decimal amount: ${usd}`);
    const nanos = BigInt(match[2]) * NANOS + BigInt((match[3] ?? '').padEnd(9, '0'));
    return match[1] ? -nanos : nanos;
}

const ceilDiv = (a: bigint, b: bigint) => (a + b - 1n) / b;

export function userCostNanos(tokens: Tokens, rates: Pricing['rates'], markupPercent: number): bigint {
    const scaled = TOKEN_TYPES.reduce((sum, type) => sum + BigInt(tokens[type]) * toNanos(rates[type]), 0n);
    return ceilDiv(scaled * BigInt(100 + markupPercent), 100n * 1_000_000n);
}

export interface Estimate {
    expected: Tokens;
    max: Tokens;
    expectedCost: bigint;
    maxCost: bigint;
}

export function estimate(
    prompt: string,
    size: string,
    quality: string,
    referenceImages: number,
    pricing: Pricing,
    markupPercent: number,
): Estimate {
    const t = pricing.tokens;
    const characters = [...prompt].length; // Unicode characters, like PHP's mb_strlen
    const textEstimate = Math.ceil(characters / t.characters_per_text_token);
    const variancePercent = Math.round(t.text_token_variance * 100);
    const textMax = Math.ceil((textEstimate * (100 + variancePercent)) / 100);
    const reasoning = t.reasoning[quality];

    const expected: Tokens = {
        input_text_tokens: textEstimate,
        input_image_tokens: referenceImages * t.per_reference_image,
        output_tokens: t.output[quality][size],
        reasoning_tokens: Math.floor((reasoning.min + reasoning.max) / 2),
    };
    const max: Tokens = { ...expected, input_text_tokens: textMax, reasoning_tokens: reasoning.max };

    return {
        expected,
        max,
        expectedCost: userCostNanos(expected, pricing.rates, markupPercent),
        maxCost: userCostNanos(max, pricing.rates, markupPercent),
    };
}

/** Format nanodollars (BigInt, or a 9-decimal string from the server) for display, rounded half up. */
export function formatUsd(value: bigint | string | null, decimals = 4): string {
    if (value === null) return '—';
    const nanos = typeof value === 'bigint' ? value : toNanos(value);
    const abs = nanos < 0n ? -nanos : nanos;
    const unit = 10n ** BigInt(decimals);
    const step = 10n ** BigInt(9 - decimals);
    const rounded = (abs + step / 2n) / step;
    const fraction = decimals > 0 ? '.' + (rounded % unit).toString().padStart(decimals, '0') : '';
    return `${nanos < 0n ? '-' : ''}$${(rounded / unit).toLocaleString('en-US')}${fraction}`;
}

/** The exact amount with all nine decimals, for tooltips: "$0.046125000". */
export function exactUsd(value: string | null): string {
    if (value === null) return '—';
    return value.startsWith('-') ? `-$${value.slice(1)}` : `$${value}`;
}
