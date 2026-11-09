<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Providers\AlinmaPay;

use Illuminate\Http\Request;
use YacoubAlhaidari\ProviderPayments\Contracts\CapturesPayments;
use YacoubAlhaidari\ProviderPayments\Contracts\RefundsPayments;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundResponseDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Exceptions\ConfigurationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentRequestException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Providers\BankGateway;
use YacoubAlhaidari\ProviderPayments\Repositories\PaymentLedger;
use YacoubAlhaidari\ProviderPayments\Support\ExactJson;
use YacoubAlhaidari\ProviderPayments\Support\Money;

final class AlinmaPayGateway extends BankGateway implements CapturesPayments, RefundsPayments
{
    protected string $name = 'alinmapay';

    protected array $capabilities = ['create' => true, 'verify' => true, 'webhook' => true, 'refund' => true, 'capture' => true];

    protected function requiredCredentials(): array
    {
        return ['terminal_id', 'terminal_password', 'merchant_key'];
    }

    public function isConfigured(): bool
    {
        $key = (string) $this->credential('merchant_key');

        return parent::isConfigured() && ctype_xdigit($key) && in_array(strlen($key), [32, 64], true)
            && ($this->config['base_url'] ?? '') === 'https://pg.alinmapay.com.sa'
            && in_array($this->config['environment'] ?? 'sandbox', ['sandbox', 'production'], true)
            && in_array($this->config['payment_type'] ?? '1', ['1', '4', '15'], true);
    }

    protected function validateRequest(PaymentRequestDTO $request): void
    {
        parent::validateRequest($request);
        $this->environment();
        self::normalizeBillingCity($request->customer?->city);
        if (! in_array($this->config['payment_type'] ?? '1', ['1', '4', '15'], true)) {
            throw new ConfigurationException('AlinmaPay checkout payment type must be 1, 4 or 15.');
        }
    }

    public static function normalizeBillingCity(?string $city): ?string
    {
        $city = trim((string) $city);
        if ($city === '') {
            return null;
        }
        $normalized = preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $city);
        $cities = [
            'الرياض' => 'Riyadh', 'رياض' => 'Riyadh',
            'جدة' => 'Jeddah', 'جده' => 'Jeddah',
            'مكة' => 'Makkah', 'مكه' => 'Makkah', 'مكة المكرمة' => 'Makkah',
            'المدينة' => 'Madinah', 'المدينة المنورة' => 'Madinah',
            'الدمام' => 'Dammam', 'الخبر' => 'Khobar', 'الظهران' => 'Dhahran',
            'الأحساء' => 'Al Ahsa', 'الاحساء' => 'Al Ahsa', 'الهفوف' => 'Hofuf',
            'الطائف' => 'Taif', 'تبوك' => 'Tabuk', 'حائل' => 'Hail',
            'بريدة' => 'Buraydah', 'عنيزة' => 'Unaizah', 'أبها' => 'Abha', 'ابها' => 'Abha',
            'خميس مشيط' => 'Khamis Mushait', 'جازان' => 'Jazan', 'جيزان' => 'Jazan',
            'نجران' => 'Najran', 'ينبع' => 'Yanbu', 'الجبيل' => 'Jubail',
            'القطيف' => 'Qatif', 'عرعر' => 'Arar', 'سكاكا' => 'Sakaka',
            'الباحة' => 'Al Baha', 'الخرج' => 'Al Kharj', 'حفر الباطن' => 'Hafar Al Batin',
        ];
        if (isset($cities[$normalized])) {
            return $cities[$normalized];
        }
        if (preg_match("/^[A-Za-z][A-Za-z .'-]{0,79}$/D", $city) === 1) {
            return $city;
        }

        throw new \InvalidArgumentException('AlinmaPay billing city must be a supported Saudi city or an English city name.');
    }

    private function environment(): string
    {
        $environment = $this->config['environment'] ?? 'sandbox';
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new ConfigurationException('Invalid AlinmaPay environment.');
        }

        return $environment;
    }

    private function api(string $type, string $order, int $amount, string $currency, array $fields = []): array
    {
        $this->sar($currency);
        $decimal = Money::decimal($amount, $currency);
        $terminal = (string) $this->credential('terminal_id');
        $password = (string) $this->credential('terminal_password');
        $key = (string) $this->credential('merchant_key');
        $payload = [
            'terminalId' => $terminal,
            'password' => $password,
            'signature' => hash('sha256', implode('|', [$order, $terminal, $password, $key, $decimal, $currency])),
            'paymentType' => $type,
            'amount' => $decimal,
            'currency' => $currency,
            'order' => ['orderId' => $order],
            'customer' => ['billingAddressCountry' => 'SA'],
        ];
        $path = $this->environment() === 'sandbox' ? '/SB_Transactions' : '/Transactions';

        return $this->send('POST', $path.'/v2/payments/pay-request', array_replace($payload, $fields));
    }

    protected function createRemote(PaymentRequestDTO $request): PaymentResponseDTO
    {
        $customer = $request->customer;
        $data = $this->api($this->config['payment_type'] ?? '1', $request->orderReference, $request->amount, $request->currency, [
            'order' => array_filter(['orderId' => $request->orderReference, 'description' => $request->description], fn ($v) => $v !== null),
            'customer' => array_filter([
                'customerEmail' => $customer?->email,
                'cardHolderName' => $customer?->name,
                'billingAddressStreet' => $customer?->street,
                'billingAddressCity' => self::normalizeBillingCity($customer?->city),
                'billingAddressPostalCode' => $customer?->zip,
                'billingAddressCountry' => $customer?->country ?? 'SA',
                'customerIp' => $customer?->ip,
            ], fn ($v) => $v !== null),
            'additionalDetails' => ['userData' => json_encode(['receiptUrl' => $request->successUrl], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
        ]);
        if (isset($data['responseCode']) && ! in_array((string) $data['responseCode'], ['000', '001'], true)) {
            throw new PaymentRequestException('AlinmaPay checkout rejected.');
        }
        $id = $this->identifier($data, 'transactionId');
        if (in_array(strtoupper((string) ($data['result'] ?? '')), ['FAILED', 'FAILURE', 'DECLINED', 'ERROR'], true)) {
            throw new PaymentRequestException('AlinmaPay checkout rejected.');
        }
        $url = $data['redirectUrl'] ?? $data['paymentLink']['linkUrl'] ?? null;
        if (! is_string($url) || $url === '') {
            throw new PaymentRequestException('AlinmaPay checkout URL missing.');
        }
        $url = $this->checkout($url);
        if (empty($data['redirectUrl'])) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (isset($query['paymentId']) && ! is_string($query['paymentId'])) {
                throw new PaymentRequestException('Invalid AlinmaPay payment link identifier.');
            }
            if (empty($query['paymentId'])) {
                $fragment = parse_url($url, PHP_URL_FRAGMENT);
                $base = explode('?', explode('#', $url, 2)[0], 2)[0];
                $query['paymentId'] = $id;
                $url = $base.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986).($fragment !== null ? '#'.$fragment : '');
            } elseif ($query['paymentId'] !== $id) {
                throw new PaymentVerificationException('AlinmaPay payment link identity mismatch.');
            }
        }

        return new PaymentResponseDTO($this->name, S::REQUIRES_ACTION, $id, $request->orderReference, $request->amount, $request->currency, $url);
    }

    public function getPaymentStatus(string $transactionReference): PaymentResponseDTO
    {
        $this->ready();
        $row = app(PaymentLedger::class)->find($this->name, $transactionReference);
        $data = $this->api('10', 'INQUIRY', 0, $row->currency, ['referenceId' => $transactionReference]);
        if (
            $this->identifier($data, 'transactionId') !== $transactionReference
            || $this->identifier($data, 'orderId') !== $row->order_reference
            || ($data['currency'] ?? null) !== $row->currency
            || $this->remoteAmount($data['amount'] ?? null, $row->currency) !== (int) $row->amount
        ) {
            throw new PaymentVerificationException('AlinmaPay inquiry identity/amount/currency mismatch.');
        }
        $status = $this->status($data);

        return new PaymentResponseDTO($this->name, $status, $transactionReference, $row->order_reference, (int) $row->amount, $row->currency, providerStatus: (string) ($data['result'] ?? $data['status'] ?? ''), raw: array_intersect_key($data, array_flip(['transactionId', 'orderId', 'amount', 'currency', 'responseCode', 'result', 'status'])));
    }

    private function status(array $data): S
    {
        if (isset($data['status'], $data['result']) && strtoupper((string) $data['status']) !== strtoupper((string) $data['result'])) {
            throw new PaymentVerificationException('Contradictory AlinmaPay status fields.');
        }
        $result = strtoupper((string) ($data['status'] ?? $data['result'] ?? ''));
        $code = (string) ($data['responseCode'] ?? '');

        return match (true) {
            in_array($result, ['PENDING', 'INITIATED', 'PROCESSING'], true) && in_array($code, ['000', '001'], true) => S::PENDING,
            in_array($result, ['AUTHORIZED', 'AUTHORISED', 'PRE_AUTHORIZED'], true) && $code === '000' => S::AUTHORIZED,
            $result === 'SUCCESS' && $code === '000' && (string) ($data['paymentType'] ?? '') === '4' => S::AUTHORIZED,
            $result === 'SUCCESS' && $code === '000' && in_array((string) ($data['paymentType'] ?? ''), ['1', '5', '15'], true) => S::PAID,
            in_array($result, ['PAID', 'CAPTURED'], true) && $code === '000' => S::PAID,
            in_array($result, ['CANCELLED', 'CANCELED', 'VOIDED'], true) && in_array($code, ['000', '624', '517'], true) => S::CANCELLED,
            $result === 'EXPIRED' => S::EXPIRED,
            in_array($result, ['FAILED', 'DECLINED', 'FAILURE'], true) && $code !== '000' => S::FAILED,
            $result === 'REFUNDED' && $code === '000' => S::REFUNDED,
            $result === 'PARTIALLY_REFUNDED' && $code === '000' => S::PARTIALLY_REFUNDED,
            default => throw new PaymentVerificationException('Unrecognized or contradictory AlinmaPay status.'),
        };
    }

    protected function callbackPayload(Request $request): array
    {
        if ($request->has('data')) {
            $key = (string) $this->credential('merchant_key');
            $value = $request->input('data');
            if (! is_string($value) || ! ctype_xdigit($key) || ! in_array(strlen($key), [32, 64], true)) {
                throw new PaymentVerificationException('Invalid AlinmaPay encrypted receipt.');
            }
            $cipher = base64_decode($value, true);
            // The source protocol uses AES-128 even when its hex merchant key holds 32 bytes.
            $plain = $cipher === false ? false : openssl_decrypt($cipher, 'aes-128-ecb', substr(hex2bin($key), 0, 16), OPENSSL_RAW_DATA);
            if ($plain === false) {
                throw new PaymentVerificationException('Invalid AlinmaPay encrypted receipt.');
            }
            try {
                return ExactJson::decode($plain);
            } catch (\JsonException) {
                throw new PaymentVerificationException('Invalid AlinmaPay receipt JSON.');
            }
        }
        if ($request->isJson()) {
            try {
                return ExactJson::decode($request->getContent());
            } catch (\JsonException) {
                throw new PaymentVerificationException('Invalid AlinmaPay callback JSON.');
            }
        }

        return $request->all();
    }

    public function verifyWebhook(Request $request): bool
    {
        $this->ready();
        try {
            $data = $this->callbackPayload($request);
            $id = $this->identifier($data, 'transactionId');
            $code = $this->requireText($data, 'responseCode');
            $this->callbackIdentity($data);
            $signature = $request->header('X-AlinmaPay-Signature') ?? $data['signature'] ?? null;
            if ($signature === null && $request->has('data')) {
                // Encryption alone is not authentication. The callback still requires bank inquiry.
                return true;
            }
            $expected = hash('sha256', implode('|', [$id, (string) $this->credential('merchant_key'), $code, Money::decimal($this->remoteAmount($data['amount'] ?? null, 'SAR'), 'SAR')]));

            return is_string($signature) && hash_equals($expected, $signature);
        } catch (PaymentException|\InvalidArgumentException|\ValueError) {
            return false;
        }
    }

    protected function callbackIdentity(array $payload): array
    {
        return [$this->identifier($payload, 'orderId'), $this->identifier($payload, 'transactionId'), $this->remoteAmount($payload['amount'] ?? null, 'SAR'), $this->requireText($payload, 'currency')];
    }

    public function refund(RefundRequestDTO $request): RefundResponseDTO
    {
        $this->ready();
        $this->sar($request->currency);

        return app(PaymentLedger::class)->refund($this->name, $request, function () use ($request) {
            $id = $this->executeOperation('refund', $request->transactionReference, $request->amount, $request->currency);

            return new RefundResponseDTO($this->name, true, $id, $request->transactionReference, $request->amount, $request->currency);
        });
    }

    public function capture(CaptureRequestDTO $request): CaptureResponseDTO
    {
        $this->ready();
        $this->sar($request->currency);

        return app(PaymentLedger::class)->capture($this->name, $request, function () use ($request) {
            $id = $this->executeOperation('capture', $request->transactionReference, $request->amount, $request->currency);

            return new CaptureResponseDTO($this->name, true, $id, $request->transactionReference, $request->amount, $request->currency);
        });
    }

    protected function executeOperation(string $kind, string $reference, int $amount, string $currency): string
    {
        $type = ['refund' => '2', 'capture' => '5'][$kind];
        $order = ['refund' => 'REFUND', 'capture' => 'CAPTURE'][$kind];
        $data = $this->api($type, $order, $amount, $currency, ['referenceId' => $reference]);
        if (($data['responseCode'] ?? null) !== '000'
            || ! in_array(strtoupper((string) ($data['result'] ?? '')), ['SUCCESS', ['refund' => 'REFUNDED', 'capture' => 'CAPTURED'][$kind]], true)
            || ($data['currency'] ?? null) !== $currency
            || $this->remoteAmount($data['amount'] ?? null, $currency) !== $amount
            || ($data['orderId'] ?? null) !== $order
            || (isset($data['referenceId']) && $data['referenceId'] !== $reference)
        ) {
            throw new PaymentVerificationException('AlinmaPay operation not confirmed; reconcile before retry.');
        }

        return $this->identifier($data, 'transactionId');
    }
}
