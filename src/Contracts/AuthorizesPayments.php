<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Contracts;

use YacoubAlhaidari\ProviderPayments\DTOs\AuthorizeRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\AuthorizeResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureResponseDTO;

/**
 * Optional capability: provider supports authorize + capture flow.
 * (e.g. Tamara, Tabby)
 */
interface AuthorizesPayments
{
    /**
     * Request authorization for a payment (funds reserved but not captured).
     */
    public function authorize(AuthorizeRequestDTO $request): AuthorizeResponseDTO;

    /**
     * Capture a previously authorized payment.
     */
    public function capture(CaptureRequestDTO $request): CaptureResponseDTO;
}
