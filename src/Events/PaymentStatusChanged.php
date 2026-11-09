<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Events;

use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus;

class PaymentStatusChanged
{
    public function __construct(
        public string $provider,
        public string $transactionReference,
        public PaymentStatus $status,
    ) {}
}
