<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use YacoubAlhaidari\ProviderPayments\DTOs\CustomerDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\Tests\TestCase;

final class MisPayMobileTest extends TestCase
{
    public static function validMobiles(): array
    {
        return array_map(fn (string $phone): array => [$phone], [
            '0501234567', '501234567', '+966501234567', '966501234567',
            '00966501234567', '+966 (50) 123-4567',
            '٠٥٠١٢٣٤٥٦٧', '+٩٦٦٥٠١٢٣٤٥٦٧', '۰۵۰۱۲۳۴۵۶۷',
        ]);
    }

    public static function invalidMobiles(): array
    {
        return array_map(fn (string $phone): array => [$phone], [
            '', '12345', '050123456', '05012345678', '+966', '+96650123',
            '+9665012345678', '+966111234567', '+971501234567', 'abc0501234567',
        ]);
    }

    #[DataProvider('validMobiles')]
    public function test_checkout_sends_a_normalized_saudi_mobile(string $phone): void
    {
        $gateway = $this->gateway('mispay');
        $token = $this->encrypted(['token' => 'test-jwt']);
        Http::fake([
            'https://api-sandbox.mispay.dev/v1/api/token' => Http::response(['result' => ['token' => $token]]),
            'https://api-sandbox.mispay.dev/v1/api/start-checkout' => Http::response(['result' => ['trackId' => 'remote-1', 'url' => 'https://checkout.mispay.co/1']]),
        ]);
        $values = get_object_vars($this->requestDTO());
        $values['customer'] = new CustomerDTO('Test Customer', 'test@example.com', $phone, 'Street', 'Riyadh');

        $result = $gateway->createPayment(new PaymentRequestDTO(...$values));

        self::assertSame('remote-1', $result->providerTransactionId);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/start-checkout')
            && $request['customerDetails']['mobileNumber'] === '+966501234567');
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidMobiles')]
    public function test_invalid_mobile_is_rejected_before_token_or_ledger_reservation(string $phone): void
    {
        $gateway = $this->gateway('mispay');
        $values = get_object_vars($this->requestDTO());
        $values['customer'] = new CustomerDTO('Test Customer', 'test@example.com', $phone, 'Street', 'Riyadh');

        try {
            $gateway->createPayment(new PaymentRequestDTO(...$values));
            self::fail('Invalid mobile was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('MisPay requires a Saudi mobile number.', $exception->getMessage());
        }

        Http::assertNothingSent();
        self::assertSame(0, DB::table('ppsa_transactions')->count());
    }
}
