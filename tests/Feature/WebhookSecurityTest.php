<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use YacoubAlhaidari\ProviderPayments\Events\PaymentSucceeded;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Security\MisPayToken;
use YacoubAlhaidari\ProviderPayments\Support\Money;
use YacoubAlhaidari\ProviderPayments\Tests\TestCase;

final class WebhookSecurityTest extends TestCase
{
    public static function providers(): array
    {
        return array_map(fn ($p) => [$p], ['clickpay', 'telr', 'tabby', 'tamara', 'mispay']);
    }

    #[DataProvider('providers')]
    public function test_unsigned_webhooks_cannot_settle(string $p): void
    {
        $this->gateway($p);
        $this->seedPayment($p);
        Http::fake();
        $this->postJson('/payments/'.$p.'/webhook', ['id' => 'remote-1', 'order_id' => 'remote-1', 'code' => 'MP00'])->assertStatus(401);
        self::assertSame('requires_action', DB::table('ppsa_transactions')->value('status'));
        Http::assertNothingSent();
    }

    private function clickpayRaw(int $amount = 15000): array
    {
        return ['tran_ref' => 'remote-1', 'profile_id' => '123', 'cart_id' => 'ORD-1', 'cart_currency' => 'SAR', 'cart_amount' => Money::decimal($amount), 'tran_type' => 'sale', 'payment_result' => ['response_status' => 'A']];
    }

    private function signedClickPay(array $body): TestResponse
    {
        $raw = json_encode($body);

        return $this->call('POST', '/payments/clickpay/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGNATURE' => hash_hmac('sha256', $raw, 'test-key')], $raw);
    }

    public function test_clickpay_raw_hmac_query_integrity_and_replay(): void
    {
        $this->gateway('clickpay', false);
        $this->seedPayment('clickpay');
        Event::fake([PaymentSucceeded::class]);
        Http::fake(['*' => Http::response($this->clickpayRaw())]);
        $this->signedClickPay(['tran_ref' => 'remote-1'])->assertOk()->assertJson(['accepted' => true]);
        $this->signedClickPay(['tran_ref' => 'remote-1'])->assertOk()->assertJson(['accepted' => false]);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        self::assertSame('paid', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_clickpay_signed_body_cannot_override_remote_amount(): void
    {
        $this->gateway('clickpay');
        $this->seedPayment('clickpay');
        Http::fake(['*' => Http::response($this->clickpayRaw(14000))]);
        $this->signedClickPay(['tran_ref' => 'remote-1', 'cart_amount' => '150.00'])->assertStatus(422);
        self::assertSame(0, DB::table('ppsa_webhook_events')->count());
    }

    public function test_clickpay_query_rejects_other_profile(): void
    {
        $g = $this->gateway('clickpay');
        $d = $this->clickpayRaw();
        $d['profile_id'] = '999';
        Http::fake(['*' => Http::response($d)]);
        $this->expectException(PaymentVerificationException::class);
        $g->getPaymentStatus('remote-1');
    }

    public function test_tampered_clickpay_body_fails_even_with_valid_signature_for_different_body(): void
    {
        $this->gateway('clickpay');
        Http::fake();
        $this->call('POST', '/payments/clickpay/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGNATURE' => hash_hmac('sha256', '{"tran_ref":"other"}', 'test-key')], '{"tran_ref":"remote-1"}')->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_tabby_uses_configured_header_and_queries_remote(): void
    {
        $this->gateway('tabby');
        $this->seedPayment('tabby');
        Http::fake(['*' => Http::response(['id' => 'remote-1', 'status' => 'AUTHORIZED', 'currency' => 'SAR', 'amount' => '150.00', 'order' => ['reference_id' => 'ORD-1']])]);
        $this->postJson('/payments/tabby/webhook', ['id' => 'remote-1', 'status' => 'CLOSED'], ['X-Tabby-Secret' => 'test-hook'])->assertOk();
        self::assertSame('authorized', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_tabby_rejects_different_order_in_authenticated_remote_result(): void
    {
        $this->gateway('tabby');
        $this->seedPayment('tabby');
        Http::fake(['*' => Http::response(['id' => 'remote-1', 'status' => 'AUTHORIZED', 'currency' => 'SAR', 'amount' => '150.00', 'order' => ['reference_id' => 'OTHER']])]);
        $this->postJson('/payments/tabby/webhook', ['id' => 'remote-1'], ['X-Tabby-Secret' => 'test-hook'])->assertStatus(422);
    }

    public function test_tamara_valid_jwt_queries_remote_status(): void
    {
        $this->gateway('tamara');
        $this->seedPayment('tamara');
        Http::fake(['*' => Http::response(['order_id' => 'remote-1', 'order_reference_id' => 'ORD-1', 'status' => 'authorised', 'total_amount' => ['amount' => '150.00', 'currency' => 'SAR']])]);
        $this->postJson('/payments/tamara/webhook', ['order_id' => 'remote-1', 'event_type' => 'order_captured'], ['Authorization' => 'Bearer '.$this->jwt(['exp' => time() + 300])])->assertOk();
        self::assertSame('authorized', DB::table('ppsa_transactions')->value('status'));
    }

    public static function badTokens(): array
    {
        return [['expired'], ['wrong-key'], ['wrong-alg'], ['future-nbf'], ['future-iat']];
    }

    #[DataProvider('badTokens')]
    public function test_tamara_invalid_jwt_rejected(string $kind): void
    {
        $this->gateway('tamara');
        Http::fake();
        $claims = match ($kind) {
            'expired' => ['exp' => time() - 1],'future-nbf' => ['nbf' => time() + 999],'future-iat' => ['iat' => time() + 999],default => ['exp' => time() + 300]
        };
        $token = $this->jwt($claims, $kind === 'wrong-key' ? 'bad' : 'test-notify', $kind === 'wrong-alg' ? 'none' : 'HS256');
        $this->postJson('/payments/tamara/webhook', ['order_id' => 'remote-1'], ['Authorization' => 'Bearer '.$token])->assertStatus(401);
        Http::assertNothingSent();
    }

    private function telrAdvice(): array
    {
        $p = ['tran_store' => '456', 'tran_type' => 'sale', 'tran_class' => 'ecom', 'tran_test' => '1', 'tran_ref' => 'advice-transaction', 'tran_prevref' => '', 'tran_firstref' => '', 'tran_order' => 'remote-1', 'tran_currency' => 'SAR', 'tran_amount' => '150.00', 'tran_cartid' => 'ORD-1', 'tran_desc' => 'Literal + and %25', 'tran_status' => 'A', 'tran_authcode' => '1234', 'tran_authmessage' => 'OK'];
        $p['tran_check'] = sha1('test-secret:'.implode(':', array_values($p)));

        return $p;
    }

    public function test_telr_form_signature_preserves_plus_and_optional_order_field(): void
    {
        $this->gateway('telr');
        $this->seedPayment('telr');
        Http::fake(['*' => Http::response(['order' => ['ref' => 'remote-1', 'cartid' => 'ORD-1', 'amount' => '150.00', 'currency' => 'SAR', 'test' => 1, 'status' => ['code' => 3]]])]);
        $this->post('/payments/telr/webhook', $this->telrAdvice())->assertOk();
        self::assertSame('paid', DB::table('ppsa_transactions')->value('status'));
        Http::assertSent(fn ($r) => $r['order']['ref'] === 'remote-1' && $r['method'] === 'check');
    }

    public function test_telr_wrong_environment_rejected(): void
    {
        $g = $this->gateway('telr');
        $p = $this->telrAdvice();
        $p['tran_test'] = '0';
        $p['tran_check'] = sha1('test-secret:'.implode(':', array_values(array_diff_key($p, ['tran_check' => true]))));
        self::assertFalse($g->verifyWebhook(Request::create('/', 'POST', $p)));
    }

    public function test_mispay_secret_and_track_identity_and_replay(): void
    {
        $this->gateway('mispay');
        $this->seedPayment('mispay');
        Event::fake([PaymentSucceeded::class]);
        Http::fake();
        $payload = ['checkoutId' => 'remote-1', 'orderId' => 'ORD-1', 'code' => 'MP00'];
        $headers = ['X-Mispay-Signature' => 'test-hook'];
        $this->postJson('/payments/mispay/webhook', $payload, $headers)->assertOk()->assertJson(['accepted' => true]);
        $this->postJson('/payments/mispay/webhook', $payload, $headers)->assertOk()->assertJson(['accepted' => false]);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Http::assertNothingSent();
    }

    public function test_mispay_wrong_track_cannot_settle_or_overwrite_reference(): void
    {
        $this->gateway('mispay');
        $this->seedPayment('mispay');
        $this->postJson('/payments/mispay/webhook', ['checkoutId' => 'other', 'orderId' => 'ORD-1', 'code' => 'MP00'], ['X-Mispay-Signature' => 'test-hook'])->assertStatus(422);
        self::assertSame('requires_action', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_mispay_refund_notification_does_not_invent_refund_amount(): void
    {
        $this->gateway('mispay');
        $this->seedPayment('mispay', 'paid');
        $this->postJson('/payments/mispay/webhook', ['checkoutId' => 'remote-1', 'orderId' => 'ORD-1', 'code' => 'MP03'], ['X-Mispay-Signature' => 'test-hook'])->assertOk()->assertJson(['reconciliation_required' => true]);
        self::assertSame('paid', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_mispay_gcm_tampering_fails(): void
    {
        $value = $this->encrypted(['token' => 'abc']);
        $binary = base64_decode($value);
        $binary[30] = chr(ord($binary[30]) ^ 1);
        $this->expectException(\InvalidArgumentException::class);
        MisPayToken::decrypt(base64_encode($binary), 'test-secret');
    }

    public function test_unknown_provider_and_browser_success_have_no_payment_side_effects(): void
    {
        $this->get('/payments/clickpay/success')->assertNotFound();
        $this->postJson('/payments/unknown/webhook', [])->assertNotFound();
        self::assertSame(0, DB::table('ppsa_transactions')->count());
    }
}
