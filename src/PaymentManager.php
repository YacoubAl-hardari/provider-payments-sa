<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments;

use Closure;
use YacoubAlhaidari\ProviderPayments\Contracts\CredentialResolverInterface;
use YacoubAlhaidari\ProviderPayments\Contracts\PaymentGatewayInterface;
use YacoubAlhaidari\ProviderPayments\Contracts\RecoversPayments;
use YacoubAlhaidari\ProviderPayments\Contracts\RefundsPayments;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundResponseDTO;
use YacoubAlhaidari\ProviderPayments\Exceptions\UnsupportedOperationException;
use YacoubAlhaidari\ProviderPayments\Repositories\PaymentLedger;
use YacoubAlhaidari\ProviderPayments\Support\ArrayCredentialResolver;

class PaymentManager
{
    private ProviderRegistry $registry;

    private array $instances = [];

    public function __construct(private array $config = [], mixed $container = null, private ?CredentialResolverInterface $credentialResolver = null, ?ProviderRegistry $registry = null)
    {
        $this->registry = $registry ?? ProviderRegistry::defaults();
        $this->credentialResolver ??= new ArrayCredentialResolver($config['providers'] ?? []);
    }

    public function driver(?string $name = null): PaymentGatewayInterface
    {
        $name ??= $this->config['default'] ?? 'clickpay';

        return $this->instances[$name] ??= $this->registry->resolve($this->config['providers'][$name]['driver'] ?? $name, array_replace(($this->config['providers'][$name] ?? []) + ['timeout' => $this->config['timeout'] ?? 30, 'query_retries' => $this->config['query_retries'] ?? 0, 'logging' => $this->config['logging'] ?? false], ['payments_enabled' => $this->config['enabled'] ?? true, 'persistence' => $this->config['persistence']['enabled'] ?? false]), $this->credentialResolver);
    }

    public function gateway(?string $name = null): PaymentGatewayInterface
    {
        return $this->driver($name);
    }

    public function verifyPayment(string $reference, ?string $name = null): PaymentResponseDTO
    {
        $gateway = $this->driver($name);
        $ledger = app(PaymentLedger::class);
        $ledger->find($gateway->getName(), $reference);
        $result = $gateway->getPaymentStatus($reference);
        $ledger->accept($gateway->getName(), $ledger->eventKey($result), $result);
        $stored = $ledger->response($ledger->find($gateway->getName(), $reference));
        if ($stored->status !== $result->status) {
            return new PaymentResponseDTO(...array_replace(get_object_vars($stored), ['metadata' => $stored->metadata + ['remote_status' => $result->status->value, 'reconciliation_required' => true]]));
        }

        return $stored;
    }

    public function recoverPayment(string $orderReference, ?string $name = null): PaymentResponseDTO
    {
        $gateway = $this->driver($name);
        if (! $gateway instanceof RecoversPayments) {
            throw new UnsupportedOperationException('Provider has no audited cart-reference recovery.');
        }
        $ledger = app(PaymentLedger::class);
        $ledger->find($gateway->getName(), null, $orderReference);
        $remote = $gateway->getPaymentByOrderReference($orderReference);

        return $ledger->recover($gateway->getName(), $orderReference, $remote);
    }

    public function extend(string $name, Closure $factory): self
    {
        $this->registry->register($name, $factory);
        unset($this->instances[$name]);

        return $this;
    }

    public function register(string $name, PaymentGatewayInterface $gateway): self
    {
        $this->instances[$name] = $gateway;

        return $this;
    }

    public function setDefaultDriver(string $name): self
    {
        $this->config['default'] = $name;

        return $this;
    }

    public function createPayment(PaymentRequestDTO $request, ?string $driver = null): PaymentResponseDTO
    {
        return $this->driver($driver)->createPayment($request);
    }

    public function getPaymentStatus(string $ref, ?string $driver = null): PaymentResponseDTO
    {
        return $this->driver($driver)->getPaymentStatus($ref);
    }

    public function refund(RefundRequestDTO $request, ?string $driver = null): RefundResponseDTO
    {
        $g = $this->driver($driver);
        if (! $g instanceof RefundsPayments) {
            throw new UnsupportedOperationException('Refund is not implemented for this provider.');
        }

        return $g->refund($request);
    }
}
