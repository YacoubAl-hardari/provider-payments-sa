<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\PaymentManager;
use YacoubAlhaidari\ProviderPayments\Tests\TestCase;

final class AlinmaInquiryProtocolTest extends TestCase
{
    private function snapshot(string $result = 'INITIATED', string $code = '001'): array
    {
        return ['terminalId' => 'test-terminal', 'transactionId' => 'remote-1',
            'orderDetails' => ['orderId' => 'ORD-1'], 'amountDetails' => ['amount' => '150.00'],
            'responseCode' => $code, 'result' => $result];
    }

    public function test_inquiry_uses_original_amount_order_and_merchant_ip_and_preserves_bank_status(): void
    {
        $this->gateway('alinmapay');
        config(['provider-payments.providers.alinmapay.merchant_ip' => '192.0.2.10']);
        $this->app->forgetInstance('payment.manager');
        $this->seedPayment('alinmapay');
        Http::fake(['https://pg.alinmapay.com.sa/SB_Transactions/v2/payments/pay-request' => Http::response($this->snapshot())]);

        $result = app(PaymentManager::class)->verifyPayment('remote-1', 'alinmapay');

        self::assertSame(PaymentStatus::REQUIRES_ACTION, $result->status);
        self::assertSame('INITIATED', $result->providerStatus);
        Http::assertSent(fn ($request): bool => $request['paymentType'] === '10' && $request['amount'] === '150.00'
            && $request['order']['orderId'] === 'ORD-1' && $request['referenceId'] === 'remote-1'
            && $request['merchantIp'] === '192.0.2.10'
            && $request['signature'] === hash('sha256', 'ORD-1|test-terminal|test-password|'.str_repeat('ab', 32).'|150.00|SAR'));
        Http::assertSentCount(1);
    }

    public static function mismatches(): array
    {
        return [['transactionId', 'other'], ['orderDetails.orderId', 'other'], ['amountDetails.amount', '0.01'],
            ['terminalId', 'other'], ['currency', 'USD'], ['orderId', 'contradictory'], ['amount', '0.01']];
    }

    #[DataProvider('mismatches')]
    public function test_nested_inquiry_does_not_accept_identity_or_money_mismatches(string $field, string $value): void
    {
        $gateway = $this->gateway('alinmapay');
        $this->seedPayment('alinmapay');
        $response = $this->snapshot();
        data_set($response, $field, $value);
        Http::fake(['https://pg.alinmapay.com.sa/SB_Transactions/v2/payments/pay-request' => Http::response($response)]);
        $this->expectException(PaymentVerificationException::class);
        $gateway->getPaymentStatus('remote-1');
    }

    public static function rejectedInquiries(): array
    {
        return [['612'], ['622']];
    }

    #[DataProvider('rejectedInquiries')]
    public function test_an_invalid_inquiry_does_not_claim_that_payment_failed(string $code): void
    {
        $gateway = $this->gateway('alinmapay');
        $this->seedPayment('alinmapay');
        Http::fake(['https://pg.alinmapay.com.sa/SB_Transactions/v2/payments/pay-request' => Http::response($this->snapshot('FAILURE', $code))]);
        $this->expectException(PaymentVerificationException::class);
        $gateway->getPaymentStatus('remote-1');
    }
}
