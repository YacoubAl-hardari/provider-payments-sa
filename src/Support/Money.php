<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Support;

use YacoubAlhaidari\ProviderPayments\Enums\Currency;

final class Money
{
    public static function places(string $currency): int
    {
        return Currency::from($currency)->decimalPlaces();
    }

    public static function decimal(int $minor, string $currency = 'SAR'): string
    {
        if ($minor < 0) {
            throw new \InvalidArgumentException('Negative money.');
        }
        $places = self::places($currency);
        $factor = 10 ** $places;

        return intdiv($minor, $factor).'.'.str_pad((string) ($minor % $factor), $places, '0', STR_PAD_LEFT);
    }

    public static function minor(string|int $decimal, string $currency = 'SAR'): int
    {
        $places = self::places($currency);
        if (! preg_match('/^\d+(?:\.\d{1,'.$places.'})?$/D', (string) $decimal)) {
            throw new \InvalidArgumentException('Invalid decimal money or excess precision.');
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $decimal, 2), 2, '');
        $digits = ltrim($whole.str_pad($fraction, $places, '0'), '0') ?: '0';
        if (strlen($digits) > strlen((string) PHP_INT_MAX) || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)) {
            throw new \InvalidArgumentException('Money exceeds integer range.');
        }

        return (int) $digits;
    }
}
