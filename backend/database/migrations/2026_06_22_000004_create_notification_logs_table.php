<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OPS-H3 — delivery log for WhatsApp/SMS notifications so failures are visible
 * to operators instead of vanishing into storage/logs.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('notification_logs')) return;

        Schema::create('notification_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('channel', 20)->nullable();   // whatsapp / sms
            $t->string('recipient', 40)->nullable();
            $t->string('type', 40);                   // order_confirmed, dispatch_done, payment_due, low_stock
            $t->string('status', 12);                 // sent / failed
            $t->text('error')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['company_id', 'created_at']);
            $t->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
