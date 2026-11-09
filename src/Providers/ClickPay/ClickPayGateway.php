<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Providers\ClickPay;

use Illuminate\Http\Request;
use YacoubAlhaidari\ProviderPayments\AbstractGateway;
use YacoubAlhaidari\ProviderPayments\Contracts\ProcessesWebhooks;
use YacoubAlhaidari\ProviderPayments\Contracts\RecoversPayments;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\WebhookResultDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\WebhookVerificationException;

class ClickPayGateway extends AbstractGateway implements ProcessesWebhooks, RecoversPayments
{
    protected string $name = 'clickpay';

    protected function requiredCredentials(): array
    {
        return ['profile_id', 'server_key'];
    }

    public function isConfigured(): bool
    {
        return parent::isConfigured() && filter_var($this->credential('profile_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    private function api(string $path, array $data): array
    {
        return $this->send('POST', $path, ['profile_id' => (int) $this->credential('profile_id')] + $data, ['authorization' => $this->credential('server_key')]);
    }

    protected function createRemote(PaymentRequestDTO $r): PaymentResponseDTO
    {
        $data = $this->api('/payment/request', array_filter([
            'tran_type' => 'sale',
            'tran_class' => 'ecom',
            'cart_id' => $r->orderReference,
            'cart_currency' => $r->currency,
            'cart_amount' => $r->formattedAmount(),
            'cart_description' => $r->description ?? $r->orderReference,
            'return' => $r->successUrl,
            'callback' => $r->webhookUrl ?? (($this->config['webhook_url'] ?? null) ?: null),
            'hide_shipping' => true,
            'paypage_lang' => $r->language,
            'customer_details' => $r->customer?->toArray(),
        ], fn ($v) => $v !== null));

        return new PaymentResponseDTO($this->name, S::REQUIRES_ACTION, $this->requireText($data, 'tran_ref'), $r->orderReference, $r->amount, $r->currency, $this->checkout($this->requireText($data, 'redirect_url')));
    }

    public function getPaymentStatus(string $transactionReference): PaymentResponseDTO
    {
        $d = $this->api('/payment/query', ['tran_ref' => $transactionReference]);

        return $this->mapInquiry($d, $transactionReference);
    }

    private function mapInquiry(array $d, string $transactionReference): PaymentResponseDTO
    {
        if (($d['tran_ref'] ?? null) !== $transactionReference || (isset($d['profile_id']) && (string) $d['profile_id'] !== (string) $this->credential('profile_id'))) {
            throw new PaymentVerificationException('ClickPay reference/profile mismatch.');
        }
        $currency = $this->requireText($d, 'cart_currency');
        $state = (string) data_get($d, 'payment_result.response_status');
        $status = match ($state) {
            'A' => match (strtolower($d['tran_type'] ?? '')) {
                'sale', 'capture' => S::PAID,
                'auth' => S::AUTHORIZED,
                default => S::PENDING
            },
            'D', 'E', 'V', 'C', 'X' => S::FAILED,
            default => S::PENDING
        };

        return new PaymentResponseDTO($this->name, $status, $transactionReference, $this->requireText($d, 'cart_id'), $this->remoteAmount($d['cart_amount'] ?? null, $currency), $currency, providerStatus: $state);
    }

    public function getPaymentByOrderReference(string $orderReference): PaymentResponseDTO
    {
        $data = $this->api('/payment/query', ['cart_id' => $orderReference]);
        $matches = array_values(array_filter(array_is_list($data) ? $data : [$data], fn ($d) => is_array($d) && ($d['cart_id'] ?? null) === $orderReference && is_string($d['tran_ref'] ?? null) && $d['tran_ref'] !== ''));
        if (count($matches) !== 1) {
            throw new PaymentVerificationException('ClickPay recovery requires one unique matching transaction.');
        }

        return $this->mapInquiry($matches[0], $matches[0]['tran_ref']);
    }

    public function verifyWebhook(Request $request): bool
    {
        $key = (string) $this->credential('server_key');
        $s = $request->header('signature', '');

        return $key !== '' && is_string($s) && preg_match('/^[a-f0-9]{64}$/iD', $s) && hash_equals(hash_hmac('sha256', $request->getContent(), $key), strtolower($s));
    }

    public function processWebhook(Request $request): WebhookResultDTO
    {
        if (! $this->verifyWebhook($request)) {
            throw new WebhookVerificationException('Invalid ClickPay signature.');
        }
        $r = $this->getPaymentStatus($this->requireText($request->all(), 'tran_ref'));

        return new WebhookResultDTO($this->name, true, $r->status, $r->providerTransactionId, raw: ['payment' => $r]);
    }
}
