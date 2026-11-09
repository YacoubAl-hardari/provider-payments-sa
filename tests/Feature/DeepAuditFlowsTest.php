<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use YacoubAlhaidari\ProviderPayments\AbstractGateway;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundRequestDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Events\PaymentSucceeded;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\PaymentManager;
use YacoubAlhaidari\ProviderPayments\Repositories\PaymentLedger;
use YacoubAlhaidari\ProviderPayments\Support\CheckoutWidgets;
use YacoubAlhaidari\ProviderPayments\Tests\TestCase;

final class DeepAuditFlowsTest extends TestCase
{
    public static function allProviders(): array
    {
        return array_map(fn ($p) => [$p], ['telr', 'clickpay', 'tabby', 'tamara', 'mispay']);
    }

    public static function queriedProviders(): array
    {
        return array_map(fn ($p) => [$p], ['telr', 'clickpay', 'tabby', 'tamara']);
    }

    private function inquiry(string $p): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/contracts/inquiries.json'), true)[$p];
    }

    private function creation(string $p): array
    {
        return match ($p) {
            'telr' => ['order' => ['ref' => 'remote-1', 'url' => 'https://secure.telr.com/1']],
            'clickpay' => ['tran_ref' => 'remote-1', 'redirect_url' => 'https://secure.clickpay.com.sa/1'],
            'tabby' => ['id' => 'session-1', 'payment' => ['id' => 'remote-1'], 'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/1']]]]],
            'tamara' => ['order_id' => 'remote-1', 'checkout_id' => 'session-1', 'checkout_url' => 'https://checkout.tamara.co/1'],
            'mispay' => ['result' => ['trackId' => 'remote-1', 'url' => 'https://checkout.mispay.co/1']],
        };
    }

    private function notify(string $p): TestResponse
    {
        if ($p === 'clickpay') {
            $body = json_encode(['tran_ref' => 'remote-1', 'cart_id' => 'ORD-1']);

            return $this->call('POST', '/payments/clickpay/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGNATURE' => hash_hmac('sha256', $body, 'test-key')], $body);
        }
        if ($p === 'telr') {
            $d = ['tran_store' => '456', 'tran_type' => 'sale', 'tran_class' => 'ecom', 'tran_test' => '1', 'tran_ref' => 'transaction-1', 'tran_prevref' => '', 'tran_firstref' => '', 'tran_order' => 'remote-1', 'tran_currency' => 'SAR', 'tran_amount' => '150.00', 'tran_cartid' => 'ORD-1', 'tran_desc' => 'Order', 'tran_status' => 'A', 'tran_authcode' => '123', 'tran_authmessage' => 'OK'];
            $d['tran_check'] = sha1('test-secret:'.implode(':', array_values($d)));

            return $this->post('/payments/telr/webhook', $d);
        }

        return match ($p) {
            'tabby' => $this->postJson('/payments/tabby/webhook', ['id' => 'remote-1'], ['X-Tabby-Secret' => 'test-hook']),
            'tamara' => $this->postJson('/payments/tamara/webhook', ['order_id' => 'remote-1'], ['Authorization' => 'Bearer '.$this->jwt(['exp' => time() + 100])]),
            'mispay' => $this->postJson('/payments/mispay/webhook', ['orderId' => 'ORD-1', 'checkoutId' => 'remote-1', 'code' => 'MP00'], ['X-Mispay-Signature' => 'test-hook']),
        };
    }

    #[DataProvider('allProviders')]
    public function test_checkout_to_authenticated_notification_and_consumer_event_once(string $p): void
    {
        $g = $this->gateway($p);
        $settlements = 0;
        Event::listen(PaymentSucceeded::class, function () use (&$settlements) {
            $settlements++;
        });
        Http::fake(function ($r) use ($p) {
            if (str_ends_with($r->url(), '/token')) {
                return Http::response(['result' => ['token' => $this->encrypted(['token' => 'jwt'])]]);
            }
            $query = $r->method() === 'GET' || str_ends_with($r->url(), '/payment/query') || ($r['method'] ?? null) === 'check';

            return Http::response($query ? $this->inquiry($p) : $this->creation($p));
        });
        self::assertSame(S::REQUIRES_ACTION, $g->createPayment($this->requestDTO())->status);
        $this->notify($p)->assertOk();
        $this->notify($p)->assertOk();
        self::assertSame(1, $settlements);
        self::assertContains(DB::table('ppsa_transactions')->value('status'), ['paid', 'captured']);
    }

    #[DataProvider('queriedProviders')]
    public function test_foreign_currency_remote_snapshot_is_never_settled(string $p): void
    {
        $this->gateway($p, false);
        $this->seedPayment($p);
        $d = $this->inquiry($p);
        match ($p) {
            'telr' => $d['order']['currency'] = 'USD','clickpay' => $d['cart_currency'] = 'USD','tabby' => $d['currency'] = 'USD','tamara' => $d['total_amount']['currency'] = 'USD'
        };
        Http::fake(['*' => Http::response($d)]);
        $this->notify($p)->assertStatus(422);
        self::assertSame('requires_action', DB::table('ppsa_transactions')->value('status'));
    }

    #[DataProvider('queriedProviders')]
    public function test_unknown_local_transaction_does_not_query_provider(string $p): void
    {
        $this->gateway($p);
        Http::fake();
        $this->notify($p)->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_cart_query_recovers_ambiguous_clickpay_creation_without_new_charge(): void
    {
        $this->gateway('clickpay', false);
        DB::table('ppsa_transactions')->insert(['provider' => 'clickpay', 'order_reference' => 'ORD-1', 'amount' => 15000, 'currency' => 'SAR', 'status' => 'pending', 'fingerprint' => 'test', 'creation_state' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['*' => Http::response($this->inquiry('clickpay'))]);
        $r = app(PaymentManager::class)->recoverPayment('ORD-1', 'clickpay');
        self::assertSame(S::PAID, $r->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payment/query') && $r['cart_id'] === 'ORD-1');
        Http::assertSentCount(1);
    }

    public function test_multiple_cart_matches_do_not_attach_a_payment(): void
    {
        $this->gateway('clickpay');
        DB::table('ppsa_transactions')->insert(['provider' => 'clickpay', 'order_reference' => 'ORD-1', 'amount' => 15000, 'currency' => 'SAR', 'status' => 'pending', 'fingerprint' => 'test', 'creation_state' => 'reserved']);
        Http::fake(['*' => Http::response([$this->inquiry('clickpay'), $this->inquiry('clickpay')])]);
        $this->expectException(PaymentVerificationException::class);
        app(PaymentManager::class)->recoverPayment('ORD-1', 'clickpay');
    }

    public function test_reserved_refund_completes_when_full_refund_webhook_arrives_first(): void
    {
        $g = $this->gateway('tamara');
        $this->seedPayment('tamara', 'captured');
        Http::fake(function () {
            app(PaymentLedger::class)->accept('tamara', 'full-refund', new PaymentResponseDTO('tamara', S::REFUNDED, 'remote-1', 'ORD-1', 15000));

            return Http::response(['order_id' => 'remote-1', 'refund_id' => 'refund-1', 'refunded_amount' => ['amount' => '150.00', 'currency' => 'SAR']]);
        });
        self::assertTrue($g->refund(new RefundRequestDTO('remote-1', 15000, refundReference: 'r1'))->success);
        self::assertSame('confirmed', DB::table('ppsa_operations')->value('state'));
    }

    public function test_query_refund_can_skip_a_missed_capture_notification(): void
    {
        $this->seedPayment('tabby');
        self::assertTrue(app(PaymentLedger::class)->accept('tabby', 'refund-first', new PaymentResponseDTO('tabby', S::REFUNDED, 'remote-1', 'ORD-1', 15000, metadata: ['refunded_amount' => 15000])));
        self::assertSame('refunded', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_mispay_promotion_widget_uses_public_key_and_sandbox_host(): void
    {
        config(['provider-payments.providers.mispay.widget_key' => 'public-widget', 'provider-payments.providers.mispay.widget_url' => 'https://widget-sandbox.mispay.dev/v1/sdk.js']);
        $w = app(CheckoutWidgets::class)->configuration('mispay', 15000);
        self::assertSame('https://widget-sandbox.mispay.dev/v1/sdk.js?authorize=public-widget', $w['script_url']);
        self::assertSame('150.00', $w['attributes']['amount']);
        self::assertArrayNotHasKey('app_secret', $w);
    }

    public function test_tamara_widget_uses_selected_environment(): void
    {
        config(['provider-payments.providers.tamara.public_key' => 'public-tamara', 'provider-payments.providers.tamara.base_url' => 'https://api.tamara.co']);
        self::assertSame('https://cdn.tamara.co/widget-v2/tamara-widget.js', app(CheckoutWidgets::class)->configuration('tamara', 15000)['script_url']);
    }

    public function test_tabby_widget_exposes_only_public_merchant_options(): void
    {
        config(['provider-payments.providers.tabby.credentials.public_key' => 'public-tabby', 'provider-payments.providers.tabby.credentials.merchant_code' => 'merchant-public', 'provider-payments.providers.tabby.credentials.secret_key' => 'never-expose']);
        $w = app(CheckoutWidgets::class)->configuration('tabby', 15000);
        self::assertSame('150.00', $w['options']['price']);
        self::assertStringNotContainsString('never-expose', json_encode($w));
    }

    public function test_sixth_gateway_registers_only_in_test_without_manager_changes(): void
    {
        $manager = new PaymentManager(['providers' => ['sixth' => ['enabled' => true]], 'default' => 'sixth']);
        $manager->extend('sixth', fn ($config, $credentials) => new class($config, $credentials) extends AbstractGateway
        {
            protected string $name = 'sixth';

            protected function requiredCredentials(): array
            {
                return [];
            }

            protected function createRemote(PaymentRequestDTO $r): PaymentResponseDTO
            {
                return new PaymentResponseDTO('sixth', S::REQUIRES_ACTION, 'test-only', $r->orderReference, $r->amount, $r->currency);
            }

            public function getPaymentStatus(string $ref): PaymentResponseDTO
            {
                return new PaymentResponseDTO('sixth', S::PENDING, $ref);
            }
        });
        self::assertSame('sixth', $manager->createPayment($this->requestDTO())->provider);
        self::assertFalse($manager->gateway()->supports('refund'));
        Http::assertNothingSent();
    }

    #[DataProvider('allProviders')]
    public function test_legacy_url_defaults_resolve_without_overriding_explicit_urls(string $p): void
    {
        $base = $this->requestDTO();
        $c = config('provider-payments.providers.'.$p);
        $c['urls'] = ['successUrl' => 'https://merchant.example/default-success', 'cancelUrl' => 'https://merchant.example/default-cancel'];
        config(['provider-payments.providers.'.$p => $c]);
        $g = $this->gateway($p);
        Http::fake(fn ($q) => str_ends_with($q->url(), '/token') ? Http::response(['result' => ['token' => $this->encrypted(['token' => 'jwt'])]]) : Http::response($this->creation($p)));
        self::assertSame('remote-1', $g->createPayment(new PaymentRequestDTO('ORD-1', 15000, customer: $base->customer, items: $base->items))->providerTransactionId);
        $resolved = $base->withUrlDefaults(['successUrl' => 'https://merchant.example/default-success']);
        self::assertSame($base->successUrl, $resolved->successUrl);
    }

    public function test_mispay_shipping_tax_discount_and_mobile_are_preserved_exactly(): void
    {
        $g = $this->gateway('mispay');
        $base = $this->requestDTO();
        Http::fake(fn ($q) => str_ends_with($q->url(), '/token') ? Http::response(['result' => ['token' => $this->encrypted(['token' => 'jwt'])]]) : Http::response($this->creation('mispay')));
        $r = new PaymentRequestDTO('ORD-1', 16000, customer: $base->customer, successUrl: $base->successUrl, cancelUrl: $base->cancelUrl, items: $base->items, shippingAmount: 1000, taxAmount: 500, discountAmount: 500, isMobile: true);
        $g->createPayment($r);
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/start-checkout') && $q['totalPrice'] === '150.00' && $q['shippingAmount'] === '10.00' && $q['vat'] === '5.00' && $q['discount']['amount'] === '5.00' && $q['purchaseAmount'] === '160.00' && $q['isMobile'] === true);
    }

    #[DataProvider('queriedProviders')]
    public function test_wrong_remote_amount_cannot_settle_any_inquiry_provider(string $p): void
    {
        $this->gateway($p);
        $this->seedPayment($p);
        $d = $this->inquiry($p);
        match ($p) {
            'telr' => $d['order']['amount'] = '149.00','clickpay' => $d['cart_amount'] = '149.00','tabby' => $d['amount'] = '149.00','tamara' => $d['total_amount']['amount'] = '149.00'
        };
        Http::fake(['*' => Http::response($d)]);
        $this->notify($p)->assertStatus(422);
        self::assertSame('requires_action', DB::table('ppsa_transactions')->value('status'));
    }

    #[DataProvider('allProviders')]
    public function test_nonempty_wrong_authentication_is_rejected_before_inquiry(string $p): void
    {
        $this->gateway($p);
        $this->seedPayment($p);
        Http::fake();
        $headers = ['signature' => str_repeat('0', 64), 'X-Tabby-Secret' => 'wrong', 'X-Mispay-Signature' => 'wrong', 'Authorization' => 'Bearer '.$this->jwt(['exp' => time() + 100], 'wrong-key')];
        $this->postJson('/payments/'.$p.'/webhook', ['tran_store' => '456', 'tran_test' => '1', 'tran_check' => str_repeat('0', 40), 'tran_ref' => 'remote-1', 'id' => 'remote-1', 'order_id' => 'remote-1', 'orderId' => 'ORD-1', 'checkoutId' => 'remote-1', 'code' => 'MP00'], $headers)->assertStatus(401);
        Http::assertNothingSent();
    }

    #[DataProvider('allProviders')]
    public function test_authenticated_historical_notification_works_after_provider_disabled(string $p): void
    {
        $this->gateway($p, false);
        $this->seedPayment($p);
        Http::fake(['*' => Http::response($p === 'mispay' ? ['unused' => true] : $this->inquiry($p))]);
        $this->notify($p)->assertOk();
        self::assertContains(DB::table('ppsa_transactions')->value('status'), ['paid', 'captured']);
    }
}
