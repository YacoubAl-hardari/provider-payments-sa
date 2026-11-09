<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Contracts;

use Illuminate\Http\Request;
use YacoubAlhaidari\ProviderPayments\DTOs\WebhookResultDTO;

/**
 * Optional capability: provider sends webhooks/callbacks that must be verified.
 */
interface ProcessesWebhooks
{
    /**
     * Verify the incoming webhook request's authenticity.
     * Throws WebhookVerificationException on failure.
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * Process the verified webhook payload and return a structured result.
     */
    public function processWebhook(Request $request): WebhookResultDTO;
}
