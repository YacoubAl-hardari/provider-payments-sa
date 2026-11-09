<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Providers\Telr;

use Illuminate\Http\Request;
use YacoubAlhaidari\ProviderPayments\AbstractGateway;
use YacoubAlhaidari\ProviderPayments\Contracts\ProcessesWebhooks;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\WebhookResultDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\WebhookVerificationException;

class TelrGateway extends AbstractGateway implements ProcessesWebhooks
{
    protected string $name = 'telr';

    protected function requiredCredentials(): array
    {
        return ['store_id', 'auth_key'];
    }

    private function api(array $data): array
    {
        return $this->send('POST', '/gateway/order.json', ['store' => $this->credential('store_id'), 'authkey' => $this->credential('auth_key')] + $data);
    }

    protected function createRemote(PaymentRequestDTO $r): PaymentResponseDTO
    {
        $customer = null;
        if ($r->customer) {
            $parts = preg_split('/\s+/u', trim($r->customer->name), 2);
            $customer = array_filter(['name' => ['forenames' => $parts[0], 'surname' => $parts[1] ?? ''], 'email' => $r->customer->email, 'phone' => $r->customer->phone, 'address' => array_filter(['line1' => $r->customer->street, 'city' => $r->customer->city, 'country' => $r->customer->country, 'areacode' => $r->customer->zip])]);
        }
        $d = $this->api(['method' => 'create', 'order' => ['cartid' => $r->orderReference, 'amount' => $r->formattedAmount(), 'currency' => $r->currency, 'description' => mb_substr($r->description ?? $r->orderReference, 0, 63), 'test' => (int) ($this->config['test_mode'] ?? true), 'trantype' => 'sale'], 'return' => ['authorised' => $r->successUrl, 'declined' => $r->failureUrl ?? $r->cancelUrl, 'cancelled' => $r->cancelUrl], 'customer' => $customer]);

        return new PaymentResponseDTO($this->name, S::REQUIRES_ACTION, $this->requireText($d, 'order.ref'), $r->orderReference, $r->amount, $r->currency, $this->checkout($this->requireText($d, 'order.url')));
    }

    public function getPaymentStatus(string $transactionReference): PaymentResponseDTO
    {
        $d = $this->api(['method' => 'check', 'order' => ['ref' => $transactionReference]]);
        $o = $d['order'] ?? [];
        if (($o['ref'] ?? null) !== $transactionReference || (isset($o['test']) && filter_var($o['test'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== (bool) ($this->config['test_mode'] ?? true))) {
            throw new PaymentVerificationException('Telr reference/environment mismatch.');
        }
        $currency = $this->requireText($o, 'currency');
        $code = (string) data_get($o, 'status.code');
        $s = match ($code) {
            '2' => S::AUTHORIZED,
            '3' => S::PAID,
            '-1' => S::EXPIRED,
            '-2', '-4' => S::CANCELLED,
            '-3' => S::FAILED,
            default => S::PENDING
        };

        return new PaymentResponseDTO($this->name, $s, $transactionReference, $this->requireText($o, 'cartid'), $this->remoteAmount($o['amount'] ?? null, $currency), $currency, providerStatus: $code);
    }

    public function verifyWebhook(Request $r): bool
    {
        $secret = (string) $this->credential('secret_key');
        $p = $r->all();
        $signature = $p['tran_check'] ?? null;
        if ($secret === '' || ! is_string($signature) || ! preg_match('/^[a-f0-9]{40}$/iD', $signature) || (string) ($p['tran_store'] ?? '') !== (string) $this->credential('store_id') || (string) ($p['tran_test'] ?? '') !== ((bool) ($this->config['test_mode'] ?? true) ? '1' : '0')) {
            return false;
        }
        $fields = ['tran_store', 'tran_type', 'tran_class', 'tran_test', 'tran_ref', 'tran_prevref', 'tran_firstref'];
        if (array_key_exists('tran_order', $p)) {
            $fields[] = 'tran_order';
        }
        $fields = array_merge($fields, ['tran_currency', 'tran_amount', 'tran_cartid', 'tran_desc', 'tran_status', 'tran_authcode', 'tran_authmessage']);
        $parts = [$secret];
        foreach ($fields as $f) {
            if (! is_scalar($p[$f] ?? '')) {
                return false;
            }
            $parts[] = trim((string) ($p[$f] ?? ''));
        }

        return hash_equals(sha1(implode(':', $parts)), strtolower($signature));
    }

    public function processWebhook(Request $r): WebhookResultDTO
    {
        if (! $this->verifyWebhook($r)) {
            throw new WebhookVerificationException('Invalid Telr advice.');
        }

        // tran_ref is NOT order.ref. Ledger resolves the local cart and queries its stored order.ref.
        return new WebhookResultDTO($this->name, true, S::PENDING, (string) $r->input('tran_ref'), raw: ['cart_id' => $r->input('tran_cartid')]);
    }
}
