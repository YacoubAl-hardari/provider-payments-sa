<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Enums;

use YacoubAlhaidari\ProviderPayments\Support\Money;

/**
 * Standard supported currencies.
 */
enum Currency: string
{
    case SAR = 'SAR';
    case AED = 'AED';
    case KWD = 'KWD';
    case BHD = 'BHD';
    case OMR = 'OMR';
    case QAR = 'QAR';
    case USD = 'USD';
    case EUR = 'EUR';

    /**
     * Get the number of decimal places for minor unit conversion.
     * (SAR, AED, USD, EUR = 2; KWD, BHD, OMR = 3)
     */
    public function decimalPlaces(): int
    {
        return match ($this) {
            self::KWD, self::BHD, self::OMR => 3,
            default => 2,
        };
    }

    /**
     * Convert a decimal amount (e.g. 150.00) to minor units (e.g. 15000 for SAR).
     */
    public function toMinorUnits(string|int $amount): int
    {
        return Money::minor($amount, $this->value);
    }

    public function fromMinorUnits(int $minorUnits): string
    {
        return Money::decimal($minorUnits, $this->value);
    }
}
