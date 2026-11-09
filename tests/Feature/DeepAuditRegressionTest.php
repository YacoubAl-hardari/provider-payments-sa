<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CustomerDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\EligibilityRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundRequestDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Events\PaymentPartiallyRefunded;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\ProviderDisabledException;
use YacoubAlhaidari\ProviderPayments\PaymentManager;
use YacoubAlhaidari\ProviderPayments\Repositories\PaymentLedger;
use YacoubAlhaidari\ProviderPayments\Support\ExactJson;
use YacoubAlhaidari\ProviderPayments\Tests\TestCase;

final class DeepAuditRegressionTest extends TestCase
{
    public function test_verified_api_returns_accepted_local_state_when_remote_transition_is_stale(): void
    {
        $this->gateway('clickpay');
        $this->seedPayment('clickpay', 'refunded', 15000);
        Http::fake(['*' => Http::response(['tran_ref' => 'remote-1', 'cart_id' => 'ORD-1', 'cart_currency' => 'SAR', 'cart_amount' => '150.00', 'tran_type' => 'sale', 'payment_result' => ['response_status' => 'A']])]);
        $result = app(PaymentManager::class)->verifyPayment('remote-1', 'clickpay');
        self::assertSame(S::REFUNDED, $result->status);
        self::assertTrue($result->metadata['reconciliation_required']);
    }

    public function test_partial_refund_snapshot_without_total_invalidates_previously_known_balance(): void
    {
        $this->seedPayment('tamara', 'partially_refunded', 1000);
        $ledger = app(PaymentLedger::class);
        $ledger->accept('tamara', 'external-partial', new PaymentResponseDTO('tamara', S::PARTIALLY_REFUNDED, 'remote-1', 'ORD-1', 15000));
        self::assertSame(0, DB::table('ppsa_transactions')->value('refund_balance_known'));
    }

    public function test_each_new_partial_refund_emits_event_even_without_status_change(): void
    {
        $g = $this->gateway('tamara');
        $this->seedPayment('tamara', 'captured');
        Event::fake([PaymentPartiallyRefunded::class]);
        Http::fakeSequence()->push(['order_id' => 'remote-1', 'refund_id' => 'r1', 'refunded_amount' => ['amount' => '10.00', 'currency' => 'SAR']])->push(['order_id' => 'remote-1', 'refund_id' => 'r2', 'refunded_amount' => ['amount' => '20.00', 'currency' => 'SAR']]);
        $g->refund(new RefundRequestDTO('remote-1', 1000, refundReference: 'r1'));
        $g->refund(new RefundRequestDTO('remote-1', 2000, refundReference: 'r2'));
        Event::assertDispatchedTimes(PaymentPartiallyRefunded::class, 2);
    }

    public function test_telr_false_text_mode_is_not_treated_as_true(): void
    {
        $g = $this->gateway('telr');
        Http::fake(['*' => Http::response(['order' => ['ref' => 'remote-1', 'cartid' => 'ORD-1', 'currency' => 'SAR', 'amount' => '150.00', 'test' => 'false', 'status' => ['code' => 3]]])]);
        $this->expectException(PaymentVerificationException::class);
        $g->getPaymentStatus('remote-1');
    }

    public function test_telr_postal_field_matches_order_api_areacode_contract(): void
    {
        $g = $this->gateway('telr');
        Http::fake(['*' => Http::response(['order' => ['ref' => 'remote-1', 'url' => 'https://secure.telr.com/checkout/1']])]);
        $g->createPayment($this->requestDTO());
        Http::assertSent(fn ($r) => data_get($r->data(), 'customer.address.areacode') === '12345' && ! isset($r['customer']['address']['zip']));
    }

    public function test_mispay_normalizes_local_mobile_like_source(): void
    {
        $g = $this->gateway('mispay');
        Http::fake(fn ($r) => str_ends_with($r->url(), '/token') ? Http::response(['result' => ['token' => $this->encrypted(['token' => 'jwt'])]]) : Http::response(['result' => ['trackId' => 'remote-1', 'url' => 'https://checkout.mispay.co/1']]));
        $base = $this->requestDTO();
        $customer = new CustomerDTO('Test Customer', 'test@example.com', '0500000000', 'Street', 'Riyadh');
        $r = new PaymentRequestDTO('ORD-1', 15000, customer: $customer, successUrl: $base->successUrl, cancelUrl: $base->cancelUrl, items: $base->items);
        $g->createPayment($r);
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/start-checkout') && data_get($q->data(), 'customerDetails.mobileNumber') === '+966500000000');
    }

    public function test_tamara_failed_status_is_not_pending(): void
    {
        $g = $this->gateway('tamara');
        Http::fake(['*' => Http::response(['order_id' => 'remote-1', 'order_reference_id' => 'ORD-1', 'total_amount' => ['amount' => '150.00', 'currency' => 'SAR'], 'status' => 'failed'])]);
        self::assertSame(S::FAILED, $g->getPaymentStatus('remote-1')->status);
    }

    public function test_tamara_no_ledger_webhook_does_not_query_provider(): void
    {
        $this->gateway('tamara');
        config(['provider-payments.persistence.enabled' => false]);
        Http::fake();
        $this->postJson('/payments/tamara/webhook', ['order_id' => 'remote-1'], ['Authorization' => 'Bearer '.$this->jwt(['exp' => time() + 100])])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_exact_decimal_provider_json_is_not_rounded_through_float(): void
    {
        $g = $this->gateway('clickpay');
        Http::fake(['*' => Http::response('{"tran_ref":"remote-1","cart_id":"ORD-1","cart_currency":"SAR","cart_amount":90071992547409.93,"tran_type":"sale","payment_result":{"response_status":"A"}}', 200, ['Content-Type' => 'application/json'])]);
        self::assertSame(9007199254740993, $g->getPaymentStatus('remote-1')->amount);
    }

    public function test_empty_env_url_defaults_do_not_replace_optional_nulls(): void
    {
        $r = $this->requestDTO();
        $resolved = $r->withUrlDefaults(['successUrl' => '', 'webhookUrl' => '']);
        self::assertSame($r->successUrl, $resolved->successUrl);
        $minimal = new PaymentRequestDTO('x', 1, successUrl: 'https://merchant.example/success', cancelUrl: 'https://merchant.example/cancel');
        self::assertNull($minimal->withUrlDefaults(['webhookUrl' => ''])->webhookUrl);
    }

    public function test_eligibility_false_is_cached_and_failure_cooldown_avoids_repeated_network(): void
    {
        $g = $this->gateway('tamara');
        Http::fake(['*' => Http::response(['is_eligible' => false])]);
        $r = new EligibilityRequestDTO($this->requestDTO()->customer, 15000);
        self::assertFalse($g->checkEligibility($r));
        self::assertFalse($g->checkEligibility($r));
        Http::assertSentCount(1);
    }

    public function test_eligibility_disabled_makes_no_remote_calls(): void
    {
        $this->gateway('tamara');
        config(['provider-payments.providers.tamara.precheck_enabled' => false]);
        $this->app->forgetInstance('payment.manager');
        Http::fake();
        self::assertTrue(app(PaymentManager::class)->gateway('tamara')->checkEligibility(new EligibilityRequestDTO($this->requestDTO()->customer, 15000)));
        Http::assertNothingSent();
    }

    public function test_exact_json_preserves_numeric_strings_and_escaped_text(): void
    {
        $r = ExactJson::decode('{"amount":0.10,"label":"a 1.23 \\"quoted\\"","integer":123,"text":"1.23"}');
        self::assertSame('0.10', $r['amount']);
        self::assertSame(123, $r['integer']);
        self::assertSame('1.23', $r['text']);
        self::assertStringContainsString('1.23', $r['label']);
    }

    public function test_provider_config_cannot_bypass_global_disable(): void
    {
        config(['provider-payments.enabled' => false, 'provider-payments.providers.clickpay.payments_enabled' => true]);
        $g = $this->gateway('clickpay');
        $this->expectException(ProviderDisabledException::class);
        $g->createPayment($this->requestDTO());
    }

    public function test_invalid_capture_input_does_not_poison_operation_reference(): void
    {
        $g = $this->gateway('tamara');
        $this->seedPayment('tamara', 'authorized');
        Http::fake();
        try {
            $g->capture(new CaptureRequestDTO('remote-1', 15000, metadata: ['reference_id' => 'cap-1']));
            self::fail();
        } catch (\InvalidArgumentException) {
            self::assertSame(0, DB::table('ppsa_operations')->count());
            Http::assertNothingSent();
        }
    }

    public function test_remote_capture_sum_cannot_exceed_authorized_payment(): void
    {
        $g = $this->gateway('tabby');
        Http::fake(['*' => Http::response(['id' => 'remote-1', 'currency' => 'SAR', 'amount' => '150.00', 'status' => 'CLOSED', 'order' => ['reference_id' => 'ORD-1'], 'captures' => [['amount' => '150.00'], ['amount' => '1.00']]])]);
        $this->expectException(PaymentVerificationException::class);
        $g->getPaymentStatus('remote-1');
    }

    public function test_tabby_production_buyer_does_not_require_test_email_or_fabricate_history(): void
    {
        $g = $this->gateway('tabby');
        $base = $this->requestDTO();
        $c = new CustomerDTO('Customer', phone: '+966500000000', street: 'Street', city: 'Riyadh');
        Http::fake(['*' => Http::response(['id' => 'session-1', 'payment' => ['id' => 'remote-1'], 'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/1']]]]])]);
        $g->createPayment(new PaymentRequestDTO('ORD-1', 15000, customer: $c, successUrl: $base->successUrl, cancelUrl: $base->cancelUrl, items: $base->items));
        Http::assertSent(fn ($r) => ! array_key_exists('email', $r['payment']['buyer']) && ! array_key_exists('buyer_history', $r['payment']));
    }

    public function test_capture_adjustment_fields_are_not_silently_zeroed(): void
    {
        $g = $this->gateway('tabby');
        $this->seedPayment('tabby', 'authorized');
        Http::fake(['*' => Http::response(['id' => 'remote-1', 'status' => 'CLOSED', 'currency' => 'SAR', 'captures' => [['id' => 'cap-id', 'reference_id' => 'cap-1', 'amount' => '150.00']]])]);
        $g->capture(new CaptureRequestDTO('remote-1', 15000, metadata: ['reference_id' => 'cap-1'], shippingAmount: 1000, taxAmount: 500, discountAmount: 500));
        Http::assertSent(fn ($r) => $r['shipping_amount'] === '10.00' && $r['tax_amount'] === '5.00' && $r['discount_amount'] === '5.00');
    }

    public function test_clickpay_profile_id_has_source_integer_json_type(): void
    {
        $g = $this->gateway('clickpay');
        Http::fake(['*' => Http::response(['tran_ref' => 'remote-1', 'redirect_url' => 'https://secure.clickpay.com.sa/1'])]);
        $g->createPayment($this->requestDTO());
        Http::assertSent(fn ($r) => $r['profile_id'] === 123);
    }

    public function test_tabby_capture_preserves_source_null_cleanup_and_required_item_defaults(): void
    {
        $g = $this->gateway('tabby');
        $this->seedPayment('tabby', 'authorized');
        Http::fake(['*' => Http::response(['id' => 'remote-1', 'status' => 'CLOSED', 'currency' => 'SAR', 'captures' => [['id' => 'cap-id', 'reference_id' => 'cap-1', 'amount' => '150.00']]])]);
        $g->capture(new CaptureRequestDTO('remote-1', 15000, metadata: ['reference_id' => 'cap-1', 'items' => [['product_name' => 'Item', 'quantity' => 1, 'unit_price' => '150.00', 'image_url' => null, 'reference_id' => null]]]));
        Http::assertSent(fn ($r) => $r['items'][0]['title'] === 'Item' && $r['items'][0]['reference_id'] === 'item-1' && $r['items'][0]['discount_amount'] === '0.00' && ! array_key_exists('image_url', $r['items'][0]));
    }
}
