<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Providers\MisPay;

use Illuminate\Http\Request;
use YacoubAlhaidari\ProviderPayments\AbstractGateway;
use YacoubAlhaidari\ProviderPayments\Contracts\ProcessesWebhooks;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\WebhookResultDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentRequestException;
use YacoubAlhaidari\ProviderPayments\Exceptions\UnsupportedOperationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\WebhookVerificationException;
use YacoubAlhaidari\ProviderPayments\Security\MisPayToken;
use YacoubAlhaidari\ProviderPayments\Support\Money;

class MisPayGateway extends AbstractGateway implements ProcessesWebhooks
{
    protected string $name = 'mispay';

    protected array $capabilities = ['create' => true, 'verify' => false, 'webhook' => true];

    protected function requiredCredentials(): array
    {
        return ['app_id', 'app_secret'];
    }

    protected function bnpl(PaymentRequestDTO $request): void
    {
        self::normalizeMobile((string) $request->customer?->phone);
        if ($request->currency !== 'SAR') {
            throw new \InvalidArgumentException('Audited MisPay profile supports SAR only.');
        }

        parent::bnpl($request);
    }

    protected function createRemote(PaymentRequestDTO $r): PaymentResponseDTO
    {
        $this->bnpl($r);
        if ($r->currency !== 'SAR') {
            throw new \InvalidArgumentException('Audited MisPay profile supports SAR only.');
        }
        $headers = ['x-app-id' => $this->credential('app_id'), 'x-app-secret' => $this->credential('app_secret')];
        $token = $this->send('GET', '/token', [], $headers);
        try {
            $t = MisPayToken::decrypt($this->requireText($token, 'result.token'), (string) $this->credential('app_secret'));
        } catch (\Throwable) {
            throw new PaymentRequestException('MisPay token decryption failed.');
        }
        $jwt = $this->requireText($t, 'token');
        $c = $r->customer;
        $parts = preg_split('/\s+/u', trim($c->name), 2);
        $payload = ['orderId' => $r->orderReference, 'invoiceId' => $r->orderReference, 'totalPrice' => Money::decimal(array_sum(array_map(fn ($i) => $i->total(), $r->items)), $r->currency), 'shippingAmount' => Money::decimal($r->shippingAmount, $r->currency), 'vat' => Money::decimal($r->taxAmount, $r->currency), 'discount' => ['percentage' => '0', 'amount' => Money::decimal($r->discountAmount, $r->currency)], 'purchaseAmount' => $r->formattedAmount(), 'purchaseCurrency' => $r->currency, 'lang' => $r->language, 'version' => 'v1.2', 'customerDetails' => ['id' => $r->metadata['customer_id'] ?? $r->orderReference, 'firstName' => $parts[0], 'lastName' => $parts[1] ?? $parts[0], 'mobileNumber' => self::normalizeMobile((string) $c->phone), 'email' => $c->email], 'orderDetails' => ['items' => array_map(fn ($i) => ['id' => $i->sku ?? $i->name, 'sku' => $i->sku ?? $i->name, 'nameArabic' => $i->name, 'nameEnglish' => $i->name, 'quantity' => (string) $i->quantity, 'unitPrice' => Money::decimal($i->unitPrice, $r->currency)], $r->items)], 'shippingDetails' => ['firstName' => $parts[0], 'lastName' => $parts[1] ?? $parts[0], 'countryCode' => $c->country, 'city' => $c->city, 'district' => $r->metadata['district'] ?? $c->city, 'line1' => $c->street, 'line2' => '', 'gpsCoordinates' => ''], 'isMobile' => $r->isMobile];
        if ($this->config['send_callback_override'] ?? false) {
            $payload['callbackUrl'] = $r->successUrl;
        }
        $d = $this->send('POST', '/start-checkout', $payload, $headers + ['Authorization' => 'Bearer '.$jwt]);

        return new PaymentResponseDTO($this->name, S::REQUIRES_ACTION, $this->requireText($d, 'result.trackId'), $r->orderReference, $r->amount, $r->currency, $this->checkout($this->requireText($d, 'result.url')));
    }

    public static function normalizeMobile(string $phone): string
    {
        $phone = strtr($phone, array_combine(
            preg_split('//u', "\u{0660}\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}\u{0667}\u{0668}\u{0669}\u{06F0}\u{06F1}\u{06F2}\u{06F3}\u{06F4}\u{06F5}\u{06F6}\u{06F7}\u{06F8}\u{06F9}", -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789'),
        ));
        $phone = preg_replace('/[\s().\-\x{200E}\x{200F}\x{061C}]+/u', '', $phone);
        if ($phone !== null && preg_match('/^(?:(?:\+|00)?966|0)?(5[0-9]{8})$/D', $phone, $matches) === 1) {
            return '+966'.$matches[1];
        }

        throw new \InvalidArgumentException('MisPay requires a Saudi mobile number.');
    }

    public function getPaymentStatus(string $transactionReference): PaymentResponseDTO
    {
        throw new UnsupportedOperationException('No MisPay inquiry API found in audited source.');
    }

    public function verifyWebhook(Request $r): bool
    {
        $key = (string) $this->credential('webhook_secret');
        $v = $r->header('X-Mispay-Signature') ?? $r->header('X-Webhook-Secret');

        if ($key !== '' && is_string($v) && hash_equals($key, $v)) {
            return true;
        }

        return in_array($r->ip(), $this->allowedWebhookIps(), true);
    }

    public function hasWebhookAuthentication(): bool
    {
        return (string) $this->credential('webhook_secret') !== '' || $this->allowedWebhookIps() !== [];
    }

    /** @return list<string> */
    private function allowedWebhookIps(): array
    {
        $configured = $this->config['whitelist_ip'] ?? '';
        $ips = is_array($configured) ? $configured : preg_split('/[\s,;]+/', trim((string) $configured));

        return array_values(array_filter(array_map('trim', $ips),
            fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false));
    }

    public function processWebhook(Request $r): WebhookResultDTO
    {
        if (! $this->verifyWebhook($r)) {
            throw new WebhookVerificationException('Invalid MisPay secret.');
        }
        $id = $this->requireText($r->all(), 'orderId');
        $code = strtoupper($this->requireText($r->all(), 'code'));
        $s = match (strtoupper($code)) {
            'MP00' => S::PAID,
            'MP01' => S::FAILED,
            'MP02' => S::PENDING,
            'MP03' => S::PENDING,
            default => throw new PaymentRequestException('Unknown MisPay code.')
        };

        return new WebhookResultDTO($this->name, true, $s, $this->requireText($r->all(), 'checkoutId'), raw: ['cart_id' => $id, 'code' => $code]);
    }
}
