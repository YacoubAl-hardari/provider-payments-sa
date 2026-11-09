<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Contracts;

use YacoubAlhaidari\ProviderPayments\DTOs\CaptureRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureResponseDTO;

interface CapturesPayments
{
    public function capture(CaptureRequestDTO $request): CaptureResponseDTO;
}
