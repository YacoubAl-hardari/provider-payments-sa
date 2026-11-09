<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentTransportException;
use YacoubAlhaidari\ProviderPayments\Tests\TestCase;

final class PaymentTransportTest extends TestCase
{
    public static function failures(): array
    {
        return [
            'dns failure' => [['errno' => 6], true],
            'connect failure' => [['errno' => 7], true],
            'connect timeout' => [['errno' => 28, 'request_size' => 0, 'pretransfer_time' => 0.0, 'connect_time' => 0.0], true],
            'response timeout' => [['errno' => 28, 'request_size' => 500, 'pretransfer_time' => 0.1, 'connect_time' => 0.1], false],
            'missing transfer evidence' => [['errno' => 28], false],
            'connected but no response' => [['errno' => 28, 'request_size' => 0, 'pretransfer_time' => 0.0, 'connect_time' => 0.1], false],
            'unknown transport' => [[], false],
        ];
    }

    #[DataProvider('failures')]
    public function test_preserves_unknown_outcomes_but_closes_proven_unsent_creations(array $context, bool $safe): void
    {
        $gateway = $this->gateway('alrajhi');
        Http::fake(['https://securepayments.alrajhibank.com.sa/pg/payment/hosted.htm' => function (Request $request) use ($context) {
            return Create::rejectionFor(new ConnectException('private-secret bank information', $request->toPsrRequest(), null, $context));
        }]);

        try {
            $gateway->createPayment($this->requestDTO());
            self::fail('Expected a payment transport failure.');
        } catch (PaymentTransportException $exception) {
            self::assertSame($safe, $exception->failedBeforeSending);
            self::assertStringNotContainsString('private-secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame($safe ? 'failed' : 'reserved', DB::table('ppsa_transactions')->value('creation_state'));
        self::assertSame($safe ? 'failed' : 'pending', DB::table('ppsa_transactions')->value('status'));
        Http::assertSentCount(1);
    }
}
