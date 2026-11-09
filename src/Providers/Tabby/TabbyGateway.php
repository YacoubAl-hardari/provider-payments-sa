<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Providers\Tabby;

use Illuminate\Http\Request;
use YacoubAlhaidari\ProviderPayments\AbstractGateway;
use YacoubAlhaidari\ProviderPayments\Contracts\CancelsPayments;
use YacoubAlhaidari\ProviderPayments\Contracts\CapturesPayments;
use YacoubAlhaidari\ProviderPayments\Contracts\ProcessesWebhooks;
use YacoubAlhaidari\ProviderPayments\Contracts\RefundsPayments;
use YacoubAlhaidari\ProviderPayments\DTOs\CancelRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CancelResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\WebhookResultDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentRequestException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\WebhookVerificationException;
use YacoubAlhaidari\ProviderPayments\Repositories\PaymentLedger;
use YacoubAlhaidari\ProviderPayments\Support\Money;

class TabbyGateway extends AbstractGateway implements CancelsPayments, CapturesPayments, ProcessesWebhooks, RefundsPayments
{
    protected string $name = 'tabby';

    protected array $capabilities = ['create' => true, 'verify' => true, 'webhook' => true, 'refund' => true, 'capture' => true, 'cancel' => true];

    protected function requiredCredentials(): array
    {
        return ['merchant_code', 'secret_key'];
    }

    private function api(string $method, string $path, array $data = []): array
    {
        return $this->send($method, '/api/v2'.$path, $data, ['Authorization' => 'Bearer '.$this->credential('secret_key')]);
    }

    protected function createRemote(PaymentRequestDTO $r): PaymentResponseDTO
    {
        $this->bnpl($r);
        $c = $r->customer;
        $buyer = ['name' => $c->name, 'phone' => $c->phone];
        if (str_starts_with((string) $this->credential('secret_key'), 'sk_test_')) {
            $buyer['email'] = $c->email;
        }
        $payload = ['payment' => ['amount' => $r->formattedAmount(), 'currency' => $r->currency, 'description' => $r->description ?? $r->orderReference, 'buyer' => $buyer, 'buyer_history' => $r->metadata['buyer_history'] ?? [], 'order' => ['reference_id' => $r->orderReference, 'items' => $this->items($r), 'tax_amount' => Money::decimal($r->taxAmount, $r->currency), 'shipping_amount' => Money::decimal($r->shippingAmount, $r->currency), 'discount_amount' => Money::decimal($r->discountAmount, $r->currency)], 'order_history' => $r->metadata['order_history'] ?? [], 'shipping_address' => ['city' => $c->city, 'address' => $c->street, 'zip' => $c->zip ?? '']], 'merchant_code' => $this->credential('merchant_code'), 'lang' => $r->language, 'merchant_urls' => ['success' => $r->successUrl, 'failure' => $r->failureUrl ?? $r->cancelUrl, 'cancel' => $r->cancelUrl]];
        if ($payload['payment']['buyer_history'] === []) {
            unset($payload['payment']['buyer_history']);
        }
        $d = $this->api('POST', '/checkout', $payload);
        $url = data_get($d, 'configuration.available_products.installments.0.web_url');
        if (! is_string($url)) {
            throw new PaymentRequestException('Tabby checkout unavailable or rejected.');
        }

        return new PaymentResponseDTO($this->name, S::REQUIRES_ACTION, $this->requireText($d, 'payment.id'), $r->orderReference, $r->amount, $r->currency, $this->checkout($url), $this->requireText($d, 'id'));
    }

    public function getPaymentStatus(string $transactionReference): PaymentResponseDTO
    {
        $d = $this->api('GET', '/payments/'.rawurlencode($transactionReference));
        if (($d['id'] ?? null) !== $transactionReference) {
            throw new PaymentVerificationException('Tabby reference mismatch.');
        }
        $currency = $this->requireText($d, 'currency');
        $status = strtoupper($this->requireText($d, 'status'));
        $s = match ($status) {
            'AUTHORIZED' => S::AUTHORIZED,
            'CLOSED' => S::PENDING,
            'REJECTED' => S::FAILED,
            'EXPIRED' => S::EXPIRED,
            default => S::PENDING
        };
        $total = $this->remoteAmount($d['amount'] ?? null, $currency);
        $refunded = 0;
        $captured = 0;
        foreach ($d['captures'] ?? [] as $capture) {
            $next = $this->remoteAmount($capture['amount'] ?? null, $currency);
            if ($next > $total - $captured) {
                throw new PaymentVerificationException('Tabby captures exceed payment amount.');
            }
            $captured += $next;
        }
        if ($status === 'CLOSED') {
            $s = $captured === $total ? S::CAPTURED : ($captured === 0 ? S::CANCELLED : S::PENDING);
        }
        foreach ($d['refunds'] ?? [] as $refund) {
            $next = $this->remoteAmount($refund['amount'] ?? null, $currency);
            if ($next > $total - $refunded || $next > $captured - $refunded) {
                throw new PaymentVerificationException('Tabby refunds exceed captured balance.');
            }
            $refunded += $next;
        }
        if ($refunded > 0) {
            $s = $refunded >= $total ? S::REFUNDED : S::PARTIALLY_REFUNDED;
        }

        return new PaymentResponseDTO($this->name, $s, $transactionReference, $this->requireText($d, 'order.reference_id'), $total, $currency, providerStatus: $status, metadata: ['refunded_amount' => $refunded]);
    }

    public function capture(CaptureRequestDTO $r): CaptureResponseDTO
    {
        $items = $this->captureItems($r->metadata['items'] ?? [], $r->currency);

        return app(PaymentLedger::class)->capture($this->name, $r, function () use ($r, $items) {
            $d = $this->api('POST', '/payments/'.rawurlencode($r->transactionReference).'/captures', ['amount' => Money::decimal($r->amount, $r->currency), 'reference_id' => $r->metadata['reference_id'], 'tax_amount' => Money::decimal($r->taxAmount, $r->currency), 'shipping_amount' => Money::decimal($r->shippingAmount, $r->currency), 'discount_amount' => Money::decimal($r->discountAmount, $r->currency), 'items' => $items]);
            $capture = null;
            foreach ($d['captures'] ?? [] as $candidate) {
                if (($candidate['reference_id'] ?? null) === $r->metadata['reference_id']) {
                    $capture = $candidate;
                }
            }
            if (($d['id'] ?? null) !== $r->transactionReference || strtoupper($d['status'] ?? '') !== 'CLOSED' || ($d['currency'] ?? null) !== $r->currency || ! $capture || $this->remoteAmount($capture['amount'] ?? null, $r->currency) !== $r->amount) {
                throw new PaymentRequestException('Tabby capture not confirmed; inquire before retry.');
            }

            return new CaptureResponseDTO($this->name, true, $this->requireText($capture, 'id'), $r->transactionReference, $r->amount, $r->currency);
        });
    }

    private function captureItems(array $items, string $currency): array
    {
        $result = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException('Capture items must be arrays.');
            }
            $row = array_filter($item, fn ($value) => $value !== null && $value !== '');
            $price = $row['unit_price'] ?? '0.00';
            $discount = $row['discount_amount'] ?? '0.00';
            if ((! is_string($price) && ! is_int($price)) || (! is_string($discount) && ! is_int($discount))) {
                throw new \InvalidArgumentException('Capture item money must be decimal strings or integers, not floats.');
            }
            $row['title'] = $row['title'] ?? $row['product_name'] ?? 'Product';
            unset($row['product_name']);
            $row['reference_id'] = $row['reference_id'] ?? 'item-'.($index + 1);
            $row['quantity'] = $row['quantity'] ?? 1;
            if (! is_int($row['quantity']) || $row['quantity'] < 1) {
                throw new \InvalidArgumentException('Capture item quantity must be a positive integer.');
            }
            $row['description'] = $row['description'] ?? '-';
            $row['category'] = $row['category'] ?? 'other';
            $row['unit_price'] = Money::decimal(Money::minor($price, $currency), $currency);
            $row['discount_amount'] = Money::decimal(Money::minor($discount, $currency), $currency);
            $result[] = $row;
        }

        return $result;
    }

    public function refund(RefundRequestDTO $r): RefundResponseDTO
    {
        return app(PaymentLedger::class)->refund($this->name, $r, function () use ($r) {
            $d = $this->api('POST', '/payments/'.rawurlencode($r->transactionReference).'/refunds', ['amount' => Money::decimal($r->amount, $r->currency), 'reference_id' => $r->refundReference, 'reason' => $r->reason ?? 'Refund']);
            $refund = null;
            foreach ($d['refunds'] ?? [] as $candidate) {
                if (($candidate['reference_id'] ?? null) === $r->refundReference) {
                    $refund = $candidate;
                }
            }
            if (($d['id'] ?? null) !== $r->transactionReference || (isset($d['currency']) && $d['currency'] !== $r->currency) || ! $refund || $this->remoteAmount($refund['amount'] ?? null, $r->currency) !== $r->amount) {
                throw new PaymentRequestException('Tabby refund not confirmed; inquire before retry.');
            }

            return new RefundResponseDTO($this->name, true, $this->requireText($refund, 'id'), $r->transactionReference, $r->amount, $r->currency);
        });
    }

    public function cancel(CancelRequestDTO $r): CancelResponseDTO
    {
        return app(PaymentLedger::class)->cancel($this->name, $r, function () use ($r) {
            $d = $this->api('GET', '/payments/'.rawurlencode($r->transactionReference));
            if (($d['id'] ?? null) !== $r->transactionReference || ! empty($d['captures']) || ! empty($d['refunds'])) {
                throw new PaymentVerificationException('Cannot close captured/refunded Tabby payment.');
            }
            $closed = $this->api('POST', '/payments/'.rawurlencode($r->transactionReference).'/close');
            if (($closed['id'] ?? null) !== $r->transactionReference || strtoupper($closed['status'] ?? '') !== 'CLOSED' || ! empty($closed['captures'])) {
                throw new PaymentVerificationException('Tabby cancellation not confirmed.');
            }

            return new CancelResponseDTO($this->name, true, $r->reference, $r->transactionReference, $r->amount, $r->currency);
        });
    }

    public function verifyWebhook(Request $r): bool
    {
        $title = $this->config['webhook_header'] ?? '';
        $key = (string) $this->credential('webhook_secret');
        $v = $title ? $r->header($title) : null;

        return $key !== '' && is_string($v) && hash_equals($key, $v);
    }

    public function processWebhook(Request $r): WebhookResultDTO
    {
        if (! $this->verifyWebhook($r)) {
            throw new WebhookVerificationException('Invalid Tabby header.');
        }
        $p = $this->getPaymentStatus($this->requireText($r->all(), 'id'));

        return new WebhookResultDTO($this->name, true, $p->status, $p->providerTransactionId, raw: ['payment' => $p]);
    }
}
