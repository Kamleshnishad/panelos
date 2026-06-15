<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            // Twilio SMS
            $table->string('twilio_account_sid', 100)->nullable();
            $table->string('twilio_auth_token', 100)->nullable();
            $table->string('twilio_from_number', 20)->nullable();   // +91XXXXXXXXXX
            $table->boolean('sms_enabled')->default(false);
            // WhatsApp (Twilio whatsapp: channel)
            $table->string('whatsapp_from', 20)->nullable();        // +14155238886 (sandbox) or your number
            $table->boolean('whatsapp_enabled')->default(false);
            // Notification triggers
            $table->boolean('notify_payment_due')->default(true);
            $table->integer('payment_due_days_before')->default(3);
            $table->boolean('notify_payment_overdue')->default(true);
            $table->boolean('notify_low_stock')->default(true);
            $table->boolean('notify_order_confirmed')->default(false);
            $table->boolean('notify_dispatch_done')->default(false);
            // Admin phone for internal alerts
            $table->string('admin_phone', 20)->nullable();
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
