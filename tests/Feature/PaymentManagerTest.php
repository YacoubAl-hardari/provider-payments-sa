<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundRequestDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Events\PaymentSucceeded;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentStateException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\UnsupportedOperationException;
use YacoubAlhaidari\ProviderPayments\Facades\Payment;
use YacoubAlhaidari\ProviderPayments\PaymentManager;
use YacoubAlhaidari\ProviderPayments\ProviderRegistry;
use YacoubAlhaidari\ProviderPayments\Repositories\PaymentLedger;
use YacoubAlhaidari\ProviderPayments\Tests\TestCase;

final class PaymentManagerTest extends TestCase
{
    public function test_registry_factory_and_container_and_facade(): void
    {
        $original = $this->gateway('clickpay');
        $r = app(ProviderRegistry::class);
        $r->register('custom', fn ($config, $credentials) => $original);
        self::assertSame($original, app(PaymentManager::class)->gateway('custom'));
        self::assertSame(app(PaymentManager::class), Payment::manager());
        self::assertTrue(Route::has('provider-payments.webhook'));
    }

    public function test_unimplemented_refund_fails_without_http(): void
    {
        $this->gateway('clickpay');
        $this->expectException(UnsupportedOperationException::class);
        app(PaymentManager::class)->refund(new RefundRequestDTO('x', 100), 'clickpay');
    }

    public function test_creation_idempotency_rejects_changed_amount(): void
    {
        $g = $this->gateway('clickpay');
        Http::fake(['*' => Http::response(['tran_ref' => 'remote-1', 'redirect_url' => 'https://secure.clickpay.com.sa/1'])]);
        $g->createPayment($this->requestDTO());
        $this->expectException(PaymentStateException::class);
        $g->createPayment($this->requestDTO(amount: 15100));
    }

    public function test_ledger_integrity_rejects_wrong_amount_and_currency(): void
    {
        $this->seedPayment('clickpay');
        $this->expectException(PaymentVerificationException::class);
        app(PaymentLedger::class)->accept('clickpay', 'key', new PaymentResponseDTO('clickpay', S::PAID, 'remote-1', 'ORD-1', 14999, 'SAR'));
    }

    public function test_duplicate_and_stale_notifications_never_duplicate_financial_events(): void
    {
        $this->seedPayment('clickpay');
        Event::fake([PaymentSucceeded::class]);
        $ledger = app(PaymentLedger::class);
        $paid = new PaymentResponseDTO('clickpay', S::PAID, 'remote-1', 'ORD-1', 15000);
        self::assertTrue($ledger->accept('clickpay', 'paid', $paid));
        self::assertFalse($ledger->accept('clickpay', 'paid', $paid));
        self::assertFalse($ledger->accept('clickpay', 'old', new PaymentResponseDTO('clickpay', S::PENDING, 'remote-1', 'ORD-1', 15000)));
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        self::assertSame('paid', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_successful_partial_refunds_are_bounded_and_idempotent(): void
    {
        $g = $this->gateway('tabby');
        $this->seedPayment('tabby', 'captured');
        Http::fake(['*' => Http::response(['id' => 'remote-1', 'refunds' => [['id' => 'refund-1', 'reference_id' => 'r1', 'amount' => '50.00']]])]);
        $req = new RefundRequestDTO('remote-1', 5000, refundReference: 'r1');
        self::assertTrue($g->refund($req)->success);
        self::assertTrue($g->refund($req)->success);
        Http::assertSentCount(1);
        self::assertSame('partially_refunded', DB::table('ppsa_transactions')->value('status'));
        self::assertSame(5000, DB::table('ppsa_transactions')->value('refunded_amount'));
        $this->expectException(PaymentStateException::class);
        $g->refund(new RefundRequestDTO('remote-1', 10001, refundReference: 'r2'));
    }

    public function test_unknown_refund_blocks_retry_and_other_refunds(): void
    {
        $g = $this->gateway('tamara');
        $this->seedPayment('tamara', 'captured');
        Http::fake(['*' => Http::response(['unknown' => true])]);
        try {
            $g->refund(new RefundRequestDTO('remote-1', 1000, refundReference: 'r1'));
            self::fail();
        } catch (PaymentException) {
        }
        self::assertSame('reserved', DB::table('ppsa_operations')->value('state'));
        $this->expectException(PaymentStateException::class);
        $g->refund(new RefundRequestDTO('remote-1', 1000, refundReference: 'r2'));
    }

    public function test_tabby_capture_is_full_once_and_requires_authorized(): void
    {
        $g = $this->gateway('tabby');
        $this->seedPayment('tabby', 'authorized');
        Http::fake(['*' => Http::response(['id' => 'remote-1', 'status' => 'CLOSED', 'currency' => 'SAR', 'captures' => [['id' => 'capture-1', 'reference_id' => 'cap-1', 'amount' => '150.00']]])]);
        $req = new CaptureRequestDTO('remote-1', 15000, metadata: ['reference_id' => 'cap-1']);
        self::assertTrue($g->capture($req)->success);
        self::assertTrue($g->capture($req)->success);
        Http::assertSentCount(1);
        $this->expectException(PaymentStateException::class);
        $g->capture(new CaptureRequestDTO('remote-1', 15000, metadata: ['reference_id' => 'cap-2']));
    }

    public function test_tamara_refund_uses_audited_endpoint(): void
    {
        $g = $this->gateway('tamara');
        $this->seedPayment('tamara', 'captured');
        Http::fake(['*' => Http::response(['refund_id' => 'refund-1', 'order_id' => 'remote-1', 'refunded_amount' => ['amount' => '150.00', 'currency' => 'SAR']])]);
        self::assertTrue($g->refund(new RefundRequestDTO('remote-1', 15000, refundReference: 'refund-1'))->success);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payments/simplified-refund/remote-1') && $r['total_amount']['amount'] === '150.00');
        self::assertSame('refunded', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_env_sync_is_append_only_and_dry_run_does_not_create_file(): void
    {
        $directory = __DIR__.'/../runtime';
        if (! is_dir($directory)) {
            mkdir($directory);
        }$path = $directory.'/.env';
        file_put_contents($path, "CLICKPAY_SERVER_KEY=existing-test-value\n");
        $oldPath = $this->app->environmentPath();
        $this->app->useEnvironmentPath($directory);
        try {
            $this->artisan('payments:env:sync', ['--dry-run' => true])->assertExitCode(0);
            self::assertSame("CLICKPAY_SERVER_KEY=existing-test-value\n", file_get_contents($path));
            $this->artisan('payments:env:sync', ['--write' => true])->assertExitCode(0);
            $once = file_get_contents($path);
            self::assertSame(1, substr_count($once, 'CLICKPAY_SERVER_KEY='));
            self::assertStringContainsString('CLICKPAY_SERVER_KEY=existing-test-value', $once);
            $this->artisan('payments:env:sync', ['--write' => true])->assertExitCode(0);
            self::assertSame($once, file_get_contents($path));
        } finally {
            $this->app->useEnvironmentPath($oldPath);
            unlink($path);
        }
    }

    public function test_commands_run_without_provider_network(): void
    {
        $this->artisan('payments:list')->assertExitCode(0);
        $this->artisan('payments:diagnose')->assertExitCode(0);
        Http::assertNothingSent();
    }
}
