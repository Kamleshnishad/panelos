<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second mailbox per company, for the automatic notification e-mails (payment reminders, receipts). The first one
 * (smtp_*) stays the main mailbox for everything the team sends by hand: quotations, invoices. Same shape, same encryption.
 * Which kind of e-mail uses which mailbox is the admin's choice (mail_routing); left empty, notifications simply use the main mailbox.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('notification_settings', 'notif_smtp_enabled')) {
                $t->boolean('notif_smtp_enabled')->default(false);
                $t->string('notif_smtp_host', 150)->nullable();
                $t->unsignedSmallInteger('notif_smtp_port')->nullable();
                $t->string('notif_smtp_encryption', 10)->nullable();
                $t->string('notif_smtp_username', 190)->nullable();
                $t->text('notif_smtp_password')->nullable();         // encrypted
                $t->string('notif_smtp_from_address', 190)->nullable();
                $t->string('notif_smtp_from_name', 150)->nullable();
            }
            if (!Schema::hasColumn('notification_settings', 'mail_routing')) {
                $t->json('mail_routing')->nullable();               // which kind of e-mail goes out through which mailbox
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $t) {
            $t->dropColumn(['notif_smtp_enabled', 'notif_smtp_host', 'notif_smtp_port', 'notif_smtp_encryption', 'notif_smtp_username',
                'notif_smtp_password', 'notif_smtp_from_address', 'notif_smtp_from_name', 'mail_routing']);
        });
    }
};
