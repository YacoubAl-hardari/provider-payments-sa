<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use YacoubAlhaidari\ProviderPayments\Contracts\RefundsPayments;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundRequestDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Events\PaymentSucceeded;
use YacoubAlhaidari\ProviderPayments\Exceptions\ConfigurationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentStateException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\UnsupportedOperationException;
use YacoubAlhaidari\ProviderPayments\PaymentManager;
use YacoubAlhaidari\ProviderPayments\ProviderPaymentsServiceProvider;
use YacoubAlhaidari\ProviderPayments\Tests\TestCase;

final class BankGatewayTest extends TestCase
{
    public static function banks(): array
    {
        return [['alinmapay'], ['alrajhi']];
    }

    private function bankEncrypt(array $data, bool $query = false): string
    {
        $plain = $query ? http_build_query($data) : json_encode([$data], JSON_THROW_ON_ERROR);

        return strtoupper(bin2hex(openssl_encrypt($plain, 'aes-256-cbc', '01234567890123456789012345678901', OPENSSL_RAW_DATA, 'PGKEYENCDECIVSPC')));
    }

    private function bankDecrypt(string $cipher): array
    {
        return json_decode(openssl_decrypt(hex2bin($cipher), 'aes-256-cbc', '01234567890123456789012345678901', OPENSSL_RAW_DATA, 'PGKEYENCDECIVSPC'), true, 512, JSON_THROW_ON_ERROR)[0];
    }

    private function creation(string $bank): array
    {
        return $bank === 'alinmapay'
            ? ['transactionId' => 'remote-1', 'responseCode' => '001', 'paymentLink' => ['linkUrl' => 'https://pg.alinmapay.com.sa/pay?paymentId=&lang=ar']]
            : [['status' => '1', 'result' => 'remote-1:https://securepayments.alrajhibank.com.sa/pg/paymentpage.htm?language=ar']];
    }

    private function snapshot(string $bank, string $status = 'paid'): array
    {
        return $bank === 'alinmapay'
            ? ['transactionId' => 'remote-1', 'orderId' => 'ORD-1', 'amount' => '150.00', 'currency' => 'SAR', 'result' => $status === 'paid' ? 'SUCCESS' : strtoupper($status), 'responseCode' => '000', 'paymentType' => '1']
            : ['paymentId' => 'remote-1', 'trackId' => 'ORD-1', 'amt' => '150.00', 'currencyCode' => '682', 'result' => $status === 'paid' ? 'CAPTURED' : strtoupper($status), 'transId' => 'bank-transaction-1'];
    }

    private function signature(array $data): string
    {
        return hash('sha256', implode('|', [$data['transactionId'], str_repeat('ab', 32), $data['responseCode'], $data['amount']]));
    }

    private function sendBankCallback(string $bank, ?array $data = null)
    {
        $data ??= $this->snapshot($bank);

        return $bank === 'alinmapay'
            ? $this->postJson('/payments/alinmapay/webhook', $data, ['X-AlinmaPay-Signature' => $this->signature($data)])
            : $this->post('/payments/alrajhi/webhook', ['trandata' => $this->bankEncrypt($data)]);
    }

    #[DataProvider('banks')]
    public function test_checkout_wire_format_exact_money_and_durable_idempotency(string $bank): void
    {
        $gateway = $this->gateway($bank);
        Http::fake(['*' => Http::response($this->creation($bank))]);
        $result = $gateway->createPayment($this->requestDTO());
        self::assertSame(S::REQUIRES_ACTION, $result->status);
        self::assertSame('remote-1', $result->providerTransactionId);
        self::assertStringContainsString($bank === 'alinmapay' ? 'paymentId=remote-1' : 'PaymentID=remote-1', $result->checkoutUrl);
        self::assertSame($result->checkoutUrl, $gateway->createPayment($this->requestDTO())->checkoutUrl);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($bank) {
            if ($bank === 'alinmapay') {
                $expected = hash('sha256', 'ORD-1|test-terminal|test-password|'.str_repeat('ab', 32).'|150.00|SAR');

                return $request->url() === 'https://pg.alinmapay.com.sa/SB_Transactions/v2/payments/pay-request'
                    && $request['signature'] === $expected && $request['amount'] === '150.00'
                    && $request['paymentType'] === '1'
                    && json_decode($request['additionalDetails']['userData'], true)['receiptUrl'] === 'https://merchant.example/success';
            }
            $data = $this->bankDecrypt($request[0]['trandata']);

            return $request->url() === 'https://securepayments.alrajhibank.com.sa/pg/payment/hosted.htm'
                && $request->hasHeader('X-FORWARDED-FOR', '192.0.2.10')
                && $data['amt'] === '150.00' && $data['currencyCode'] === '682'
                && $data['action'] === '1' && $data['trackId'] === 'ORD-1'
                && $data['password'] === 'test-password'
                && $request[0]['responseURL'] === $data['responseURL'];
        });
    }

    #[DataProvider('banks')]
    public function test_disabled_bank_does_not_contact_bank(string $bank): void
    {
        $gateway = $this->gateway($bank, false);
        try {
            $gateway->createPayment($this->requestDTO());
            self::fail('Expected disabled gateway.');
        } catch (PaymentException) {
            Http::assertNothingSent();
            self::assertSame(0, DB::table('ppsa_transactions')->count());
        }
    }

    #[DataProvider('banks')]
    public function test_currency_rejected_before_reserving_checkout(string $bank): void
    {
        $gateway = $this->gateway($bank);
        try {
            $gateway->createPayment(new PaymentRequestDTO('USD-1', 15000, 'USD', successUrl: 'https://merchant.example/return', cancelUrl: 'https://merchant.example/cancel'));
            self::fail();
        } catch (\InvalidArgumentException) {
            Http::assertNothingSent();
            self::assertSame(0, DB::table('ppsa_transactions')->count());
        }
    }

    #[DataProvider('banks')]
    public function test_timeout_retains_reservation_and_never_reissues_sale(string $bank): void
    {
        $gateway = $this->gateway($bank);
        Http::fake(fn () => throw new ConnectionException('timeout'));
        try {
            $gateway->createPayment($this->requestDTO());
            self::fail();
        } catch (PaymentException) {
            self::assertSame('reserved', DB::table('ppsa_transactions')->value('creation_state'));
        }
        $this->expectException(PaymentStateException::class);
        $gateway->createPayment($this->requestDTO());
    }

    #[DataProvider('banks')]
    public function test_notification_queries_saved_identity_and_deduplicates_success(string $bank): void
    {
        $this->gateway($bank);
        $this->seedPayment($bank);
        Event::fake([PaymentSucceeded::class]);
        $remote = $this->snapshot($bank);
        Http::fake(['*' => Http::response($bank === 'alrajhi' ? [['trandata' => $this->bankEncrypt($remote)]] : $remote)]);
        $this->sendBankCallback($bank)->assertOk()->assertJson(['accepted' => true]);
        $this->sendBankCallback($bank)->assertOk()->assertJson(['accepted' => false]);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        self::assertSame($bank === 'alrajhi' ? 'captured' : 'paid', DB::table('ppsa_transactions')->value('status'));
        Http::assertSent(function ($request) use ($bank) {
            if ($bank === 'alinmapay') {
                return $request['referenceId'] === 'remote-1' && $request['paymentType'] === '10'
                    && $request['amount'] === '150.00' && $request['order']['orderId'] === 'ORD-1';
            }
            $payload = $this->bankDecrypt($request[0]['trandata']);

            return $payload['action'] === '8' && $payload['transId'] === 'remote-1' && $payload['udf5'] === 'PaymentID';
        });
    }

    #[DataProvider('banks')]
    public function test_claimed_callback_success_does_not_override_pending_inquiry(string $bank): void
    {
        $this->gateway($bank);
        $this->seedPayment($bank);
        Http::fake(['*' => Http::response($this->snapshot($bank, 'pending'))]);
        $this->sendBankCallback($bank)->assertOk();
        self::assertSame('requires_action', DB::table('ppsa_transactions')->value('status'));
    }

    #[DataProvider('banks')]
    public function test_unknown_order_rejected_without_provider_request(string $bank): void
    {
        $this->gateway($bank);
        $this->sendBankCallback($bank)->assertStatus(422);
        Http::assertNothingSent();
    }

    #[DataProvider('banks')]
    public function test_changed_callback_amount_rejected_before_inquiry(string $bank): void
    {
        $this->gateway($bank);
        $this->seedPayment($bank);
        $data = $this->snapshot($bank);
        $data[$bank === 'alrajhi' ? 'amt' : 'amount'] = '0.01';
        $this->sendBankCallback($bank, $data)->assertStatus(422);
        Http::assertNothingSent();
        self::assertSame('requires_action', DB::table('ppsa_transactions')->value('status'));
    }

    public static function identityMismatches(): array
    {
        return [
            ['alinmapay', 'transactionId', 'other'],
            ['alinmapay', 'orderId', 'other'],
            ['alinmapay', 'currency', 'USD'],
            ['alinmapay', 'amount', '149.99'],
            ['alrajhi', 'paymentId', 'other'],
            ['alrajhi', 'trackId', 'other'],
            ['alrajhi', 'currencyCode', '840'],
            ['alrajhi', 'amt', '149.99'],
        ];
    }

    #[DataProvider('identityMismatches')]
    public function test_inquiry_integrity_is_checked_even_over_successful_https(string $bank, string $field, string $value): void
    {
        $gateway = $this->gateway($bank);
        $this->seedPayment($bank);
        $remote = $this->snapshot($bank);
        $remote[$field] = $value;
        Http::fake(['*' => Http::response($remote)]);
        $this->expectException(PaymentVerificationException::class);
        $gateway->getPaymentStatus('remote-1');
    }

    public function test_plain_alrajhi_callback_and_unsigned_alinma_callback_are_rejected(): void
    {
        $this->gateway('alrajhi');
        $this->postJson('/payments/alrajhi/webhook', $this->snapshot('alrajhi'))->assertUnauthorized();
        $this->gateway('alinmapay');
        $this->postJson('/payments/alinmapay/webhook', $this->snapshot('alinmapay'))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_alinma_encrypted_receipt_uses_source_aes128_hex_key_format_and_still_queries(): void
    {
        $this->gateway('alinmapay');
        $this->seedPayment('alinmapay');
        $remote = $this->snapshot('alinmapay');
        $cipher = openssl_encrypt(json_encode($remote), 'aes-128-ecb', str_repeat(chr(0xAB), 16), OPENSSL_RAW_DATA);
        Http::fake(['*' => Http::response($remote)]);
        $this->post('/payments/alinmapay/webhook', ['data' => base64_encode($cipher)])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_alrajhi_encrypted_query_string_response_is_supported(): void
    {
        $gateway = $this->gateway('alrajhi');
        $this->seedPayment('alrajhi');
        Http::fake(['*' => Http::response([['trandata' => $this->bankEncrypt($this->snapshot('alrajhi'), true)]])]);
        self::assertSame(S::CAPTURED, $gateway->getPaymentStatus('remote-1')->status);
    }

    public function test_refund_is_bounded_confirmed_and_cached(): void
    {
        $bank = 'alinmapay';
        $gateway = $this->gateway($bank);
        $this->seedPayment($bank, 'captured');
        Http::fake(function ($request) {
            self::assertSame('2', $request['paymentType']);
            self::assertSame('remote-1', $request['referenceId']);
            self::assertSame('50.00', $request['amount']);

            return Http::response(['transactionId' => 'refund-1', 'orderId' => 'REFUND', 'referenceId' => 'remote-1', 'amount' => '50.00', 'currency' => 'SAR', 'result' => 'SUCCESS', 'responseCode' => '000']);
        });
        $request = new RefundRequestDTO('remote-1', 5000, refundReference: 'return-1');
        self::assertTrue($gateway->refund($request)->success);
        self::assertSame('refund-1', $gateway->refund($request)->refundId);
        Http::assertSentCount(1);
        self::assertSame(5000, DB::table('ppsa_transactions')->value('refunded_amount'));
        self::assertSame('partially_refunded', DB::table('ppsa_transactions')->value('status'));
        $this->expectException(PaymentStateException::class);
        $gateway->refund(new RefundRequestDTO('remote-1', 10001, refundReference: 'return-2'));
    }

    public function test_alinma_full_authorized_capture_is_confirmed_once(): void
    {
        $gateway = $this->gateway('alinmapay');
        $this->seedPayment('alinmapay', 'authorized');
        Http::fake(function ($request) {
            self::assertSame('5', $request['paymentType']);
            self::assertSame('remote-1', $request['referenceId']);

            return Http::response(['transactionId' => 'operation-1', 'orderId' => 'CAPTURE', 'amount' => '150.00', 'currency' => 'SAR', 'result' => 'SUCCESS', 'responseCode' => '000']);
        });
        $request = new CaptureRequestDTO('remote-1', 15000, metadata: ['reference_id' => 'capture-1']);
        self::assertTrue($gateway->capture($request)->success);
        self::assertTrue($gateway->capture($request)->success);
        Http::assertSentCount(1);
        self::assertSame('captured', DB::table('ppsa_transactions')->value('status'));
    }

    public function test_unimplemented_alrajhi_money_operations_fail_before_http(): void
    {
        $gateway = $this->gateway('alrajhi');
        self::assertNotInstanceOf(RefundsPayments::class, $gateway);
        self::assertFalse($gateway->supports('capture'));
        self::assertFalse($gateway->supports('cancel'));
        try {
            app(PaymentManager::class)->refund(new RefundRequestDTO('remote-1', 100, refundReference: 'r1'), 'alrajhi');
            self::fail('Unsupported operation must fail.');
        } catch (UnsupportedOperationException) {
            Http::assertNothingSent();
        }
    }

    #[DataProvider('banks')]
    public function test_bank_checkout_requires_persistence_before_contacting_provider(string $bank): void
    {
        $this->gateway($bank);
        config(['provider-payments.persistence.enabled' => false]);
        $this->app->forgetInstance('payment.manager');
        try {
            app(PaymentManager::class)->gateway($bank)->createPayment($this->requestDTO());
            self::fail('Bank verification needs saved identity.');
        } catch (ConfigurationException) {
            Http::assertNothingSent();
        }
    }

    public function test_ambiguous_refund_retains_operation_and_blocks_new_reference(): void
    {
        $bank = 'alinmapay';
        $gateway = $this->gateway($bank);
        $this->seedPayment($bank, 'captured');
        Http::fake(['*' => Http::response(['status' => '1'])]);
        try {
            $gateway->refund(new RefundRequestDTO('remote-1', 5000, refundReference: 'r1'));
            self::fail();
        } catch (PaymentException) {
            self::assertSame('reserved', DB::table('ppsa_operations')->value('state'));
        }
        $this->expectException(PaymentStateException::class);
        $gateway->refund(new RefundRequestDTO('remote-1', 5000, refundReference: 'r2'));
    }

    public function test_bank_configuration_is_additive_and_routes_use_existing_names(): void
    {
        self::assertFalse(config('provider-payments.providers.alinmapay.enabled'));
        self::assertFalse(config('provider-payments.providers.alrajhi.enabled'));
        self::assertSame('clickpay', config('provider-payments.default'));
        self::assertSame('/payments/alrajhi/webhook', route('provider-payments.webhook', ['provider' => 'alrajhi'], false));
        $this->gateway('alinmapay');
        $this->gateway('alrajhi');
        self::assertTrue(app(PaymentManager::class)->gateway('alinmapay')->isConfigured());
        self::assertFalse(app(PaymentManager::class)->gateway('alrajhi')->supports('refund'));
        self::assertFalse(app(PaymentManager::class)->gateway('alinmapay')->supports('cancel'));
    }

    public function test_older_published_configuration_gets_missing_banks_without_replacing_overrides(): void
    {
        $clickpay = config('provider-payments.providers.clickpay');
        $clickpay['enabled'] = true;
        $clickpay['credentials']['server_key'] = 'consumer-owned-value';
        config(['provider-payments.providers' => ['clickpay' => $clickpay], 'provider-payments.default' => 'clickpay']);
        (new ProviderPaymentsServiceProvider($this->app))->register();
        self::assertSame($clickpay, config('provider-payments.providers.clickpay'));
        self::assertFalse(config('provider-payments.providers.alinmapay.enabled'));
        self::assertFalse(config('provider-payments.providers.alrajhi.enabled'));
        self::assertSame('clickpay', config('provider-payments.default'));
    }

    #[DataProvider('banks')]
    public function test_checkout_rejects_an_untrusted_redirect_host(string $bank): void
    {
        $gateway = $this->gateway($bank);
        $data = $bank === 'alinmapay'
            ? ['transactionId' => 'remote-1', 'redirectUrl' => 'https://attacker.example/pay']
            : [['status' => '1', 'result' => 'remote-1:https://attacker.example/pay']];
        Http::fake(['*' => Http::response($data)]);
        $this->expectException(PaymentException::class);
        $gateway->createPayment($this->requestDTO());
    }

    public function test_alinma_signature_tampering_is_rejected_before_query(): void
    {
        $this->gateway('alinmapay');
        $this->seedPayment('alinmapay');
        $data = $this->snapshot('alinmapay');
        $signature = $this->signature($data);
        $data['amount'] = '149.99';
        $this->postJson('/payments/alinmapay/webhook', $data, ['X-AlinmaPay-Signature' => $signature])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_alinma_generic_success_without_payment_type_is_not_assumed_paid(): void
    {
        $gateway = $this->gateway('alinmapay');
        $this->seedPayment('alinmapay');
        $data = $this->snapshot('alinmapay');
        unset($data['paymentType']);
        Http::fake(['*' => Http::response($data)]);
        $this->expectException(PaymentVerificationException::class);
        $gateway->getPaymentStatus('remote-1');
    }

    public function test_alinma_preauthorization_is_not_fulfillment_even_if_current_mode_is_sale(): void
    {
        $gateway = $this->gateway('alinmapay');
        $this->seedPayment('alinmapay');
        $data = $this->snapshot('alinmapay');
        $data['paymentType'] = '4';
        Http::fake(['*' => Http::response($data)]);
        self::assertSame(S::AUTHORIZED, $gateway->getPaymentStatus('remote-1')->status);
    }

    public function test_alrajhi_bad_ciphertext_is_rejected_without_query(): void
    {
        $this->gateway('alrajhi');
        $this->post('/payments/alrajhi/webhook', ['trandata' => 'not-hex'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_alrajhi_wrapper_status_is_not_financial_proof(): void
    {
        $gateway = $this->gateway('alrajhi');
        $this->seedPayment('alrajhi');
        $data = $this->snapshot('alrajhi');
        $data['result'] = '1';
        Http::fake(['*' => Http::response($data)]);
        $this->expectException(PaymentVerificationException::class);
        $gateway->getPaymentStatus('remote-1');
    }

    #[DataProvider('banks')]
    public function test_historical_callback_can_reconcile_when_new_checkout_is_disabled(string $bank): void
    {
        $this->gateway($bank, false);
        $this->seedPayment($bank);
        Http::fake(['*' => Http::response($this->snapshot($bank))]);
        $this->sendBankCallback($bank)->assertOk();
        self::assertContains(DB::table('ppsa_transactions')->value('status'), ['paid', 'captured']);
    }

    public function test_alinma_production_payment_link_uses_the_selected_environment(): void
    {
        $this->gateway('alinmapay');
        config(['provider-payments.providers.alinmapay.environment' => 'production', 'provider-payments.providers.alinmapay.payment_type' => '15']);
        $this->app->forgetInstance('payment.manager');
        Http::fake(['*' => Http::response($this->creation('alinmapay'))]);
        app(PaymentManager::class)->gateway('alinmapay')->createPayment($this->requestDTO());
        Http::assertSent(fn ($request) => $request->url() === 'https://pg.alinmapay.com.sa/Transactions/v2/payments/pay-request' && $request['paymentType'] === '15');
    }

    public function test_alrajhi_explicit_object_and_url_encoding_formats_do_not_trigger_retries(): void
    {
        $this->gateway('alrajhi');
        config(['provider-payments.providers.alrajhi.trandata_format' => 'object', 'provider-payments.providers.alrajhi.url_encode_before_encrypt' => true]);
        $this->app->forgetInstance('payment.manager');
        Http::fake(['*' => Http::response($this->creation('alrajhi'))]);
        app(PaymentManager::class)->gateway('alrajhi')->createPayment($this->requestDTO());
        Http::assertSent(function ($request) {
            $plain = openssl_decrypt(hex2bin($request[0]['trandata']), 'aes-256-cbc', '01234567890123456789012345678901', OPENSSL_RAW_DATA, 'PGKEYENCDECIVSPC');
            self::assertStringStartsWith('%7B', $plain);
            $data = json_decode(urldecode($plain), true, 512, JSON_THROW_ON_ERROR);

            return $data['trackId'] === 'ORD-1' && $data['amt'] === '150.00';
        });
        Http::assertSentCount(1);
    }

    public function test_alrajhi_invalid_resource_key_is_detected_without_payment_traffic(): void
    {
        $this->gateway('alrajhi');
        config(['provider-payments.providers.alrajhi.credentials.resource_key' => 'too-short']);
        $this->app->forgetInstance('payment.manager');
        $gateway = app(PaymentManager::class)->gateway('alrajhi');
        self::assertFalse($gateway->isConfigured());
        try {
            $gateway->createPayment($this->requestDTO());
            self::fail('Invalid encryption configuration.');
        } catch (ConfigurationException) {
            Http::assertNothingSent();
            self::assertSame(0, DB::table('ppsa_transactions')->count());
        }
    }

    #[DataProvider('banks')]
    public function test_integer_bank_identifiers_are_compared_exactly_without_float_conversion(string $bank): void
    {
        $gateway = $this->gateway($bank);
        $this->seedPayment($bank);
        DB::table('ppsa_transactions')->update(['provider_reference' => '600000000000000001']);
        $data = $this->snapshot($bank);
        $data[$bank === 'alrajhi' ? 'paymentId' : 'transactionId'] = 600000000000000001;
        Http::fake(['*' => Http::response($data)]);
        self::assertSame('600000000000000001', $gateway->getPaymentStatus('600000000000000001')->providerTransactionId);
    }
}
