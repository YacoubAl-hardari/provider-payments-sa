<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Facades;

use Illuminate\Support\Facades\Facade;
use YacoubAlhaidari\ProviderPayments\PaymentManager;

class Payment extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'payment.manager';
    }

    public static function manager(): PaymentManager
    {
        return static::getFacadeRoot();
    }
}
