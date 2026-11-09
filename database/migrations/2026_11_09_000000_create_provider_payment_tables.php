<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppsa_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 32);
            $t->string('order_reference', 128);
            $t->string('provider_reference', 191)->nullable();
            $t->string('fingerprint', 64);
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3);
            $t->string('status', 32)->default('pending');
            $t->string('creation_state', 20)->default('reserved');
            $t->text('checkout_url')->nullable();
            $t->string('session_id')->nullable();
            $t->unsignedBigInteger('refunded_amount')->default(0);
            $t->boolean('refund_balance_known')->default(true);
            $t->timestamps();
            $t->unique(['provider', 'order_reference']);
            $t->unique(['provider', 'provider_reference']);
        });
        Schema::create('ppsa_webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 32);
            $t->string('event_key', 64);
            $t->string('state', 24);
            $t->timestamps();
            $t->unique(['provider', 'event_key']);
        });
        Schema::create('ppsa_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transaction_id')->constrained('ppsa_transactions');
            $t->string('kind', 16);
            $t->string('reference', 128);
            $t->unsignedBigInteger('amount');
            $t->string('state', 20);
            $t->string('remote_id')->nullable();
            $t->timestamps();
            $t->unique(['transaction_id', 'kind', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppsa_operations');
        Schema::dropIfExists('ppsa_webhook_events');
        Schema::dropIfExists('ppsa_transactions');
    }
};
