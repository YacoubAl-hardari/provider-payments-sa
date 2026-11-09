<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Contracts;

use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;

/**
 * Core contract every payment gateway must implement.
 *
 * Optional capabilities are separated into dedicated interfaces
 * (RefundsPayments, AuthorizesPayments, etc.) to avoid forcing
 * unsupported methods on providers.
 */
interface PaymentGatewayInterface
{
    /**
     * Unique lowercase slug identifying this gateway (e.g. 'clickpay', 'tabby').
     */
    public function getName(): string;

    /**
     * Whether this gateway is currently enabled in configuration.
     */
    public function isEnabled(): bool;

    /**
     * Whether this gateway has all required credentials configured.
     */
    public function isConfigured(): bool;

    /**
     * Initiate a new payment and return a response containing
     * a redirect URL, session ID, or embedded widget data.
     */
    public function createPayment(PaymentRequestDTO $request): PaymentResponseDTO;

    /**
     * Query the current status of a payment using the provider's
     * transaction reference or internal reference.
     */
    public function getPaymentStatus(string $transactionReference): PaymentResponseDTO;

    /**
     * Whether this gateway supports a specific capability.
     * Use capability slugs: 'refund', 'authorize', 'capture', 'eligibility', 'embedded'.
     */
    public function supports(string $capability): bool;

    /**
     * Returns a map of all supported capabilities with their status.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array;
}
