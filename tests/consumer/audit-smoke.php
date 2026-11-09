<?php

// Isolated CLI fixture. No merchant credentials or provider requests are configured.
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$migration = require __DIR__ . '/../../database/migrations/2026_11_09_000000_create_provider_payment_tables.php';
$migration->up();
foreach (['payments:list', 'payments:env:sync --dry-run', 'payments:diagnose'] as $command) {
    $code = \Illuminate\Support\Facades\Artisan::call($command);
    echo $command . ' exit=' . $code . PHP_EOL;
    if ($code !== 0) {
        echo \Illuminate\Support\Facades\Artisan::output();
        exit($code);
    }
}
echo 'Facade manager: ' . get_class(\YacoubAlhaidari\ProviderPayments\Facades\Payment::manager()) . PHP_EOL;
echo 'Database: isolated in-memory SQLite; no source database accessed.' . PHP_EOL;
