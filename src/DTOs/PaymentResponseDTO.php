<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\DTOs;

use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus;

/**
 * Standardized payment response returned from createPayment and getPaymentStatus.
 */
readonly class PaymentResponseDTO
{
    public function __construct(
        public string $provider,
        public PaymentStatus $status,
        public ?string $providerTransactionId = null,
        public ?string $orderReference = null,
        public int $amount = 0,
        public string $currency = 'SAR',
        public ?string $checkoutUrl = null,
        public ?string $sessionId = null,
        public ?string $providerStatus = null,
        public ?string $message = null,
        public array $raw = [],
        public array $metadata = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function requiresRedirect(): bool
    {
        return ! empty($this->checkoutUrl);
    }
}
