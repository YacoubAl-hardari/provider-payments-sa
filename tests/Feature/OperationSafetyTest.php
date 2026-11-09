<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use YacoubAlhaidari\ProviderPayments\DTOs\AuthorizeRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CancelRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\EligibilityRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\OrderItemDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundRequestDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentRequestException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentStateException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\ProviderDisabledException;
use YacoubAlhaidari\ProviderPayments\Exceptions\UnsupportedOperationException;
use YacoubAlhaidari\ProviderPayments\PaymentManager;
use YacoubAlhaidari\ProviderPayments\Repositories\PaymentLedger;
use YacoubAlhaidari\ProviderPayments\Tests\TestCase;

final class OperationSafetyTest extends TestCase
{
    public function test_tamara_auto_authorisation_makes_no_request(): void
    {
        $g = $this->gateway('tamara');
        Http::fake();
        try {
            $g->authorize(new AuthorizeRequestDTO('ORD-1', 15000, providerOrderId: 'remote-1'));
            self::fail();
        } catch (UnsupportedOperationException) {
            Http::assertNothingSent();
        }
    }

    public function test_tamara_manual_authorization_is_reserved_and_replay_safe(): void
    {
        $this->gateway('tamara');
        config(['provider-payments.providers.tamara.auto_authorisation' => false]);
        $this->app->forgetInstance('payment.manager');
        $g = app(PaymentManager::class)->gateway('tamara');
        $this->seedPayment('tamara');
        Http::fake(['*' => Http::response(['order_id' => 'remote-1', 'status' => 'authorised'])]);
        $r = new AuthorizeRequestDTO('ORD-1', 15000, providerOrderId: 'remote-1', metadata: ['reference_id' => 'auth-1']);
        self::assertTrue($g->authorize($r)->success);
        self::assertTrue($g->authorize($r)->success);
        Http::assertSentCount(1);
        self::assertSame('authorized', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_tamara_capture_uses_shipping_and_validates_response_identity(): void
    {
        $g = $this->gateway('tamara');
        $this->seedPayment('tamara', 'authorized');
        Http::fake(['*' => Http::response(['order_id' => 'remote-1', 'capture_id' => 'capture-1'])]);
        $r = new CaptureRequestDTO('remote-1', 15000, metadata: ['reference_id' => 'cap-1', 'items' => [['reference_id' => 'sku', 'name' => 'Item', 'quantity' => 1, 'total_amount' => ['amount' => '150.00', 'currency' => 'SAR']]], 'shipping_info' => ['shipped_at' => '2026-11-09T00:00:00Z', 'shipping_company' => 'Test carrier']]);
        self::assertTrue($g->capture($r)->success);
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/payments/capture') && $q['order_id'] === 'remote-1' && $q['total_amount']['amount'] === '150.00');
        self::assertSame('captured', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_partial_capture_not_sent(): void
    {
        $g = $this->gateway('tabby');
        $this->seedPayment('tabby', 'authorized');
        Http::fake();
        try {
            $g->capture(new CaptureRequestDTO('remote-1', 1000, metadata: ['reference_id' => 'cap-1']));
            self::fail();
        } catch (PaymentStateException) {
            Http::assertNothingSent();
        }
    }

    public function test_tamara_cancellation_is_identity_checked_and_idempotent(): void
    {
        $g = $this->gateway('tamara');
        $this->seedPayment('tamara', 'authorized');
        Http::fake(['*' => Http::response(['order_id' => 'remote-1', 'cancel_id' => 'cancel-1'])]);
        $r = new CancelRequestDTO('remote-1', 15000, reference: 'c1');
        self::assertTrue($g->cancel($r)->success);
        self::assertTrue($g->cancel($r)->success);
        Http::assertSentCount(1);
        self::assertSame('cancelled', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_tabby_zero_capture_close_is_cancelled_not_paid(): void
    {
        $g = $this->gateway('tabby');
        Http::fake(['*' => Http::response(['id' => 'remote-1', 'status' => 'CLOSED', 'amount' => '150.00', 'currency' => 'SAR', 'order' => ['reference_id' => 'ORD-1'], 'captures' => []])]);
        self::assertSame(S::CANCELLED, $g->getPaymentStatus('remote-1')->status);
    }

    public function test_tabby_closed_requires_full_capture_for_paid(): void
    {
        $g = $this->gateway('tabby');
        Http::fake(['*' => Http::response(['id' => 'remote-1', 'status' => 'CLOSED', 'amount' => '150.00', 'currency' => 'SAR', 'order' => ['reference_id' => 'ORD-1'], 'captures' => [['amount' => '150.00']]])]);
        self::assertSame(S::CAPTURED, $g->getPaymentStatus('remote-1')->status);
    }

    public function test_tabby_cancel_queries_before_close(): void
    {
        $g = $this->gateway('tabby');
        $this->seedPayment('tabby', 'authorized');
        Http::fakeSequence()->push(['id' => 'remote-1', 'status' => 'AUTHORIZED', 'captures' => []])->push(['id' => 'remote-1', 'status' => 'CLOSED', 'captures' => []]);
        self::assertTrue($g->cancel(new CancelRequestDTO('remote-1', 15000, reference: 'c1'))->success);
        Http::assertSentCount(2);
        self::assertSame('cancelled', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_capture_already_recorded_remotely_cannot_be_cancelled(): void
    {
        $g = $this->gateway('tabby');
        $this->seedPayment('tabby', 'authorized');
        Http::fake(['*' => Http::response(['id' => 'remote-1', 'captures' => [['amount' => '150.00']]])]);
        try {
            $g->cancel(new CancelRequestDTO('remote-1', 15000, reference: 'c1'));
            self::fail();
        } catch (PaymentVerificationException) {
            Http::assertSentCount(1);
        }
    }

    public function test_eligibility_failure_only_affects_display(): void
    {
        $g = $this->gateway('tamara');
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        self::assertTrue($g->checkEligibility(new EligibilityRequestDTO($this->requestDTO()->customer, 15000)));
        self::assertSame(0, DB::table('ppsa_transactions')->count());
    }

    public function test_eligibility_rejection_respected(): void
    {
        $g = $this->gateway('tamara');
        Http::fake(['*' => Http::response(['is_eligible' => false])]);
        self::assertFalse($g->checkEligibility(new EligibilityRequestDTO($this->requestDTO()->customer, 15000)));
    }

    public function test_cumulative_partial_refunds_advance_without_changing_state(): void
    {
        $this->seedPayment('tabby', 'partially_refunded', 1000);
        $ledger = app(PaymentLedger::class);
        $r = new PaymentResponseDTO('tabby', S::PARTIALLY_REFUNDED, 'remote-1', 'ORD-1', 15000, metadata: ['refunded_amount' => 2000]);
        self::assertTrue($ledger->accept('tabby', 'refund2', $r));
        self::assertSame(2000, DB::table('ppsa_transactions')->value('refunded_amount'));
    }

    public function test_persistence_free_mode_does_not_use_database(): void
    {
        config(['provider-payments.persistence.enabled' => false]);
        $g = $this->gateway('clickpay');
        Http::fake(['*' => Http::response(['tran_ref' => 'remote-1', 'redirect_url' => 'https://secure.clickpay.com.sa/1'])]);
        $r = $g->createPayment($this->requestDTO());
        self::assertSame('remote-1', $r->providerTransactionId);
        self::assertSame(0, DB::table('ppsa_transactions')->count());
    }

    public function test_global_disable_overrides_provider_flag(): void
    {
        config(['provider-payments.enabled' => false]);
        $g = $this->gateway('clickpay');
        Http::fake();
        $this->expectException(ProviderDisabledException::class);
        $g->createPayment($this->requestDTO());
    }

    public function test_unknown_refund_total_does_not_invent_full_refund(): void
    {
        $this->seedPayment('tamara', 'captured');
        $ledger = app(PaymentLedger::class);
        $ledger->accept('tamara', 'partial-unknown', new PaymentResponseDTO('tamara', S::PARTIALLY_REFUNDED, 'remote-1', 'ORD-1', 15000));
        self::assertSame(0, DB::table('ppsa_transactions')->value('refunded_amount'));
        self::assertSame(0, DB::table('ppsa_transactions')->value('refund_balance_known'));
        $g = $this->gateway('tamara');
        Http::fake();
        $this->expectException(PaymentStateException::class);
        $g->refund(new RefundRequestDTO('remote-1', 100, refundReference: 'r1'));
    }

    public function test_verified_return_updates_ledger_with_exact_identity(): void
    {
        $this->gateway('clickpay');
        $this->seedPayment('clickpay');
        Http::fake(['*' => Http::response(['tran_ref' => 'remote-1', 'profile_id' => '123', 'cart_id' => 'ORD-1', 'cart_currency' => 'SAR', 'cart_amount' => '150.00', 'tran_type' => 'sale', 'payment_result' => ['response_status' => 'A']])]);
        $r = app(PaymentManager::class)->verifyPayment('remote-1', 'clickpay');
        self::assertSame(S::PAID, $r->status);
        self::assertSame('paid', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_bnpl_mismatched_items_rejected_before_reserving_or_network(): void
    {
        $g = $this->gateway('tabby');
        $r = new PaymentRequestDTO('ORD-1', 15000, customer: $this->requestDTO()->customer, successUrl: 'https://merchant.example/success', cancelUrl: 'https://merchant.example/cancel', items: [new OrderItemDTO('Item', 100)]);
        Http::fake();
        try {
            $g->createPayment($r);
            self::fail();
        } catch (\InvalidArgumentException) {
            Http::assertNothingSent();
            self::assertSame(0, DB::table('ppsa_transactions')->count());
        }
    }

    public function test_tabby_capture_cannot_succeed_from_closed_without_capture_evidence(): void
    {
        $g = $this->gateway('tabby');
        $this->seedPayment('tabby', 'authorized');
        Http::fake(['*' => Http::response(['id' => 'remote-1', 'status' => 'CLOSED', 'currency' => 'SAR', 'captures' => []])]);
        try {
            $g->capture(new CaptureRequestDTO('remote-1', 15000, metadata: ['reference_id' => 'cap-1']));
            self::fail();
        } catch (PaymentRequestException) {
            self::assertSame('authorized', DB::table('ppsa_transactions')->value('status'));
            self::assertSame('reserved', DB::table('ppsa_operations')->value('state'));
        }
    }
}
