<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use YacoubAlhaidari\ProviderPayments\DTOs\CustomerDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\Tests\TestCase;

final class AlinmaBillingCityTest extends TestCase
{
    public static function cities(): array
    {
        return [['الرياض', 'Riyadh'], ['جدة', 'Jeddah'], ['الدمام', 'Dammam'], ['Riyadh', 'Riyadh']];
    }

    #[DataProvider('cities')]
    public function test_sends_the_bank_billing_city_in_english(string $city, string $expected): void
    {
        $gateway = $this->gateway('alinmapay');
        Http::fake(['https://pg.alinmapay.com.sa/SB_Transactions/v2/payments/pay-request' => Http::response([
            'transactionId' => 'CITY-1', 'responseCode' => '001', 'paymentLink' => ['linkUrl' => 'https://pg.alinmapay.com.sa/pay'],
        ])]);
        $values = get_object_vars($this->requestDTO());
        $values['customer'] = new CustomerDTO('Test Customer', 'test@example.com', '+966500000000', 'Street', $city);
        $result = $gateway->createPayment(new PaymentRequestDTO(...$values));
        self::assertNotNull($result->checkoutUrl);
        Http::assertSent(fn ($request): bool => $request['customer']['billingAddressCity'] === $expected);
        Http::assertSentCount(1);
    }

    public function test_rejects_an_unknown_arabic_city_before_reserving_or_contacting_the_bank(): void
    {
        $gateway = $this->gateway('alinmapay');
        $values = get_object_vars($this->requestDTO());
        $values['customer'] = new CustomerDTO('Test Customer', 'test@example.com', '+966500000000', 'Street', 'مدينة غير معروفة');
        try {
            $gateway->createPayment(new PaymentRequestDTO(...$values));
            self::fail('Unsupported city accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('billing city', $exception->getMessage());
        }
        Http::assertNothingSent();
        self::assertSame(0, DB::table('ppsa_transactions')->count());
    }
}
