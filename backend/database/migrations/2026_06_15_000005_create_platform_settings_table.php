<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton platform-level settings (one row) editable from the super-admin
 * panel: Razorpay billing credentials + the platform's GST seller identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            // Razorpay
            $table->boolean('razorpay_enabled')->default(false);
            $table->string('razorpay_key_id', 100)->nullable();
            $table->string('razorpay_key_secret', 150)->nullable();
            $table->string('razorpay_webhook_secret', 150)->nullable();
            // Platform GST seller identity (for subscription tax invoices)
            $table->string('platform_name', 150)->nullable();
            $table->string('platform_gstin', 20)->nullable();
            $table->string('platform_pan', 20)->nullable();
            $table->string('platform_address', 255)->nullable();
            $table->string('platform_state', 60)->nullable();
            $table->string('platform_state_code', 5)->nullable();
            $table->string('platform_email', 120)->nullable();
            $table->string('platform_phone', 20)->nullable();
            $table->string('platform_sac', 12)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
