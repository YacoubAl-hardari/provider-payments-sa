<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Events;

final class PaymentRefunded
{
    public function __construct(public string $provider, public string $transactionReference) {}
}
