<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 3.1 (audit LC-01). One row per company per outside channel that we pull from (IndiaMART now; Meta and others later).
 * `credentials` holds the secret (IndiaMART's CRM key) encrypted and is never returned by any API; `key_hint` is the last few characters so people can
 * tell which key is saved. `last_test_*` is what the "test connection" button found; `last_request_at` is when we last asked the channel (IndiaMART wants
 * 5 minutes between requests); `cursor_at` and `is_enabled` are for the pull job (3.2).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('lead_connectors')) {
            Schema::create('lead_connectors', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->string('type', 30);
                $t->boolean('is_enabled')->default(false);
                $t->text('credentials')->nullable();
                $t->string('key_hint', 8)->nullable();
                $t->timestamp('last_test_at')->nullable();
                $t->boolean('last_test_ok')->nullable();
                $t->string('last_test_message', 255)->nullable();
                $t->timestamp('last_request_at')->nullable();
                $t->timestamp('cursor_at')->nullable();
                $t->timestamp('last_success_at')->nullable();
                $t->timestamp('last_error_at')->nullable();
                $t->string('last_error', 255)->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['company_id', 'type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_connectors');
    }
};
