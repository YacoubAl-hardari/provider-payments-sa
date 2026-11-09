<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Contracts;

use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;

interface RecoversPayments
{
    public function getPaymentByOrderReference(string $orderReference): PaymentResponseDTO;
}
