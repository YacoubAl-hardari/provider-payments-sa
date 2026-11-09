<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use YacoubAlhaidari\ProviderPayments\Contracts\PaymentGatewayInterface;
use YacoubAlhaidari\ProviderPayments\DTOs\CustomerDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\OrderItemDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\PaymentManager;
use YacoubAlhaidari\ProviderPayments\ProviderPaymentsServiceProvider;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ProviderPaymentsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('provider-payments.persistence.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $migration = require __DIR__.'/../database/migrations/2026_11_09_000000_create_provider_payment_tables.php';
        $migration->up();
        Http::preventStrayRequests();
    }

    protected function gateway(string $provider, bool $enabled = true): PaymentGatewayInterface
    {
        $credentials = match ($provider) {
            'alinmapay' => ['terminal_id' => 'test-terminal', 'terminal_password' => 'test-password', 'merchant_key' => str_repeat('ab', 32)],
            'alrajhi' => ['tranportal_id' => 'test-portal', 'tranportal_password' => 'test-password', 'resource_key' => '01234567890123456789012345678901'],
            'clickpay' => ['profile_id' => '123', 'server_key' => 'test-key'],'telr' => ['store_id' => '456', 'auth_key' => 'test-auth', 'secret_key' => 'test-secret'],'tabby' => ['merchant_code' => 'TEST', 'secret_key' => 'test-secret', 'webhook_secret' => 'test-hook'],'tamara' => ['api_token' => 'test-token', 'notification_token' => 'test-notify'],'mispay' => ['app_id' => 'test-app', 'app_secret' => 'test-secret', 'webhook_secret' => 'test-hook']
        };
        $c = config('provider-payments.providers.'.$provider);
        $c['credentials'] = $credentials;
        $c['enabled'] = $enabled;
        $c['webhook_header'] = 'X-Tabby-Secret';
        if ($provider === 'alrajhi') {
            $c['merchant_ip'] = '192.0.2.10';
        }
        config(['provider-payments.providers.'.$provider => $c]);
        $this->app->forgetInstance('payment.manager');

        return app(PaymentManager::class)->gateway($provider);
    }

    protected function requestDTO(string $ref = 'ORD-1', int $amount = 15000): PaymentRequestDTO
    {
        return new PaymentRequestDTO($ref, $amount, 'SAR', new CustomerDTO('Test Customer', 'test@example.com', '+966500000000', 'Street', 'Riyadh', 'SA', '12345'), 'https://merchant.example/success', 'https://merchant.example/cancel', 'https://merchant.example/failure', 'https://merchant.example/payments/hook', items: [new OrderItemDTO('Item', $amount, sku: 'SKU-1')]);
    }

    protected function seedPayment(string $provider, string $state = 'requires_action', int $refunded = 0): void
    {
        DB::table('ppsa_transactions')->insert(['provider' => $provider, 'order_reference' => 'ORD-1', 'provider_reference' => 'remote-1', 'fingerprint' => 'test', 'amount' => 15000, 'currency' => 'SAR', 'status' => $state, 'creation_state' => 'ready', 'refunded_amount' => $refunded, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function encrypted(array $data, string $secret = 'test-secret'): string
    {
        $salt = str_repeat('s', 16);
        $nonce = str_repeat('n', 12);
        $key = hash_pbkdf2('sha256', $secret, $salt, 40000, 32, true);
        $cipher = openssl_encrypt(json_encode($data), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        return base64_encode($salt.$nonce.$cipher.$tag);
    }

    protected function jwt(array $payload, string $secret = 'test-notify', string $alg = 'HS256'): string
    {
        $b = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $a = $b(json_encode(['alg' => $alg, 'typ' => 'JWT']));
        $p = $b(json_encode($payload));

        return $a.'.'.$p.'.'.$b(hash_hmac('sha256', $a.'.'.$p, $secret, true));
    }
}
