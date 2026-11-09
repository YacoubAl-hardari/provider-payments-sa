<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use YacoubAlhaidari\ProviderPayments\Contracts\CredentialResolverInterface;
use YacoubAlhaidari\ProviderPayments\Support\ArrayCredentialResolver;

class ProviderPaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/provider-payments.php', 'provider-payments');
        // Laravel's shallow config merge otherwise hides new providers behind an older published file.
        // Add missing provider entries only; keep every consumer override intact.
        if (! $this->app->configurationIsCached()) {
            $defaults = require __DIR__.'/../config/provider-payments.php';
            $this->app['config']->set('provider-payments.providers', array_replace($defaults['providers'], $this->app['config']->get('provider-payments.providers', [])));
        }
        $this->app->singleton(ProviderRegistry::class, fn () => ProviderRegistry::defaults());
        $this->app->bindIf(CredentialResolverInterface::class, fn ($app) => new ArrayCredentialResolver($app['config']->get('provider-payments.providers', [])));
        $this->app->singleton('payment.manager', fn ($app) => new PaymentManager($app['config']->get('provider-payments', []), $app, $app->make(CredentialResolverInterface::class), $app->make(ProviderRegistry::class)));
        $this->app->alias('payment.manager', PaymentManager::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/provider-payments.php' => config_path('provider-payments.php')], 'provider-payments-config');
        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'provider-payments-migrations');
        if (config('provider-payments.routes.enabled') && ! $this->app->routesAreCached()) {
            Route::prefix(config('provider-payments.routes.prefix', 'payments'))->name('provider-payments.')->middleware(config('provider-payments.routes.middleware', []))->group(__DIR__.'/../routes/webhooks.php');
        }
        if ($this->app->runningInConsole()) {
            $this->commands([Console\EnvSyncCommand::class, Console\ListCommand::class, Console\DiagnoseCommand::class, Console\InstallCommand::class]);
        }
    }
}
