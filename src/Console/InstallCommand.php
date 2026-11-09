<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'payments:install';

    protected $description = 'Publish missing configuration and explain migrations';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'provider-payments-config']);
        $this->info('Run payments:env:sync --dry-run, then --write to append safe keys.');
        $this->line('Publish migrations: php artisan vendor:publish --tag=provider-payments-migrations');
        $this->line('Review migrations, then run php artisan migrate. Set credentials and enable each provider explicitly.');

        return self::SUCCESS;
    }
}
