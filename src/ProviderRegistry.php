<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments;

use Closure;
use YacoubAlhaidari\ProviderPayments\Contracts\CredentialResolverInterface;
use YacoubAlhaidari\ProviderPayments\Contracts\PaymentGatewayInterface;
use YacoubAlhaidari\ProviderPayments\Exceptions\InvalidGatewayException;

final class ProviderRegistry
{
    private array $factories = [];

    public function register(string $name, Closure|string $factory): void
    {
        $this->factories[$name] = $factory;
    }

    public function resolve(string $name, array $config, ?CredentialResolverInterface $credentials): PaymentGatewayInterface
    {
        $factory = $this->factories[$name] ?? throw new InvalidGatewayException('Unknown payment provider.');
        $instance = $factory instanceof Closure ? $factory($config, $credentials) : new $factory($config, $credentials);
        if (! $instance instanceof PaymentGatewayInterface) {
            throw new InvalidGatewayException('Invalid provider factory.');
        }

        return $instance;
    }

    public function names(): array
    {
        return array_keys($this->factories);
    }

    public static function defaults(): self
    {
        $r = new self;
        foreach (['ClickPay', 'Telr', 'Tabby', 'Tamara', 'MisPay'] as $name) {
            $r->register(strtolower($name), __NAMESPACE__.'\\Providers\\'.$name.'\\'.$name.'Gateway');
        }
        $r->register('alinmapay', Providers\AlinmaPay\AlinmaPayGateway::class);
        $r->register('alrajhi', Providers\AlRajhi\AlRajhiGateway::class);

        return $r;
    }
}
