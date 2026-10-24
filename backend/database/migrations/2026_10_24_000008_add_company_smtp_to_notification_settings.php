<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company can send its customer e-mail (invoices, reminders, payment receipts, the test e-mail) from its own mailbox
 * instead of the server-wide one. The password is stored encrypted (cast on the model) and never returned by the API.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('notification_settings', 'smtp_enabled')) {
                $t->boolean('smtp_enabled')->default(false);
                $t->string('smtp_host', 150)->nullable();
                $t->unsignedSmallInteger('smtp_port')->nullable();
                $t->string('smtp_encryption', 10)->nullable();      // ssl (465) | tls (587)
                $t->string('smtp_username', 190)->nullable();
                $t->text('smtp_password')->nullable();               // encrypted
                $t->string('smtp_from_address', 190)->nullable();
                $t->string('smtp_from_name', 150)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $t) {
            $t->dropColumn(['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_address', 'smtp_from_name']);
        });
    }
};
