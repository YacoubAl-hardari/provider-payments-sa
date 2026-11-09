<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Contracts;

use YacoubAlhaidari\ProviderPayments\DTOs\RefundRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundResponseDTO;

/**
 * Optional capability: provider supports refund operations.
 */
interface RefundsPayments
{
    /**
     * Initiate a refund for a captured payment.
     * Amount is integer minor units (e.g. 15000 = SAR 150.00).
     */
    public function refund(RefundRequestDTO $request): RefundResponseDTO;
}
