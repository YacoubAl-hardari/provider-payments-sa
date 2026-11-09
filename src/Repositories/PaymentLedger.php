<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Repositories;

use Closure;
use Illuminate\Support\Facades\DB;
use YacoubAlhaidari\ProviderPayments\DTOs\AuthorizeRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\AuthorizeResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CancelRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CancelResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\CaptureResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\RefundResponseDTO;
use YacoubAlhaidari\ProviderPayments\Enums\PaymentStatus as S;
use YacoubAlhaidari\ProviderPayments\Events\PaymentCreated;
use YacoubAlhaidari\ProviderPayments\Events\PaymentStatusChanged;
use YacoubAlhaidari\ProviderPayments\Events\WebhookReceived;
use YacoubAlhaidari\ProviderPayments\Exceptions\ConfigurationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentStateException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentTransportException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Support\StateMachine;

final class PaymentLedger
{
    public function eventKey(PaymentResponseDTO $r): string
    {
        return hash('sha256', json_encode([$r->providerTransactionId, $r->status->value, $r->amount, $r->currency, $r->metadata['refunded_amount'] ?? null], JSON_THROW_ON_ERROR));
    }

    private function enabled(): void
    {
        if (! config('provider-payments.persistence.enabled')) {
            throw new ConfigurationException('Persistence must be enabled for webhooks and money operations.');
        }
    }

    public function create(string $provider, PaymentRequestDTO $request, Closure $remote): PaymentResponseDTO
    {
        $this->enabled();
        $query = DB::table('ppsa_transactions');
        $fingerprint = hash('sha256', serialize($request));
        $inserted = $query->insertOrIgnore(['provider' => $provider, 'order_reference' => $request->orderReference, 'fingerprint' => $fingerprint, 'amount' => $request->amount, 'currency' => $request->currency, 'status' => 'pending', 'creation_state' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
        $row = $query->where('provider', $provider)->where('order_reference', $request->orderReference)->first();
        if (! $row || ! hash_equals($row->fingerprint, $fingerprint)) {
            throw new PaymentStateException('Idempotency key reused with different payment data.');
        }
        if (! $inserted) {
            if ($row->creation_state !== 'ready') {
                throw new PaymentStateException('Previous creation outcome unknown or in progress; reconcile before retry.');
            }

            return $this->response($row);
        }
        try {
            $r = $remote();
        } catch (PaymentTransportException $exception) {
            if ($exception->failedBeforeSending) {
                DB::table('ppsa_transactions')->where('id', $row->id)
                    ->update(['status' => 'failed', 'creation_state' => 'failed', 'updated_at' => now()]);
            }

            throw $exception;
        }
        if (! $r->providerTransactionId) {
            throw new PaymentVerificationException('Creation missing provider reference.');
        }
        DB::table('ppsa_transactions')->where('id', $row->id)->update(['provider_reference' => $r->providerTransactionId, 'checkout_url' => $r->checkoutUrl, 'session_id' => $r->sessionId, 'status' => $r->status->value, 'creation_state' => 'ready', 'updated_at' => now()]);
        event(new PaymentCreated($provider, $request->orderReference, $request->amount, $request->currency, $r->providerTransactionId));

        return $r;
    }

    public function recover(string $provider, string $orderReference, PaymentResponseDTO $remote): PaymentResponseDTO
    {
        $this->enabled();

        return DB::transaction(function () use ($provider, $orderReference, $remote) {
            $row = DB::table('ppsa_transactions')->where('provider', $provider)->where('order_reference', $orderReference)->lockForUpdate()->first();
            if (! $row || $remote->provider !== $provider || $remote->orderReference !== $orderReference || $remote->amount !== (int) $row->amount || $remote->currency !== $row->currency || ! $remote->providerTransactionId || ($row->provider_reference && $row->provider_reference !== $remote->providerTransactionId)) {
                throw new PaymentVerificationException('Recovered payment identity/amount/currency mismatch.');
            }
            DB::table('ppsa_transactions')->where('id', $row->id)->update(['provider_reference' => $remote->providerTransactionId, 'creation_state' => 'ready', 'updated_at' => now()]);
            $this->accept($provider, $this->eventKey($remote), $remote);

            return $this->response($this->find($provider, $remote->providerTransactionId));
        });
    }

    public function find(string $provider, ?string $ref, ?string $cart = null): object
    {
        $this->enabled();
        $q = DB::table('ppsa_transactions')->where('provider', $provider);
        if ($cart !== null) {
            $q->where('order_reference', $cart);
        } else {
            $q->where('provider_reference', $ref);
        }

        return $q->first() ?? throw new PaymentVerificationException('Unknown payment reference.');
    }

    public function response(object $row): PaymentResponseDTO
    {
        return new PaymentResponseDTO($row->provider, S::from($row->status), $row->provider_reference, $row->order_reference, (int) $row->amount, $row->currency, $row->checkout_url, $row->session_id, metadata: ['refunded_amount' => (int) $row->refunded_amount, 'refund_balance_known' => (bool) $row->refund_balance_known]);
    }

    public function accept(string $provider, string $eventKey, PaymentResponseDTO $r): bool
    {
        $this->enabled();

        return DB::transaction(function () use ($provider, $eventKey, $r) {
            $row = DB::table('ppsa_transactions')->where('provider', $provider)->where('provider_reference', $r->providerTransactionId)->lockForUpdate()->first();
            if (! $row || $r->provider !== $provider || $row->order_reference !== $r->orderReference || (int) $row->amount !== $r->amount || $row->currency !== $r->currency) {
                throw new PaymentVerificationException('Payment identity, order, amount or currency mismatch.');
            }
            // Even a repeated amount-less partial-refund notice must revoke a known balance.
            if ($r->status === S::PARTIALLY_REFUNDED && ! isset($r->metadata['refunded_amount']) && in_array($row->status, ['paid', 'captured', 'partially_refunded'], true)) {
                DB::table('ppsa_transactions')->where('id', $row->id)->update(['refund_balance_known' => false, 'updated_at' => now()]);
            }
            $inserted = DB::table('ppsa_webhook_events')->insertOrIgnore(['provider' => $provider, 'event_key' => $eventKey, 'state' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
            if (! $inserted) {
                return false;
            }
            $from = S::from($row->status);
            $stale = false;
            try {
                if (! (in_array($from, [S::PENDING, S::REQUIRES_ACTION, S::AUTHORIZED], true) && in_array($r->status, [S::PARTIALLY_REFUNDED, S::REFUNDED], true))) {
                    StateMachine::assert($from, $r->status);
                }
            } catch (PaymentStateException) {
                $stale = true;
            }
            $refundChanged = isset($r->metadata['refunded_amount']) && (int) $r->metadata['refunded_amount'] > (int) $row->refunded_amount;
            if (isset($r->metadata['refunded_amount']) && ((int) $r->metadata['refunded_amount'] < 0 || (int) $r->metadata['refunded_amount'] > (int) $row->amount)) {
                throw new PaymentVerificationException('Invalid cumulative refund balance.');
            }
            if (! $stale && ($from !== $r->status || $refundChanged || (isset($r->metadata['refunded_amount']) && ! $row->refund_balance_known))) {
                $values = ['status' => $r->status->value, 'updated_at' => now()];
                if (isset($r->metadata['refunded_amount'])) {
                    $values['refunded_amount'] = max((int) $row->refunded_amount, (int) $r->metadata['refunded_amount']);
                    $values['refund_balance_known'] = true;
                }
                if ($r->status === S::REFUNDED) {
                    $values['refunded_amount'] = (int) $row->amount;
                    $values['refund_balance_known'] = true;
                }
                if ($r->status === S::PARTIALLY_REFUNDED && ! isset($r->metadata['refunded_amount'])) {
                    // Tamara's retrieved order may omit refund totals. Block further local refunds until reconciliation.
                    $values['refund_balance_known'] = false;
                }
                DB::table('ppsa_transactions')->where('id', $row->id)->update($values);
                event(new WebhookReceived($provider, ['transaction_reference' => $r->providerTransactionId, 'status' => $r->status->value]));
                if ($from !== $r->status || $refundChanged) {
                    $this->changed($r);
                }
            }
            DB::table('ppsa_webhook_events')->where('provider', $provider)->where('event_key', $eventKey)->update(['state' => $stale ? 'ignored' : 'processed', 'updated_at' => now()]);

            return ! $stale;
        });
    }

    private function changed(PaymentResponseDTO $r): void
    {
        // Synchronous listeners participate in this DB transaction. Throwing rolls back event receipt.
        // Queue external effects with afterCommit and an application outbox for exactly-once delivery.
        event(new PaymentStatusChanged($r->provider, (string) $r->providerTransactionId, $r->status));
        $class = match ($r->status) {
            S::AUTHORIZED => 'PaymentAuthorized',
            S::PAID, S::CAPTURED => 'PaymentSucceeded',
            S::FAILED => 'PaymentFailed',
            S::CANCELLED => 'PaymentCancelled',
            S::PARTIALLY_REFUNDED => 'PaymentPartiallyRefunded',
            S::REFUNDED => 'PaymentRefunded',
            default => null
        };
        if ($class) {
            $class = 'YacoubAlhaidari\\ProviderPayments\\Events\\'.$class;
            event(new $class($r->provider, (string) $r->providerTransactionId));
        }
    }

    public function refund(string $provider, RefundRequestDTO $r, Closure $remote): RefundResponseDTO
    {
        return $this->operation($provider, 'refund', $r, $r->refundReference, $remote);
    }

    public function capture(string $provider, CaptureRequestDTO $r, Closure $remote): CaptureResponseDTO
    {
        return $this->operation($provider, 'capture', $r, $r->metadata['reference_id'] ?? null, $remote);
    }

    public function authorize(string $provider, AuthorizeRequestDTO $r, Closure $remote): AuthorizeResponseDTO
    {
        $request = new CaptureRequestDTO((string) $r->providerOrderId, $r->amount, $r->currency, metadata: $r->metadata);

        return $this->operation($provider, 'authorize', $request, $r->metadata['reference_id'] ?? null, $remote);
    }

    public function cancel(string $provider, CancelRequestDTO $r, Closure $remote): CancelResponseDTO
    {
        $request = new CaptureRequestDTO($r->transactionReference, $r->amount, $r->currency);

        return $this->operation($provider, 'cancel', $request, $r->reference, $remote);
    }

    private function operation(string $provider, string $kind, RefundRequestDTO|CaptureRequestDTO $r, ?string $reference, Closure $remote): RefundResponseDTO|CaptureResponseDTO|AuthorizeResponseDTO|CancelResponseDTO
    {
        $this->enabled();
        if (! $reference || strlen($reference) > 128) {
            throw new \InvalidArgumentException('Stable operation reference required.');
        }
        $reservation = DB::transaction(function () use ($provider, $kind, $r, $reference) {
            $row = DB::table('ppsa_transactions')->where('provider', $provider)->where('provider_reference', $r->transactionReference)->lockForUpdate()->first();
            if (! $row || $row->currency !== $r->currency) {
                throw new PaymentStateException('Unknown payment or currency mismatch.');
            }
            $old = DB::table('ppsa_operations')->where('transaction_id', $row->id)->where('kind', $kind)->where('reference', $reference)->first();
            if ($old) {
                if ((int) $old->amount !== $r->amount) {
                    throw new PaymentStateException('Operation reference amount mismatch.');
                }
                if ($old->state !== 'confirmed') {
                    throw new PaymentStateException('Operation reserved; reconcile unknown outcome before retry.');
                }

                return [$row, $old, true];
            }
            $ops = DB::table('ppsa_operations')->where('transaction_id', $row->id);
            if ($ops->where('state', 'reserved')->exists()) {
                throw new PaymentStateException('Another money operation is in progress or unknown.');
            }
            if ($kind === 'refund') {
                if (! $row->refund_balance_known || ! in_array($row->status, ['paid', 'captured', 'partially_refunded'], true) || $r->amount > (int) $row->amount - (int) $row->refunded_amount) {
                    throw new PaymentStateException('Refund exceeds captured balance or invalid state.');
                }
            } elseif ($kind === 'authorize' && (! in_array($row->status, ['pending', 'requires_action'], true) || $r->amount !== (int) $row->amount)) {
                throw new PaymentStateException('Payment cannot be manually authorized.');
            } elseif ($kind === 'cancel' && (! in_array($row->status, ['pending', 'requires_action', 'authorized'], true) || $r->amount !== (int) $row->amount)) {
                throw new PaymentStateException('Captured payment cannot be cancelled.');
            } elseif ($kind === 'capture' && ($row->status !== 'authorized' || $r->amount !== (int) $row->amount)) {
                throw new PaymentStateException('Only one full authorized capture is supported.');
            }
            $id = DB::table('ppsa_operations')->insertGetId(['transaction_id' => $row->id, 'kind' => $kind, 'reference' => $reference, 'amount' => $r->amount, 'state' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);

            return [$row, (object) ['id' => $id], false];
        });
        [$row, $operation, $cached] = $reservation;
        if ($cached && $kind === 'cancel') {
            return new CancelResponseDTO($provider, true, $operation->remote_id, $r->transactionReference, $r->amount, $r->currency);
        }
        if ($cached && $kind === 'authorize') {
            return new AuthorizeResponseDTO($provider, true, providerTransactionId: $r->transactionReference, amount: $r->amount, currency: $r->currency);
        }
        if ($cached) {
            return $kind === 'refund' ? new RefundResponseDTO($provider, true, $operation->remote_id, $r->transactionReference, $r->amount, $r->currency) : new CaptureResponseDTO($provider, true, $operation->remote_id, $r->transactionReference, $r->amount, $r->currency);
        }
        // Reservation survives network, parsing and local validation failures.
        $result = $remote();
        if (! $result->success) {
            throw new PaymentStateException('Provider operation not confirmed.');
        }
        DB::transaction(function () use ($row, $operation, $result, $kind, $r) {
            $current = DB::table('ppsa_transactions')->where('id', $row->id)->lockForUpdate()->first();
            $values = ['updated_at' => now()];
            if ($kind === 'refund') {
                // Concurrent verified refund webhook may already have advanced the balance.
                $total = max((int) $current->refunded_amount, (int) $row->refunded_amount + $r->amount);
                $status = $total >= (int) $row->amount ? S::REFUNDED : S::PARTIALLY_REFUNDED;
                $values['refunded_amount'] = $total;
            } else {
                $status = match ($kind) {
                    'authorize' => S::AUTHORIZED,
                    'cancel' => S::CANCELLED,
                    default => S::CAPTURED
                };
            }
            if ($kind === 'refund' && $current->status === S::REFUNDED->value) {
                $status = S::REFUNDED;
                $values['refunded_amount'] = (int) $row->amount;
            }
            StateMachine::assert(S::from($current->status), $status);
            $values['status'] = $status->value;
            DB::table('ppsa_transactions')->where('id', $row->id)->update($values);
            DB::table('ppsa_operations')->where('id', $operation->id)->update(['state' => 'confirmed', 'remote_id' => $kind === 'refund' ? $result->refundId : match ($kind) {
                'authorize' => $result->authorizationId,
                'cancel' => $result->cancellationId,
                default => $result->captureId
            }, 'updated_at' => now()]);
            if ($current->status !== $status->value || ($kind === 'refund' && $total > (int) $current->refunded_amount)) {
                $this->changed(new PaymentResponseDTO($row->provider, $status, $row->provider_reference, $row->order_reference, (int) $row->amount, $row->currency));
            }
        });

        return $result;
    }
}
