<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Contracts;

use YacoubAlhaidari\ProviderPayments\DTOs\CancelRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CancelResponseDTO;

interface CancelsPayments
{
    public function cancel(CancelRequestDTO $request): CancelResponseDTO;
}
