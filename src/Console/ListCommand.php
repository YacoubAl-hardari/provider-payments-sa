<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Console;

use Illuminate\Console\Command;
use YacoubAlhaidari\ProviderPayments\PaymentManager;

final class ListCommand extends Command
{
    protected $signature = 'payments:list';

    protected $description = 'Show payment provider readiness without secrets or network requests';

    public function handle(PaymentManager $manager): int
    {
        foreach (config('provider-payments.providers', []) as $name => $config) {
            $g = $manager->gateway($name);
            $this->line($name.' '.($g->isEnabled() ? 'enabled' : 'disabled').' '.($g->isConfigured() ? 'configured' : 'missing credentials').' '.($config['base_url'] ?? '').' capabilities: '.implode(',', array_keys(array_filter($g->capabilities()))));
        }

        return self::SUCCESS;
    }
}
