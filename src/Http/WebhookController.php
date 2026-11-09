<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use YacoubAlhaidari\ProviderPayments\Contracts\ProcessesWebhooks;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\Events\WebhookVerificationFailed;
use YacoubAlhaidari\ProviderPayments\Exceptions\ConfigurationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\WebhookVerificationException;
use YacoubAlhaidari\ProviderPayments\PaymentManager;
use YacoubAlhaidari\ProviderPayments\Repositories\PaymentLedger;

final class WebhookController
{
    public function __invoke(Request $r, string $provider, PaymentManager $manager, PaymentLedger $ledger): JsonResponse
    {
        if (strlen($r->getContent()) > 65536) {
            return response()->json(['ok' => false], 413);
        }
        try {
            $g = $manager->gateway($provider);
            if (! $g instanceof ProcessesWebhooks || ! $g->verifyWebhook($r)) {
                event(new WebhookVerificationFailed($provider, ''));

                return response()->json(['ok' => false], 401);
            }
            if (! config('provider-payments.persistence.enabled')) {
                return response()->json(['ok' => false], 503);
            }
            if (in_array($provider, ['alinmapay', 'alrajhi'], true)) {
                $payment = $g->processWebhook($r)->raw['payment'];

                return response()->json(['ok' => true, 'accepted' => $ledger->accept($provider, $ledger->eventKey($payment), $payment)]);
            }
            $text = static function (mixed $value): string {
                if (! is_string($value) || $value === '') {
                    throw new \InvalidArgumentException('Missing webhook reference.');
                }

                return $value;
            };
            if ($provider === 'telr') {
                $ledger->find($provider, null, $text($r->input('tran_cartid')));
            } elseif ($provider === 'mispay') {
                $ledger->find($provider, null, $text($r->input('orderId')));
            } elseif ($provider === 'clickpay' && $r->has('cart_id')) {
                $row = $ledger->find($provider, null, $text($r->input('cart_id')));
                if (! $row->provider_reference) {
                    $manager->recoverPayment($row->order_reference, $provider);
                    $row = $ledger->find($provider, null, $row->order_reference);
                }
                if ($row->provider_reference !== $text($r->input('tran_ref'))) {
                    throw new PaymentVerificationException('ClickPay callback reference mismatch.');
                }
            } else {
                $ledger->find($provider, $text($r->input(match ($provider) {
                    'clickpay' => 'tran_ref','tamara' => 'order_id',default => 'id'
                })));
            }
            $result = $g->processWebhook($r);
            if ($provider === 'telr') {
                $row = $ledger->find($provider, null, (string) $result->raw['cart_id']);
                $payment = $g->getPaymentStatus($row->provider_reference);
            } elseif ($provider === 'mispay') {
                $row = $ledger->find($provider, null, (string) $result->raw['cart_id']);
                // Checkout ID must match our saved track ID. Empty/mismatched IDs never settle.
                if (! $result->transactionReference || $row->provider_reference !== $result->transactionReference) {
                    throw new PaymentVerificationException('MisPay checkout mismatch.');
                }
                if ($result->raw['code'] === 'MP03') {
                    // A refund notice lacks trusted amount/cumulative balance; require merchant reconciliation.
                    return response()->json(['ok' => true, 'reconciliation_required' => true]);
                }
                $payment = new PaymentResponseDTO($provider, $result->status, $row->provider_reference, $row->order_reference, (int) $row->amount, $row->currency);
            } else {
                $payment = $result->raw['payment'];
            }
            $key = $ledger->eventKey($payment);
            $accepted = $ledger->accept($provider, $key, $payment);

            return response()->json(['ok' => true, 'accepted' => $accepted]);
        } catch (WebhookVerificationException) {
            return response()->json(['ok' => false], 401);
        } catch (ConfigurationException) {
            return response()->json(['ok' => false], 503);
        } catch (PaymentVerificationException|\InvalidArgumentException|\ValueError) {
            return response()->json(['ok' => false], 422);
        } catch (PaymentException) {
            return response()->json(['ok' => false], 502);
        }
    }
}
