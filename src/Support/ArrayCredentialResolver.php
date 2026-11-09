<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Support;

use YacoubAlhaidari\ProviderPayments\Contracts\CredentialResolverInterface;

class ArrayCredentialResolver implements CredentialResolverInterface
{
    /**
     * @param  array<string, array<string, mixed>>  $providers
     */
    public function __construct(protected array $providers = []) {}

    public function get(string $provider, string $key, mixed $default = null): mixed
    {
        $providerConfig = $this->providers[$provider] ?? [];
        $credentials = $providerConfig['credentials'] ?? $providerConfig;

        return $credentials[$key] ?? $default;
    }

    public function all(string $provider): array
    {
        return $this->providers[$provider]['credentials'] ?? $this->providers[$provider] ?? [];
    }

    public function hasAll(string $provider, array $required): bool
    {
        $credentials = $this->all($provider);

        foreach ($required as $key) {
            if (! array_key_exists($key, $credentials) || empty($credentials[$key])) {
                return false;
            }
        }

        return true;
    }
}
