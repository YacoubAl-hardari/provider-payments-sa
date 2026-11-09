<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\DTOs;

use YacoubAlhaidari\ProviderPayments\Support\Money;

/**
 * Standardized payment creation request.
 */
readonly class PaymentRequestDTO
{
    public function __construct(
        public string $orderReference,
        public int $amount,
        public string $currency = 'SAR',
        public ?CustomerDTO $customer = null,
        public ?string $successUrl = null,
        public ?string $cancelUrl = null,
        public ?string $failureUrl = null,
        public ?string $webhookUrl = null,
        public ?string $description = null,
        public array $items = [],
        public array $metadata = [],
        public string $language = 'ar',
        public int $shippingAmount = 0,
        public int $taxAmount = 0,
        public int $discountAmount = 0,
        public bool $isMobile = false,
    ) {
        if ($orderReference === '' || strlen($orderReference) > 128 || $amount <= 0) {
            throw new \InvalidArgumentException('Positive minor amount and order reference required.');
        }
        Money::places($currency);
        if ($shippingAmount < 0 || $taxAmount < 0 || $discountAmount < 0 || ! in_array($language, ['ar', 'en'], true)) {
            throw new \InvalidArgumentException('Invalid checkout adjustments or language.');
        }
        foreach ($items as $item) {
            if (! $item instanceof OrderItemDTO) {
                throw new \InvalidArgumentException('Invalid order item.');
            }
        }
        foreach ([$successUrl, $cancelUrl, $failureUrl, $webhookUrl] as $url) {
            if ($url !== null && (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https')) {
                throw new \InvalidArgumentException('HTTPS callback URLs required.');
            }
        }
    }

    public function withUrlDefaults(array $urls): self
    {
        $values = get_object_vars($this);
        foreach (['successUrl', 'cancelUrl', 'failureUrl', 'webhookUrl'] as $key) {
            $default = $urls[$key] ?? null;
            $values[$key] ??= is_string($default) && trim($default) !== '' ? $default : null;
        }

        return new self(...$values);
    }

    public function formattedAmount(): string
    {
        return Money::decimal($this->amount, $this->currency);
    }

    public function minorUnits(): int
    {
        return $this->amount;
    }
}
