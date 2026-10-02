<?php

declare(strict_types=1);

namespace Zuste\Helpers;

use Zuste\Core\Config;

/**
 * Money arithmetic and formatting.
 *
 * Amounts are stored as DECIMAL(12,2)/DECIMAL(14,2) in MySQL and handled as
 * PHP floats *only* through this class, which rounds every intermediate result
 * to 2 dp. That keeps `amount + fee + tax === total` exactly true, which is
 * what the ledger invariant checks depend on.
 */
final class Money
{
    public const SCALE = 2;

    /** Round to storage precision. Always use this before persisting. */
    public static function round(float|int|string $amount): float
    {
        return round((float) $amount, self::SCALE);
    }

    public static function add(float ...$amounts): float
    {
        return self::round(array_sum($amounts));
    }

    public static function subtract(float $from, float ...$amounts): float
    {
        return self::round($from - array_sum($amounts));
    }

    public static function multiply(float $amount, float $factor): float
    {
        return self::round($amount * $factor);
    }

    /** Percentage of an amount, e.g. percentOf(25000, 10) === 2500.00 */
    public static function percentOf(float $amount, float $percent): float
    {
        return self::round($amount * ($percent / 100));
    }

    /** True when two amounts are equal at storage precision. */
    public static function equals(float $a, float $b): bool
    {
        return abs(self::round($a) - self::round($b)) < 0.005;
    }

    public static function isPositive(float $amount): bool
    {
        return self::round($amount) > 0;
    }

    public static function max(float ...$amounts): float
    {
        return self::round(max($amounts));
    }

    public static function min(float ...$amounts): float
    {
        return self::round(min($amounts));
    }

    /** Clamp to a range — used to keep a refund within the captured amount. */
    public static function clamp(float $amount, float $min, float $max): float
    {
        return self::round(max($min, min($max, $amount)));
    }

    // -----------------------------------------------------------------
    // Formatting
    // -----------------------------------------------------------------

    /** "₹25,000.00" — full precision, for invoices and payment screens. */
    public static function format(float $amount, ?string $currency = null): string
    {
        return self::symbol($currency) . self::groupIndian($amount, 2);
    }

    /** "₹25,000" — whole rupees, for list rows and cards. */
    public static function formatShort(float $amount, ?string $currency = null): string
    {
        return self::symbol($currency) . self::groupIndian(round($amount), 0);
    }

    /** "+ ₹5,000" / "- ₹27,500" for wallet ledger rows. */
    public static function formatSigned(float $amount, string $direction, ?string $currency = null): string
    {
        $sign = $direction === 'credit' ? '+ ' : '- ';

        return $sign . self::formatShort(abs($amount), $currency);
    }

    /** "₹15,000 - ₹50,000" for a creator's price range. */
    public static function formatRange(float $min, float $max, ?string $currency = null): string
    {
        if (self::equals($min, $max)) {
            return self::formatShort($min, $currency);
        }

        return self::formatShort($min, $currency) . ' - ' . self::formatShort($max, $currency);
    }

    /**
     * Indian digit grouping (2,2,3): 1234567 → "12,34,567".
     * number_format() cannot express this, so we group manually.
     */
    public static function groupIndian(float $amount, int $decimals = 2): string
    {
        $negative = $amount < 0;
        $amount   = abs($amount);

        $formatted = number_format($amount, $decimals, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $formatted), 2, '');

        if (strlen($whole) > 3) {
            $lastThree = substr($whole, -3);
            $rest      = substr($whole, 0, -3);
            $rest      = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
            $whole     = $rest . ',' . $lastThree;
        }

        $result = $whole . ($decimals > 0 && $fraction !== '' ? '.' . $fraction : '');

        return ($negative ? '-' : '') . $result;
    }

    /** Compact display for dashboard tiles: 1250000 → "₹12.5L". */
    public static function compact(float $amount, ?string $currency = null): string
    {
        $symbol = self::symbol($currency);
        $abs    = abs($amount);

        return $symbol . match (true) {
            $abs >= 10_000_000 => rtrim(rtrim(number_format($amount / 10_000_000, 2, '.', ''), '0'), '.') . 'Cr',
            $abs >= 100_000    => rtrim(rtrim(number_format($amount / 100_000, 2, '.', ''), '0'), '.') . 'L',
            $abs >= 1_000      => rtrim(rtrim(number_format($amount / 1_000, 1, '.', ''), '0'), '.') . 'K',
            default            => self::groupIndian($amount, 0),
        };
    }

    public static function symbol(?string $currency = null): string
    {
        $currency ??= self::defaultCurrency();

        return match (strtoupper($currency)) {
            'INR'   => '₹',
            'USD'   => '$',
            'EUR'   => '€',
            'GBP'   => '£',
            'AED'   => 'AED ',
            default => strtoupper($currency) . ' ',
        };
    }

    public static function defaultCurrency(): string
    {
        return strtoupper(Config::settingString('general', 'default_currency', 'INR'));
    }

    // -----------------------------------------------------------------
    // Gateway conversion
    // -----------------------------------------------------------------

    /**
     * Convert to the gateway's minor unit (paise/cents).
     * Razorpay and Stripe both take integers, never floats.
     */
    public static function toMinorUnits(float $amount): int
    {
        return (int) round(self::round($amount) * 100);
    }

    public static function fromMinorUnits(int $minor): float
    {
        return self::round($minor / 100);
    }

    // -----------------------------------------------------------------
    // Booking pricing
    // -----------------------------------------------------------------

    /**
     * Compute the full price breakdown for a booking from the platform's
     * current fee settings. Every caller (API, admin, cron) uses this one
     * function so the numbers can never diverge between surfaces.
     *
     * @return array{
     *   amount:float, platform_fee:float, commission_amount:float,
     *   tax_amount:float, tax_rate:float, total_amount:float,
     *   creator_earning:float, platform_fee_percent:float, commission_percent:float
     * }
     */
    public static function breakdown(float $amount, ?float $commissionOverride = null): array
    {
        $amount = self::round($amount);

        $feePercent        = Config::settingFloat('commission', 'platform_fee_percent', 10.0);
        $commissionPercent = $commissionOverride ?? Config::settingFloat('commission', 'creator_commission_percent', 10.0);
        $taxPercent        = Config::settingFloat('commission', 'tax_percent', 18.0);

        $platformFee = self::percentOf($amount, $feePercent);
        $commission  = self::percentOf($amount, $commissionPercent);

        // GST applies to the platform's service fee, not to the creator's fee.
        $tax = self::percentOf($platformFee, $taxPercent);

        return [
            'amount'               => $amount,
            'platform_fee'         => $platformFee,
            'commission_amount'    => $commission,
            'tax_amount'           => $tax,
            'tax_rate'             => $taxPercent,
            'total_amount'         => self::add($amount, $platformFee, $tax),
            'creator_earning'      => self::subtract($amount, $commission),
            'platform_fee_percent' => $feePercent,
            'commission_percent'   => $commissionPercent,
        ];
    }

    /**
     * Withdrawal fee, honouring both the percentage and flat settings.
     *
     * @return array{fee:float,net:float}
     */
    public static function withdrawalBreakdown(float $amount): array
    {
        $percent = Config::settingFloat('commission', 'withdrawal_fee_percent', 0.0);
        $flat    = Config::settingFloat('commission', 'withdrawal_fee_flat', 0.0);

        $fee = self::add(self::percentOf($amount, $percent), $flat);
        $fee = self::clamp($fee, 0.0, $amount);

        return ['fee' => $fee, 'net' => self::subtract($amount, $fee)];
    }
}
