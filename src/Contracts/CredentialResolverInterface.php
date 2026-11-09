<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Contracts;

/**
 * Contract for resolving provider credentials.
 *
 * The default implementation reads from Laravel config (env-driven).
 * Custom implementations may read from a database, tenant store, etc.
 * This contract ensures the architecture supports multi-merchant scenarios
 * without requiring a full multi-tenant implementation upfront.
 */
interface CredentialResolverInterface
{
    /**
     * Get a credential value for the given provider and key.
     *
     * @param  string  $provider  Provider slug (e.g. 'clickpay', 'tamara')
     * @param  string  $key  Credential key (e.g. 'server_key', 'api_token')
     * @param  mixed  $default  Default value if not found
     */
    public function get(string $provider, string $key, mixed $default = null): mixed;

    /**
     * Get all credentials for the given provider.
     *
     * @return array<string, mixed>
     */
    public function all(string $provider): array;

    /**
     * Whether the given provider has all required credentials set.
     *
     * @param  array<string>  $required
     */
    public function hasAll(string $provider, array $required): bool;
}
