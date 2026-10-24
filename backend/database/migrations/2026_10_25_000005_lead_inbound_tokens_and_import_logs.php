<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 2.3 (audit LC-02, LE-02, LE-03, LC-12 part).
 *
 *  - lead_inbound_tokens: one secret address per integration (website form, a partner...). Only the SHA-256 of the token is stored; the token itself
 *    is shown once when made or regenerated. The company comes from the token alone. `source` is the channel its leads are recorded under.
 *  - lead_import_logs: one row for every request that reached a valid token (created, merged, repeated, rejected, spam, too large, error),
 *    for the log screen and the connector health view in 3.3. Old rows are pruned by the hourly scan.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('lead_inbound_tokens')) {
            Schema::create('lead_inbound_tokens', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->string('name', 100);
                $t->string('source', 60);
                $t->char('token_hash', 64)->unique();
                $t->string('token_hint', 8);                       // the last characters, so people can tell tokens apart
                $t->boolean('is_active')->default(true);
                $t->timestamp('last_used_at')->nullable();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index('company_id');
            });
        }

        if (!Schema::hasTable('lead_import_logs')) {
            Schema::create('lead_import_logs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->unsignedBigInteger('token_id')->nullable();
                $t->string('source', 60)->nullable();
                $t->string('outcome', 20);                          // created | merged | already_received | rejected | spam | too_large | error
                $t->unsignedBigInteger('lead_id')->nullable();
                $t->string('reason', 255)->nullable();
                $t->string('external_id', 120)->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['company_id', 'created_at']);
                $t->index(['company_id', 'outcome']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_import_logs');
        Schema::dropIfExists('lead_inbound_tokens');
    }
};
