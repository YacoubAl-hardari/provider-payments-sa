<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Providers\Tamara;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use YacoubAlhaidari\ProviderPayments\AbstractGateway;
use YacoubAlhaidari\ProviderPayments\Contracts\AuthorizesPayments;
use YacoubAlhaidari\ProviderPayments\Contracts\CancelsPayments;
use YacoubAlhaidari\ProviderPayments\Contracts\ChecksEligibility;
use YacoubAlhaidari\ProviderPayments\Contracts\ProcessesWebhooks;
use YacoubAlhaidari\ProviderPayments\Contracts\RefundsPayments;
use YacoubAlhaidari\ProviderPayments\DTOs\AuthorizeRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\AuthorizeResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CancelRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CancelResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\EligibilityRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\WebhookResultDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentRequestException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\UnsupportedOperationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\WebhookVerificationException;
use YacoubAlhaidari\ProviderPayments\Repositories\PaymentLedger;
use YacoubAlhaidari\ProviderPayments\Security\Jwt;
use YacoubAlhaidari\ProviderPayments\Support\Money;

class TamaraGateway extends AbstractGateway implements AuthorizesPayments, CancelsPayments, ChecksEligibility, ProcessesWebhooks, RefundsPayments
{
    protected string $name = 'tamara';

    protected array $capabilities = ['create' => true, 'verify' => true, 'webhook' => true, 'refund' => true, 'capture' => true, 'authorize' => true, 'eligibility' => true, 'cancel' => true];

    protected function requiredCredentials(): array
    {
        return ['api_token'];
    }

    private function api(string $method, string $path, array $data = []): array
    {
        return $this->send($method, $path, $data, ['Authorization' => 'Bearer '.$this->credential('api_token')], $path === '/pre-checkout/v1/eligibility' ? max(0.05, (int) ($this->config['precheck_timeout_ms'] ?? 200) / 1000) : null);
    }

    protected function createRemote(PaymentRequestDTO $r): PaymentResponseDTO
    {
        $this->bnpl($r);
        $c = $r->customer;
        $parts = preg_split('/\s+/u', trim($c->name), 2);
        $money = fn ($n) => ['amount' => Money::decimal($n, $r->currency), 'currency' => $r->currency];
        $address = ['first_name' => $parts[0], 'last_name' => $parts[1] ?? $parts[0], 'line1' => $c->street, 'city' => $c->city, 'country_code' => $c->country, 'phone_number' => $c->phone];
        $items = array_map(fn ($i) => ['reference_id' => $i->sku ?? $i->name, 'sku' => $i->sku ?? $i->name, 'name' => $i->name, 'type' => 'Physical', 'quantity' => $i->quantity, 'unit_price' => $money($i->unitPrice), 'total_amount' => $money($i->total()), 'tax_amount' => $money($i->taxAmount), 'discount_amount' => $money($i->discountAmount)], $r->items);
        $payload = ['total_amount' => $money($r->amount), 'shipping_amount' => $money($r->shippingAmount), 'tax_amount' => $money($r->taxAmount), 'order_reference_id' => $r->orderReference, 'order_number' => $r->orderReference, 'discount' => ['name' => $r->metadata['discount_name'] ?? 'Discount', 'amount' => $money($r->discountAmount)], 'items' => $items, 'consumer' => ['first_name' => $parts[0], 'last_name' => $parts[1] ?? $parts[0], 'email' => $c->email, 'phone_number' => $c->phone], 'country_code' => $c->country, 'description' => $r->description ?? $r->orderReference, 'merchant_url' => ['success' => $r->successUrl, 'cancel' => $r->cancelUrl, 'failure' => $r->failureUrl ?? $r->cancelUrl, 'notification' => $r->webhookUrl ?? (($this->config['webhook_url'] ?? null) ?: null)], 'payment_type' => 'PAY_BY_INSTALMENTS', 'instalments' => $this->config['instalments'] ?? 3, 'billing_address' => $address, 'shipping_address' => $address, 'locale' => $r->language === 'ar' ? 'ar_SA' : 'en_US', 'is_mobile' => $r->isMobile, 'platform' => 'Provider Payments'];

        if ($r->discountAmount === 0) {
            unset($payload['discount']);
        }
        $d = $this->api('POST', '/checkout', $payload);

        return new PaymentResponseDTO($this->name, S::REQUIRES_ACTION, $this->requireText($d, 'order_id'), $r->orderReference, $r->amount, $r->currency, $this->checkout($this->requireText($d, 'checkout_url')), $this->requireText($d, 'checkout_id'));
    }

    public function getPaymentStatus(string $transactionReference): PaymentResponseDTO
    {
        $d = $this->api('GET', '/merchants/orders/'.rawurlencode($transactionReference));
        if (($d['order_id'] ?? null) !== $transactionReference) {
            throw new PaymentVerificationException('Tamara reference mismatch.');
        }
        $currency = $this->requireText($d, 'total_amount.currency');
        $status = strtolower($this->requireText($d, 'status'));
        $s = match ($status) {
            'authorised', 'authorized' => S::AUTHORIZED,
            'fully_captured', 'captured' => S::CAPTURED,
            'fully_refunded', 'refunded' => S::REFUNDED,
            'partially_refunded' => S::PARTIALLY_REFUNDED,
            'declined', 'failed' => S::FAILED,
            'canceled', 'cancelled' => S::CANCELLED,
            'expired' => S::EXPIRED,
            default => S::PENDING
        };

        return new PaymentResponseDTO($this->name, $s, $transactionReference, $this->requireText($d, 'order_reference_id'), $this->remoteAmount(data_get($d, 'total_amount.amount'), $currency), $currency, providerStatus: $status);
    }

    public function authorize(AuthorizeRequestDTO $r): AuthorizeResponseDTO
    {
        if ($this->config['auto_authorisation'] ?? true) {
            throw new UnsupportedOperationException('Wait for automatic Tamara authorization; no manual request sent.');
        }
        if (! $r->providerOrderId) {
            throw new \InvalidArgumentException('Provider order ID required.');
        }

        return app(PaymentLedger::class)->authorize($this->name, $r, function () use ($r) {
            $d = $this->api('POST', '/orders/'.rawurlencode($r->providerOrderId).'/authorise');
            if (! in_array(strtolower($d['status'] ?? ''), ['authorised', 'authorized'], true)) {
                throw new PaymentRequestException('Tamara authorization not confirmed.');
            }
            if (($d['order_id'] ?? null) !== $r->providerOrderId) {
                throw new PaymentVerificationException('Tamara authorization reference mismatch.');
            }

            return new AuthorizeResponseDTO($this->name, true, providerTransactionId: $r->providerOrderId, amount: $r->amount, currency: $r->currency);
        });
    }

    public function capture(CaptureRequestDTO $r): CaptureResponseDTO
    {
        if (empty($r->metadata['items']) || empty($r->metadata['shipping_info'])) {
            throw new \InvalidArgumentException('Tamara capture items and shipping_info required.');
        }

        return app(PaymentLedger::class)->capture($this->name, $r, function () use ($r) {
            $m = fn ($n) => ['amount' => Money::decimal($n, $r->currency), 'currency' => $r->currency];
            $d = $this->api('POST', '/payments/capture', ['order_id' => $r->transactionReference, 'total_amount' => $m($r->amount), 'items' => $r->metadata['items'], 'shipping_info' => $r->metadata['shipping_info'], 'discount_amount' => $m($r->discountAmount), 'shipping_amount' => $m($r->shippingAmount), 'tax_amount' => $m($r->taxAmount)]);
            if (($d['order_id'] ?? null) !== $r->transactionReference) {
                throw new PaymentVerificationException('Tamara capture reference mismatch.');
            }

            return new CaptureResponseDTO($this->name, true, $this->requireText($d, 'capture_id'), $r->transactionReference, $r->amount, $r->currency);
        });
    }

    public function refund(RefundRequestDTO $r): RefundResponseDTO
    {
        return app(PaymentLedger::class)->refund($this->name, $r, function () use ($r) {
            $d = $this->api('POST', '/payments/simplified-refund/'.rawurlencode($r->transactionReference), ['total_amount' => ['amount' => Money::decimal($r->amount, $r->currency), 'currency' => $r->currency], 'comment' => $r->reason ?? 'Refund']);
            if (($d['order_id'] ?? null) !== $r->transactionReference || data_get($d, 'refunded_amount.currency') !== $r->currency || $this->remoteAmount(data_get($d, 'refunded_amount.amount'), $r->currency) !== $r->amount) {
                throw new PaymentVerificationException('Tamara refund identity or amount mismatch.');
            }

            return new RefundResponseDTO($this->name, true, $this->requireText($d, 'refund_id'), $r->transactionReference, $r->amount, $r->currency);
        });
    }

    public function cancel(CancelRequestDTO $r): CancelResponseDTO
    {
        return app(PaymentLedger::class)->cancel($this->name, $r, function () use ($r) {
            $d = $this->api('POST', '/orders/'.rawurlencode($r->transactionReference).'/cancel', ['total_amount' => ['amount' => Money::decimal($r->amount, $r->currency), 'currency' => $r->currency]]);
            if (($d['order_id'] ?? null) !== $r->transactionReference) {
                throw new PaymentVerificationException('Tamara cancellation reference mismatch.');
            }

            return new CancelResponseDTO($this->name, true, $this->requireText($d, 'cancel_id'), $r->transactionReference, $r->amount, $r->currency);
        });
    }

    public function checkEligibility(EligibilityRequestDTO $r): bool
    {
        $this->ready(true);
        if (! ($this->config['precheck_enabled'] ?? true) || ! $r->customer?->phone) {
            return true;
        }
        $scope = hash('sha256', (string) ($this->config['base_url'] ?? '').'|'.(string) $this->credential('api_token'));
        $key = 'ppsa:tamara:eligibility:'.hash('sha256', $scope.'|'.$r->customer->phone.'|'.$r->customer->email.'|'.$r->amount.'|'.$r->currency);
        $cooldown = 'ppsa:tamara:cooldown:'.$scope;
        $cacheSeconds = max(0, (int) ($this->config['precheck_cache_seconds'] ?? 60));
        $cooldownSeconds = max(0, (int) ($this->config['precheck_failure_cooldown_seconds'] ?? 10));
        $cache = Cache::getFacadeRoot();
        try {
            if ($cacheSeconds > 0 && is_bool($cached = $cache->get($key))) {
                return $cached;
            }
            if ($cooldownSeconds > 0 && $cache->has($cooldown)) {
                return true;
            }
        } catch (\Throwable) { /* Cache failures never establish payment or block display. */
        }
        try {
            $d = $this->api('POST', '/pre-checkout/v1/eligibility', ['order' => ['amount' => Money::decimal($r->amount, $r->currency), 'currency' => $r->currency], 'customer' => ['phone_number' => $r->customer->phone, 'email' => $r->customer->email]]);
            if (! is_bool($d['is_eligible'] ?? null)) {
                return true;
            }
            if ($cacheSeconds > 0) {
                try {
                    $cache->put($key, $d['is_eligible'], $cacheSeconds);
                } catch (\Throwable) {
                }
            }

            return $d['is_eligible'];
        } catch (PaymentRequestException) {
            if ($cooldownSeconds > 0) {
                try {
                    $cache->put($cooldown, true, $cooldownSeconds);
                } catch (\Throwable) {
                }
            }

            return true;
        }
    }

    public function verifyWebhook(Request $r): bool
    {
        $token = $r->bearerToken() ?? $r->query('tamaraToken', '');

        return is_string($token) && Jwt::verify($token, (string) $this->credential('notification_token'));
    }

    public function processWebhook(Request $r): WebhookResultDTO
    {
        if (! $this->verifyWebhook($r)) {
            throw new WebhookVerificationException('Invalid Tamara JWT.');
        }
        $p = $this->getPaymentStatus($this->requireText($r->all(), 'order_id'));

        return new WebhookResultDTO($this->name, true, $p->status, $p->providerTransactionId, raw: ['payment' => $p]);
    }
}
