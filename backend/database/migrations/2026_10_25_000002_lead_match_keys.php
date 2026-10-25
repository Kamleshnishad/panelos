<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 1.4 (audit LD-05). leads.phone_key and leads.email_key hold the normalised phone (last 10 digits) and e-mail (lower case) so a
 * duplicate lookup is an indexed query. The model keeps them current on every save; this fills them for the leads that already exist.
 * The normalising rules are copied here on purpose: a migration must not depend on application code that may change later.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            if (!Schema::hasColumn('leads', 'phone_key')) {
                $t->string('phone_key', 10)->nullable()->after('email');
                $t->string('email_key', 150)->nullable()->after('phone_key');
                $t->index(['company_id', 'phone_key'], 'leads_company_phone_key_idx');
                $t->index(['company_id', 'email_key'], 'leads_company_email_key_idx');
            }
        });

        DB::table('leads')->select('id', 'phone', 'email')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $r) {
                $digits = preg_replace('/\D+/', '', (string) $r->phone);
                $email = mb_strtolower(trim((string) $r->email));
                DB::table('leads')->where('id', $r->id)->update([
                    'phone_key' => strlen($digits) >= 10 ? substr($digits, -10) : null,
                    'email_key' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->dropIndex('leads_company_phone_key_idx');
            $t->dropIndex('leads_company_email_key_idx');
            $t->dropColumn(['phone_key', 'email_key']);
        });
    }
};
