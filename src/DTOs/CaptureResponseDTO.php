<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\DTOs;

readonly class CaptureResponseDTO
{
    public function __construct(
        public string $provider,
        public bool $success,
        public ?string $captureId = null,
        public ?string $providerTransactionId = null,
        public int $amount = 0,
        public string $currency = 'SAR',
        public ?string $message = null,
        public array $raw = [],
    ) {}
}
