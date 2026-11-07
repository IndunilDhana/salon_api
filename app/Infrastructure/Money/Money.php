<?php

namespace App\Infrastructure\Money;

final class Money
{
    public const SUPPORTED_CURRENCIES = ['LKR', 'USD', 'GBP', 'EUR', 'INR', 'AUD'];

    public const DEFAULT_CURRENCY = 'LKR';

    public static function formatMinor(int $minor, string $currency = self::DEFAULT_CURRENCY, int $decimals = 2): string
    {
        $factor = 10 ** $decimals;
        $units = $minor / $factor;

        return sprintf('%s %s', $currency, number_format($units, $decimals));
    }

    public static function calculateCommission(int $amountMinor, int $bps): int
    {
        return intdiv($amountMinor * $bps + 5000, 10000);
    }
}
