<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use YacoubAlhaidari\ProviderPayments\Contracts\CredentialResolverInterface;
use YacoubAlhaidari\ProviderPayments\PaymentManager;

final class DiagnoseCommand extends Command
{
    protected $signature = 'payments:diagnose';

    protected $description = 'Check local configuration and persistence without provider calls';

    public function handle(PaymentManager $manager): int
    {
        $ok = true;
        $this->line('Laravel '.app()->version().' PHP '.PHP_VERSION);
        foreach (config('provider-payments.providers', []) as $name => $config) {
            $g = $manager->gateway($name);
            if ($g->isEnabled() && ! $g->isConfigured()) {
                $this->error($name.': required credentials missing');
                $ok = false;
            }
            if ($g->isEnabled() && config('provider-payments.routes.enabled')) {
                $required = match ($name) {
                    'telr' => ['secret_key'], 'tabby' => ['webhook_secret'],
                    'tamara' => ['notification_token'], default => [],
                };
                $resolver = app(CredentialResolverInterface::class);
                if (! $resolver->hasAll($name, $required) || ($name === 'tabby' && empty($config['webhook_header']))
                    || ($name === 'mispay' && ! $g->hasWebhookAuthentication())) {
                    $this->error($name.': webhook authentication missing');
                    $ok = false;
                }
            }
        }
        if (config('provider-payments.routes.enabled') && ! Route::has('provider-payments.webhook')) {
            $this->error('Webhook route missing');
            $ok = false;
        }
        if (config('provider-payments.persistence.enabled')) {
            foreach (['ppsa_transactions', 'ppsa_webhook_events', 'ppsa_operations'] as $table) {
                if (! Schema::hasTable($table)) {
                    $this->error('Missing '.$table);
                    $ok = false;
                }
            }
        }
        $this->line($ok ? 'Local checks passed. No provider traffic sent.' : 'Fix local setup before enabling payments.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
