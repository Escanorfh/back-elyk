<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_session_payments', function (Blueprint $table) {
            $table->enum('payment_method', ['fawry', 'instapay', 'manual'])
                ->nullable()
                ->after('status');
            $table->string('merchant_order_reference')->nullable()->unique()
                ->after('payment_method');
            $table->string('fawry_reference_number')->nullable()->index()
                ->after('merchant_order_reference');
            $table->string('instapay_reference_number')->nullable()->index()
                ->after('fawry_reference_number');
            $table->string('instapay_ipa')->nullable()
                ->after('instapay_reference_number');
            $table->string('gateway_status')->nullable()
                ->after('instapay_ipa');
            $table->json('gateway_response')->nullable()
                ->after('gateway_status');
            $table->string('receipt_image_path')->nullable()
                ->after('gateway_response');
            $table->ipAddress('ip_address')->nullable()
                ->after('receipt_image_path');
            $table->timestamp('expires_at')->nullable()
                ->after('paid_at');
        });

        Schema::create('payment_transactions_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('live_session_payments')->cascadeOnDelete();
            $table->enum('event_type', ['charge_created', 'webhook_received', 'verified', 'refunded', 'failed']);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions_log');

        Schema::table('live_session_payments', function (Blueprint $table) {
            $table->dropUnique(['merchant_order_reference']);
            $table->dropIndex(['fawry_reference_number']);
            $table->dropIndex(['instapay_reference_number']);
            $table->dropColumn([
                'payment_method',
                'merchant_order_reference',
                'fawry_reference_number',
                'instapay_reference_number',
                'instapay_ipa',
                'gateway_status',
                'gateway_response',
                'receipt_image_path',
                'ip_address',
                'expires_at',
            ]);
        });
    }
};
