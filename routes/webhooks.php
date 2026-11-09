<?php

use Illuminate\Support\Facades\Route;
use YacoubAlhaidari\ProviderPayments\Http\WebhookController;

Route::post('/{provider}/webhook', WebhookController::class)->whereIn('provider', ['telr', 'clickpay', 'tabby', 'tamara', 'mispay', 'alinmapay', 'alrajhi'])->name('webhook');
