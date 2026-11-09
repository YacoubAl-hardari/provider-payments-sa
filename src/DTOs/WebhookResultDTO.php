<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\DTOs;

use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus;

readonly class WebhookResultDTO
{
    public function __construct(
        public string $provider,
        public bool $success,
        public PaymentStatus $status,
        public ?string $transactionReference = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}
}
