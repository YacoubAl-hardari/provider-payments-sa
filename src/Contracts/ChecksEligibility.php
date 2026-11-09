<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Contracts;

use YacoubAlhaidari\ProviderPayments\DTOs\EligibilityRequestDTO;

/**
 * Optional capability: provider supports pre-checkout eligibility checks.
 * (e.g. Tamara pre-checkout eligibility API)
 */
interface ChecksEligibility
{
    /**
     * Check whether a customer is eligible for BNPL financing.
     * Returns true if eligible (or on API failure, to avoid blocking checkout).
     */
    public function checkEligibility(EligibilityRequestDTO $request): bool;
}
