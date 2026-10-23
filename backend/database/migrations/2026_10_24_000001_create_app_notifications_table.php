<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications (the header bell). One row per recipient, so read/unread
 * is per person. Unrelated to notification_logs, which records SMS/WhatsApp
 * sends to customers.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('app_notifications')) return;

        Schema::create('app_notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->string('type', 40);                       // stage_pending_approval, low_stock, overdue_invoices …
            $t->string('severity', 10)->default('info');  // info | warning | danger
            $t->string('title', 160);
            $t->text('body')->nullable();
            $t->string('link_section', 40)->nullable();   // AppShell section key: batches, stock, receivables …
            $t->unsignedBigInteger('link_id')->nullable();
            // Standing conditions (low stock, overdue) carry a key so they are
            // refreshed in place rather than re-created on every scan.
            $t->string('dedupe_key', 80)->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'read_at', 'created_at']);
            $t->unique(['user_id', 'dedupe_key']);        // NULL keys never collide
            $t->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
