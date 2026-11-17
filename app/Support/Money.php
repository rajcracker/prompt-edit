<?php

namespace App\Support;

/**
 * Money is stored as integer nanodollars (1 USD = 1,000,000,000 nanodollars).
 *
 * Pixelforge reports costs as exact decimals with nine places, so nanodollars
 * represent every amount exactly and integer maths never drifts like floats do.
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    /** "12.50" → 12500000000. Accepts up to nine decimal places, never uses floats. */
    public static function toNanos(string $usd): int
    {
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,9}))?$/', trim($usd), $m)) {
            throw new \InvalidArgumentException("Not a decimal dollar amount: {$usd}");
        }

        $nanos = (int) $m[2] * self::NANOS_PER_DOLLAR + (int) str_pad($m[3] ?? '', 9, '0');

        return $m[1] === '-' ? -$nanos : $nanos;
    }

    /** 46125000 → "0.046125000" (the same format Pixelforge's usage report uses). */
    public static function toDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $abs = abs($nanos);

        return $sign.intdiv($abs, self::NANOS_PER_DOLLAR).'.'.str_pad((string) ($abs % self::NANOS_PER_DOLLAR), 9, '0', STR_PAD_LEFT);
    }

    /** Integer division that rounds up (for non-negative numbers). */
    public static function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
