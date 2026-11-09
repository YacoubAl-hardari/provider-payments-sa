<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use YacoubAlhaidari\ProviderPayments\Providers\MisPay\MisPayGateway;
use YacoubAlhaidari\ProviderPayments\Tests\TestCase;

final class MisPayIpAuthenticationTest extends TestCase
{
    public function test_configured_peer_ip_authenticates_and_replay_does_not_repeat_payment(): void
    {
        config(['provider-payments.providers.mispay.whitelist_ip' => '34.18.98.212']);
        $this->gateway('mispay');
        $this->seedPayment('mispay');
        $payload = ['checkoutId' => 'remote-1', 'orderId' => 'ORD-1', 'code' => 'MP00'];

        $this->withServerVariables(['REMOTE_ADDR' => '34.18.98.212'])
            ->postJson('/payments/mispay/webhook', $payload)->assertOk()->assertJson(['accepted' => true]);
        $this->postJson('/payments/mispay/webhook', $payload)->assertOk()->assertJson(['accepted' => false]);
        self::assertSame('paid', DB::table('ppsa_transactions')->value('status'));
        Http::assertNothingSent();
    }

    public function test_unlisted_ip_and_forged_forwarding_headers_are_rejected(): void
    {
        config(['provider-payments.providers.mispay.whitelist_ip' => '34.18.98.212']);
        $this->gateway('mispay');
        $this->seedPayment('mispay');
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->postJson('/payments/mispay/webhook', ['checkoutId' => 'remote-1', 'orderId' => 'ORD-1', 'code' => 'MP00'], [
                'X-Forwarded-For' => '34.18.98.212', 'X-Real-IP' => '34.18.98.212',
            ])->assertUnauthorized();
        self::assertSame('requires_action', DB::table('ppsa_transactions')->value('status'));
        Http::assertNothingSent();
    }

    public function test_blank_invalid_or_private_allowlists_do_not_enable_authentication(): void
    {
        $gateway = new MisPayGateway(['whitelist_ip' => 'invalid,127.0.0.1,10.0.0.1', 'credentials' => ['webhook_secret' => '']]);
        self::assertFalse($gateway->hasWebhookAuthentication());
        self::assertFalse($gateway->verifyWebhook(Request::create('/', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1'])));
    }

    public function test_an_allowed_ip_does_not_bypass_saved_track_identity(): void
    {
        config(['provider-payments.providers.mispay.whitelist_ip' => '34.18.98.212']);
        $this->gateway('mispay');
        $this->seedPayment('mispay');
        $this->withServerVariables(['REMOTE_ADDR' => '34.18.98.212'])
            ->postJson('/payments/mispay/webhook', ['checkoutId' => 'other', 'orderId' => 'ORD-1', 'code' => 'MP00'])->assertUnprocessable();
        self::assertSame('requires_action', DB::table('ppsa_transactions')->value('status'));
    }
}
