<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Events;

final class PaymentSucceeded
{
    public function __construct(public string $provider, public string $transactionReference) {}
}
