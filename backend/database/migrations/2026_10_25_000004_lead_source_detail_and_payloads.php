<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 2.1 (audit LC-11). Where did this lead come from, exactly?
 *
 *  - leads.external_id: the channel's own id for the enquiry (IndiaMART query id, form entry id...). Unique per company and source,
 *    so the same enquiry arriving twice can never become two leads. Empty for manual leads (any number of those).
 *  - leads.campaign, utm_*: what advertising or page it came through.
 *  - leads.first_seen_at: when the enquiry first reached the channel (created_at is when we stored it; a pulled lead may be older).
 *  - lead_payloads: the raw message exactly as it arrived, kept for support and re-reading; the main screens never show it.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('leads', 'external_id')) {
            Schema::table('leads', function (Blueprint $t) {
                $t->string('external_id', 120)->nullable()->after('source');
                $t->string('campaign', 150)->nullable()->after('external_id');
                $t->string('utm_source', 150)->nullable()->after('campaign');
                $t->string('utm_medium', 150)->nullable()->after('utm_source');
                $t->string('utm_campaign', 150)->nullable()->after('utm_medium');
                $t->string('utm_term', 150)->nullable()->after('utm_campaign');
                $t->string('utm_content', 150)->nullable()->after('utm_term');
                $t->timestamp('first_seen_at')->nullable()->after('utm_content');
                $t->unique(['company_id', 'source', 'external_id'], 'leads_company_source_external_unique');
            });
        }

        if (!Schema::hasTable('lead_payloads')) {
            Schema::create('lead_payloads', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $t->string('source', 60);
                $t->string('external_id', 120)->nullable();
                $t->json('payload');
                $t->timestamp('received_at')->useCurrent();
                $t->timestamps();
                $t->softDeletes();                      // like every BaseModel table
                $t->index(['company_id', 'lead_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_payloads');
        Schema::table('leads', function (Blueprint $t) {
            $t->dropUnique('leads_company_source_external_unique');
            $t->dropColumn(['external_id', 'campaign', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'first_seen_at']);
        });
    }
};
