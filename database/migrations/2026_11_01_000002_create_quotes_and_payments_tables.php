<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_order_id')->unique();
            $table->string('saga_id', 64)->index();
            $table->json('services');
            $table->json('items');
            $table->decimal('services_total', 12, 2);
            $table->decimal('items_total', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('status', 30)->index();
            $table->string('approval_token', 64)->nullable()->unique();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_id')->constrained('quotes');
            $table->decimal('amount', 12, 2);
            $table->string('provider', 30);
            $table->string('status', 30)->index();
            $table->string('checkout_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->string('provider_payment_id')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('quotes');
    }
};
