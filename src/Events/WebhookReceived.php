<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Events;

class WebhookReceived
{
    public function __construct(
        public string $provider,
        public array $payload,
    ) {}
}
