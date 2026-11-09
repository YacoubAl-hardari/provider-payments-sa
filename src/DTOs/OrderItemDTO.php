<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\DTOs;

/**
 * Line item for orders (used by BNPL gateways like Tabby, Tamara, MisPay).
 */
readonly class OrderItemDTO
{
    public function __construct(
        public string $name,
        public int $unitPrice,
        public int $quantity = 1,
        public ?string $category = 'general',
        public ?string $sku = null,
        public ?string $description = null,
        public int $taxAmount = 0,
        public int $discountAmount = 0,
    ) {
        if ($unitPrice < 0 || $quantity < 1 || $quantity > 1000000 || $unitPrice > intdiv(PHP_INT_MAX, $quantity) || $taxAmount < 0 || $discountAmount < 0 || $taxAmount > PHP_INT_MAX - ($unitPrice * $quantity) || $discountAmount > ($unitPrice * $quantity) + $taxAmount) {
            throw new \InvalidArgumentException('Invalid item money or quantity.');
        }
    }

    public function total(): int
    {
        return ($this->unitPrice * $this->quantity) + $this->taxAmount - $this->discountAmount;
    }

    public static function fromArray(array $data): self
    {
        foreach (['unit_price', 'quantity', 'tax_amount', 'discount_amount'] as $key) {
            if (isset($data[$key]) && ! is_int($data[$key])) {
                throw new \InvalidArgumentException('Item money and quantity must be integers.');
            }
        }

        return new self(
            name: (string) ($data['name'] ?? 'Item'),
            unitPrice: (int) ($data['unit_price'] ?? 0.0),
            quantity: (int) ($data['quantity'] ?? 1),
            category: isset($data['category']) ? (string) $data['category'] : 'general',
            sku: isset($data['sku']) ? (string) $data['sku'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            taxAmount: (int) ($data['tax_amount'] ?? 0.0),
            discountAmount: (int) ($data['discount_amount'] ?? 0.0),
        );
    }
}
