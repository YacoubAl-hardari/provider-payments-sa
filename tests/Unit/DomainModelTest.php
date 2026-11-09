<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentStateException;
use YacoubAlhaidari\ProviderPayments\Support\Money;
use YacoubAlhaidari\ProviderPayments\Support\StateMachine;

final class DomainModelTest extends TestCase
{
    public function test_exact_money_and_three_decimal_currency(): void
    {
        self::assertSame('150.00', Money::decimal(15000));
        self::assertSame(15000, Money::minor('150.00'));
        self::assertSame('1.234', Money::decimal(1234, 'BHD'));
        self::assertSame(1234, Money::minor('1.234', 'KWD'));
        self::assertSame(PHP_INT_MAX, Money::minor(Money::decimal(PHP_INT_MAX)));
    }

    public static function invalidMoney(): array
    {
        return [['1.234', 'SAR'], ['-1', 'SAR'], ['1e2', 'SAR'], ['NaN', 'SAR'], ['999999999999999999999', 'SAR']];
    }

    #[DataProvider('invalidMoney')]
    public function test_invalid_money_rejected(string $value, string $currency): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::minor($value, $currency);
    }

    public function test_zero_checkout_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaymentRequestDTO('x', 0);
    }

    public function test_http_return_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaymentRequestDTO('x', 1, successUrl: 'http://example.com');
    }

    public function test_minor_unit_contract(): void
    {
        $r = new PaymentRequestDTO('x', 15000);
        self::assertSame('150.00', $r->formattedAmount());
        self::assertSame(15000, $r->minorUnits());
    }

    public function test_lifecycle_does_not_regress(): void
    {
        $this->expectException(PaymentStateException::class);
        StateMachine::assert(S::CAPTURED, S::PENDING);
    }

    public function test_lifecycle_allows_refunds_after_capture(): void
    {
        StateMachine::assert(S::CAPTURED, S::PARTIALLY_REFUNDED);
        StateMachine::assert(S::PARTIALLY_REFUNDED, S::REFUNDED);
        self::assertTrue(S::CAPTURED->isSuccessful());
        self::assertFalse(S::AUTHORIZED->isSuccessful());
    }
}
