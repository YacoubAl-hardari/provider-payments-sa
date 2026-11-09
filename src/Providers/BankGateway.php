<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Providers;

use Illuminate\Http\Request;
use YacoubAlhaidari\ProviderPayments\AbstractGateway;
use YacoubAlhaidari\ProviderPayments\Contracts\ProcessesWebhooks;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\WebhookResultDTO;
use YacoubAlhaidari\ProviderPayments\Exceptions\ConfigurationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\WebhookVerificationException;
use YacoubAlhaidari\ProviderPayments\Repositories\PaymentLedger;

/** Shared ledger policy; wire formats remain provider-specific. */
abstract class BankGateway extends AbstractGateway implements ProcessesWebhooks
{
    protected array $capabilities = ['create' => true, 'verify' => true, 'webhook' => true];

    abstract protected function callbackPayload(Request $request): array;

    abstract protected function callbackIdentity(array $payload): array;

    protected function validateRequest(PaymentRequestDTO $request): void
    {
        $this->sar($request->currency);
        if (! ($this->config['persistence'] ?? false)) {
            throw new ConfigurationException('Bank checkout requires the payment ledger for subsequent verification.');
        }
    }

    protected function sar(string $currency): void
    {
        if ($currency !== 'SAR') {
            throw new \InvalidArgumentException('This bank integration supports SAR only.');
        }
    }

    protected function identifier(array $data, string $key): string
    {
        if (isset($data[$key]) && is_int($data[$key]) && $data[$key] >= 0) {
            return (string) $data[$key];
        }

        return $this->requireText($data, $key);
    }

    public function processWebhook(Request $request): WebhookResultDTO
    {
        if (! $this->verifyWebhook($request)) {
            throw new WebhookVerificationException('Bank callback verification failed.');
        }
        [$order, $reference, $amount, $currency] = $this->callbackIdentity($this->callbackPayload($request));
        $row = app(PaymentLedger::class)->find($this->name, null, $order);
        if (
            ! $row->provider_reference || ($reference !== null && $reference !== $row->provider_reference)
            || $amount !== (int) $row->amount || $currency !== $row->currency
        ) {
            throw new PaymentVerificationException('Bank callback identity/amount/currency mismatch.');
        }
        // Neither an encrypted browser response nor a signed status is settlement proof.
        // Always query the bank using the identity already saved by our application.
        $payment = $this->getPaymentStatus($row->provider_reference);

        return new WebhookResultDTO($this->name, $payment->isSuccessful(), $payment->status, $payment->providerTransactionId, raw: ['payment' => $payment]);
    }
}
