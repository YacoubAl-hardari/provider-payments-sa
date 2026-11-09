<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\DTOs;

readonly class CancelResponseDTO
{
    public function __construct(public string $provider, public bool $success, public ?string $cancellationId, public string $providerTransactionId, public int $amount, public string $currency) {}
}
