<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\DTOs;

/**
 * Customer representation for checkout and billing purposes.
 */
readonly class CustomerDTO
{
    public function __construct(
        public string $name,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $street = null,
        public ?string $city = null,
        public ?string $country = 'SA',
        public ?string $zip = null,
        public ?string $ip = null,
    ) {
        if (trim($name) === '' || ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new \InvalidArgumentException('Invalid customer name/email.');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? 'Customer'),
            email: isset($data['email']) ? (string) $data['email'] : null,
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
            street: isset($data['street']) ? (string) $data['street'] : null,
            city: isset($data['city']) ? (string) $data['city'] : null,
            country: (string) ($data['country'] ?? 'SA'),
            zip: isset($data['zip']) ? (string) $data['zip'] : null,
            ip: isset($data['ip']) ? (string) $data['ip'] : null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'street' => $this->street,
            'city' => $this->city,
            'country' => $this->country,
            'zip' => $this->zip,
            'ip' => $this->ip,
        ], fn ($val) => $val !== null);
    }
}
