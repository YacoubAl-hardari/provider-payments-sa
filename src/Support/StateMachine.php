<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Support;

use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentStateException;

final class StateMachine
{
    public static function assert(S $from, S $to): void
    {
        if ($from === $to) {
            return;
        }
        $allowed = match ($from) {
            S::PENDING, S::REQUIRES_ACTION => [S::REQUIRES_ACTION, S::AUTHORIZED, S::PAID, S::CAPTURED, S::FAILED, S::CANCELLED, S::EXPIRED],
            S::AUTHORIZED => [S::CAPTURED, S::PAID, S::CANCELLED, S::EXPIRED],
            S::CAPTURED, S::PAID => [S::PARTIALLY_REFUNDED, S::REFUNDED],
            S::PARTIALLY_REFUNDED => [S::REFUNDED],
            default => [],
        };
        if (! in_array($to, $allowed, true)) {
            throw new PaymentStateException('Rejected payment status transition.');
        }
    }
}
