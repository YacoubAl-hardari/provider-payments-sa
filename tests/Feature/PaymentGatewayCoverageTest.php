<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Exceptions\ConfigurationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentRequestException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentStateException;
use YacoubAlhaidari\ProviderPayments\Exceptions\ProviderDisabledException;
use YacoubAlhaidari\ProviderPayments\Exceptions\UnsupportedOperationException;
use YacoubAlhaidari\ProviderPayments\Tests\TestCase;

final class PaymentGatewayCoverageTest extends TestCase
{
    public static function providers(): array
    {
        return array_map(fn ($s) => [$s], ['clickpay', 'telr', 'tabby', 'tamara', 'mispay']);
    }

    private function creation(string $p): array
    {
        return match ($p) {
            'clickpay' => ['tran_ref' => 'remote-1', 'redirect_url' => 'https://secure.clickpay.com.sa/checkout/1'],
            'telr' => ['order' => ['ref' => 'remote-1', 'url' => 'https://secure.telr.com/checkout/1']],
            'tabby' => ['id' => 'session-1', 'payment' => ['id' => 'remote-1'], 'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/1']]]]],
            'tamara' => ['order_id' => 'remote-1', 'checkout_id' => 'session-1', 'checkout_url' => 'https://checkout.tamara.co/1'],
            'mispay' => ['result' => ['trackId' => 'remote-1', 'url' => 'https://checkout.mispay.co/1']],
        };
    }

    #[DataProvider('providers')]
    public function test_real_http_creation_and_mapping(string $p): void
    {
        $g = $this->gateway($p);
        $fixture = $this->creation($p);
        Http::fake(fn ($r) => str_ends_with($r->url(), '/token') ? Http::response(['result' => ['token' => $this->encrypted(['token' => 'decrypted-jwt'])]]) : Http::response($fixture));
        $r = $g->createPayment($this->requestDTO());
        self::assertSame('remote-1', $r->providerTransactionId);
        self::assertSame(15000, $r->amount);
        self::assertSame(S::REQUIRES_ACTION, $r->status);
        Http::assertSent(function ($r) use ($p) {
            return match ($p) {
                'clickpay' => $r->hasHeader('authorization', 'test-key') && $r['profile_id'] === 123 && $r['cart_amount'] === '150.00' && $r['tran_type'] === 'sale',
                'telr' => $r['authkey'] === 'test-auth' && $r['store'] === '456' && $r['order']['test'] === 1 && $r['order']['amount'] === '150.00',
                'tabby' => $r->hasHeader('Authorization', 'Bearer test-secret') && $r['merchant_code'] === 'TEST' && $r['payment']['amount'] === '150.00' && $r['payment']['order']['items'][0]['unit_price'] === '150.00',
                'tamara' => $r->hasHeader('Authorization', 'Bearer test-token') && $r['total_amount']['amount'] === '150.00' && $r['order_reference_id'] === 'ORD-1',
                'mispay' => str_ends_with($r->url(), '/start-checkout') && $r->hasHeader('Authorization', 'Bearer decrypted-jwt') && $r->hasHeader('x-app-secret', 'test-secret') && $r['purchaseAmount'] === '150.00' && $r['version'] === 'v1.2' && ! isset($r['callbackUrl']),
            };
        });
        // Same request is served from ledger without a second charge.
        $again = $g->createPayment($this->requestDTO());
        self::assertSame($r->providerTransactionId, $again->providerTransactionId);
        Http::assertSentCount($p === 'mispay' ? 2 : 1);
    }

    #[DataProvider('providers')]
    public function test_disabled_provider_cannot_send_http_even_directly(string $p): void
    {
        $g = $this->gateway($p, false);
        Http::fake();
        try {
            $g->createPayment($this->requestDTO());
            self::fail();
        } catch (ProviderDisabledException) {
            Http::assertNothingSent();
        }
    }

    #[DataProvider('providers')]
    public function test_malformed_creation_never_returns_success(string $p): void
    {
        $g = $this->gateway($p);
        Http::fake(['*' => Http::response(['unexpected' => true])]);
        $this->expectException(PaymentRequestException::class);
        $g->createPayment($this->requestDTO());
    }

    #[DataProvider('providers')]
    public function test_missing_credentials_prevent_network(string $p): void
    {
        $class = 'YacoubAlhaidari\\ProviderPayments\\Providers\\'.match ($p) {
            'clickpay' => 'ClickPay','mispay' => 'MisPay',default => ucfirst($p)
        }.'\\'.match ($p) {
            'clickpay' => 'ClickPay','mispay' => 'MisPay',default => ucfirst($p)
        }.'Gateway';
        $g = new $class(['enabled' => true]);
        Http::fake();
        try {
            $g->createPayment($this->requestDTO());
            self::fail();
        } catch (ConfigurationException) {
            Http::assertNothingSent();
        }
    }

    public static function inquiry(): array
    {
        return [
            ['clickpay', ['tran_ref' => 'remote-1', 'profile_id' => '123', 'cart_id' => 'ORD-1', 'cart_currency' => 'SAR', 'cart_amount' => '150.00', 'tran_type' => 'sale', 'payment_result' => ['response_status' => 'A']], S::PAID],
            ['telr', ['order' => ['ref' => 'remote-1', 'cartid' => 'ORD-1', 'amount' => '150.00', 'currency' => 'SAR', 'test' => 1, 'status' => ['code' => 3]]], S::PAID],
            ['tabby', ['id' => 'remote-1', 'amount' => '150.00', 'currency' => 'SAR', 'status' => 'AUTHORIZED', 'order' => ['reference_id' => 'ORD-1']], S::AUTHORIZED],
            ['tamara', ['order_id' => 'remote-1', 'order_reference_id' => 'ORD-1', 'total_amount' => ['amount' => '150.00', 'currency' => 'SAR'], 'status' => 'authorised'], S::AUTHORIZED],
        ];
    }

    #[DataProvider('inquiry')]
    public function test_inquiry_works_after_provider_disabled(string $p, array $fixture, S $state): void
    {
        $g = $this->gateway($p, false);
        Http::fake(['*' => Http::response($fixture)]);
        $r = $g->getPaymentStatus('remote-1');
        self::assertSame($state, $r->status);
        self::assertSame(15000, $r->amount);
        self::assertSame('ORD-1', $r->orderReference);
    }

    public function test_clickpay_auth_is_not_paid(): void
    {
        $g = $this->gateway('clickpay');
        $d = self::inquiry()[0][1];
        $d['tran_type'] = 'auth';
        Http::fake(['*' => Http::response($d)]);
        self::assertSame(S::AUTHORIZED, $g->getPaymentStatus('remote-1')->status);
    }

    public function test_mispay_inquiry_and_refund_are_explicitly_unsupported(): void
    {
        $g = $this->gateway('mispay');
        self::assertFalse($g->supports('verify'));
        self::assertFalse($g->supports('refund'));
        $this->expectException(UnsupportedOperationException::class);
        $g->getPaymentStatus('x');
    }

    public function test_network_timeout_is_not_retried_or_exposed(): void
    {
        $g = $this->gateway('clickpay');
        Http::fake(fn () => throw new ConnectionException('secret-sensitive-body'));
        try {
            $g->createPayment($this->requestDTO());
            self::fail();
        } catch (PaymentRequestException $e) {
            self::assertStringNotContainsString('secret-sensitive-body', $e->getMessage());
        }
        Http::assertSentCount(0); // Laravel does not record a failed transport in its request log.
        Http::fake();
        $this->expectException(PaymentStateException::class);
        $g->createPayment($this->requestDTO());
    }

    public function test_http_error_does_not_leak_response_body(): void
    {
        $g = $this->gateway('clickpay');
        Http::fake(['*' => Http::response('sensitive-token', 500)]);
        try {
            $g->createPayment($this->requestDTO());
            self::fail();
        } catch (PaymentRequestException $e) {
            self::assertStringContainsString('HTTP 500', $e->getMessage());
            self::assertStringNotContainsString('sensitive-token', $e->getMessage());
        }Http::assertSentCount(1);
    }
}
