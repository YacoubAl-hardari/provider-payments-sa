<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Providers\AlRajhi;

use Illuminate\Http\Request;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Exceptions\ConfigurationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentRequestException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Providers\BankGateway;
use YacoubAlhaidari\ProviderPayments\Repositories\PaymentLedger;
use YacoubAlhaidari\ProviderPayments\Security\AlRajhiCipher;
use YacoubAlhaidari\ProviderPayments\Support\Money;

final class AlRajhiGateway extends BankGateway
{
    protected string $name = 'alrajhi';

    protected function requiredCredentials(): array
    {
        return ['tranportal_id', 'tranportal_password', 'resource_key'];
    }

    public function isConfigured(): bool
    {
        return parent::isConfigured() && strlen((string) $this->credential('resource_key')) === 32
            && in_array($this->config['base_url'] ?? '', ['https://securepayments.alrajhibank.com.sa', 'https://digitalpayments.neoleap.com.sa'], true)
            && is_string($this->config['merchant_ip'] ?? null)
            && filter_var($this->config['merchant_ip'], FILTER_VALIDATE_IP) !== false
            && in_array($this->config['action'] ?? '1', ['1', '4'], true)
            && in_array($this->config['trandata_format'] ?? 'array', ['array', 'object'], true);
    }

    private function cipher(): AlRajhiCipher
    {
        return new AlRajhiCipher((string) $this->credential('resource_key'), (bool) ($this->config['url_encode_before_encrypt'] ?? false), (bool) ($this->config['url_decode_after_decrypt'] ?? false));
    }

    protected function validateRequest(PaymentRequestDTO $request): void
    {
        parent::validateRequest($request);
        $this->cipher();
        if (
            ! in_array($this->config['action'] ?? '1', ['1', '4'], true)
            || ! in_array($this->config['trandata_format'] ?? 'array', ['array', 'object'], true)
        ) {
            throw new ConfigurationException('Invalid AlRajhi action/trandata format.');
        }
        $this->ip($request->customer?->ip);
    }

    private function ip(?string $ip = null): string
    {
        $ip ??= $this->config['merchant_ip'] ?? null;
        if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new ConfigurationException('AlRajhi requires a valid saved customer IP or configured merchant IP.');
        }

        return $ip;
    }

    private function api(array $transaction, ?string $ip = null, array $callbacks = []): array
    {
        $transaction += ['id' => (string) $this->credential('tranportal_id'), 'password' => (string) $this->credential('tranportal_password')];
        $body = ['id' => (string) $this->credential('tranportal_id'), 'trandata' => $this->cipher()->encrypt($transaction, ($this->config['trandata_format'] ?? 'array') === 'object')] + $callbacks;
        $data = $this->send('POST', '/pg/payment/hosted.htm', [$body], ['X-FORWARDED-FOR' => $this->ip($ip)]);
        if (array_is_list($data)) {
            if (count($data) !== 1 || ! is_array($data[0])) {
                throw new PaymentRequestException('Ambiguous AlRajhi response.');
            }
            $data = $data[0];
        }
        if (! empty($data['error']) || ! empty($data['errorCode'])) {
            throw new PaymentRequestException('AlRajhi rejected the request.');
        }

        return $data;
    }

    protected function createRemote(PaymentRequestDTO $request): PaymentResponseDTO
    {
        // Bank-hosted return URLs are browser callbacks, not a separate notification field.
        $callbacks = ['responseURL' => $request->successUrl, 'errorURL' => $request->failureUrl ?? $request->cancelUrl];
        $data = $this->api([
            'amt' => Money::decimal($request->amount, $request->currency),
            'action' => $this->config['action'] ?? '1',
            'currencyCode' => '682',
            'trackId' => $request->orderReference,
        ] + $callbacks, $request->customer?->ip, $callbacks);
        if ((string) ($data['status'] ?? '') !== '1') {
            throw new PaymentRequestException('AlRajhi checkout initiation not confirmed.');
        }
        $parts = explode(':', $this->requireText($data, 'result'), 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            throw new PaymentRequestException('Invalid AlRajhi checkout reference.');
        }
        [$id, $url] = $parts;
        $url = $this->checkout($url);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (isset($query['PaymentID']) && $query['PaymentID'] !== '' && $query['PaymentID'] !== $id) {
            throw new PaymentVerificationException('AlRajhi checkout identity mismatch.');
        }
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        $base = explode('?', explode('#', $url, 2)[0], 2)[0];
        $query['PaymentID'] = $id;
        $url = $base.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986).($fragment !== null ? '#'.$fragment : '');

        return new PaymentResponseDTO($this->name, S::REQUIRES_ACTION, $id, $request->orderReference, $request->amount, $request->currency, $url);
    }

    private function transaction(array $data): array
    {
        if (isset($data['trandata'])) {
            return $this->cipher()->decrypt($this->requireText($data, 'trandata'));
        }

        // Direct fields are accepted only from the authenticated HTTPS inquiry, never callbacks.
        return $data;
    }

    private function identity(array $data, object $row, int $amount): void
    {
        if (! empty($data['error']) || ! empty($data['errorCode'])) {
            throw new PaymentVerificationException('AlRajhi transaction contains an error.');
        }
        $payment = $this->identifier(['id' => $data['paymentId'] ?? $data['paymentid'] ?? $data['paymentID'] ?? null], 'id');
        if (
            $payment !== $row->provider_reference || $this->identifier(['id' => $data['trackId'] ?? $data['trackid'] ?? null], 'id') !== $row->order_reference
            || (string) ($data['currencyCode'] ?? '') !== '682'
            || $this->remoteAmount($data['amt'] ?? null, 'SAR') !== $amount
        ) {
            throw new PaymentVerificationException('AlRajhi identity/amount/currency mismatch.');
        }
    }

    public function getPaymentStatus(string $transactionReference): PaymentResponseDTO
    {
        $this->ready();
        $row = app(PaymentLedger::class)->find($this->name, $transactionReference);
        $data = $this->transaction($this->api([
            'action' => '8',
            'amt' => Money::decimal((int) $row->amount, $row->currency),
            'currencyCode' => '682',
            'trackId' => $row->order_reference,
            'transId' => $transactionReference,
            'udf5' => 'PaymentID',
        ]));
        $this->identity($data, $row, (int) $row->amount);
        $result = strtoupper($this->requireText($data, 'result'));
        $status = match ($result) {
            'CAPTURED', 'PAID' => S::CAPTURED,
            'AUTHORIZED', 'AUTHORISED', 'APPROVED' => S::AUTHORIZED,
            'PENDING', 'PROCESSING', 'IN_PROGRESS', 'HOST TIMEOUT' => S::PENDING,
            'CANCELLED', 'CANCELED', 'VOIDED' => S::CANCELLED,
            'EXPIRED' => S::EXPIRED,
            'FAILED', 'DECLINED', 'NOT CAPTURED', 'NOT_CAPTURED', 'DENIED BY RISK' => S::FAILED,
            'REFUNDED' => S::REFUNDED,
            'PARTIALLY_REFUNDED' => S::PARTIALLY_REFUNDED,
            default => throw new PaymentVerificationException('Unrecognized AlRajhi financial status.'),
        };

        return new PaymentResponseDTO($this->name, $status, $transactionReference, $row->order_reference, (int) $row->amount, $row->currency, providerStatus: $result, metadata: ['bank_transaction_id' => $data['transId'] ?? $data['tranid'] ?? null]);
    }

    protected function callbackPayload(Request $request): array
    {
        return $this->cipher()->decrypt($this->requireText($request->all(), 'trandata'));
    }

    public function verifyWebhook(Request $request): bool
    {
        $this->ready();
        try {
            $this->callbackIdentity($this->callbackPayload($request));

            return true; // A bank inquiry, not CBC encryption, authenticates the financial result.
        } catch (ConfigurationException $exception) {
            throw $exception;
        } catch (PaymentException|\InvalidArgumentException|\ValueError) {
            return false;
        }
    }

    protected function callbackIdentity(array $payload): array
    {
        if ((string) ($payload['currencyCode'] ?? '') !== '682') {
            throw new PaymentVerificationException('AlRajhi callback currency missing/mismatched.');
        }

        return [$this->identifier(['track' => $payload['trackId'] ?? $payload['trackid'] ?? null], 'track'), $this->identifier(['payment' => $payload['paymentId'] ?? $payload['paymentid'] ?? $payload['paymentID'] ?? null], 'payment'), $this->remoteAmount($payload['amt'] ?? null, 'SAR'), 'SAR'];
    }
}
