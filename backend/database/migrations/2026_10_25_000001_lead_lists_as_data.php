<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 1.1 (audit LD-14, part of LD-09). Lead sources and lost reasons become lists a company edits, instead of a database enum
 * that needs a migration for every new channel.
 *
 *  - leads.source and leads.status: enum -> plain string (values kept as stored). The allowed values are checked in code.
 *  - lead_sources, lead_lost_reasons: one list per company, seeded here for existing companies and lazily for new ones.
 *  - leads.lost_reason_id, leads.lost_note: the chosen reason (leads.lost_reason keeps its name, so grouping and old rows are unchanged)
 *    and an optional free-text note.
 */
return new class extends Migration {
    private const SOURCES = ['Website', 'Phone', 'WhatsApp', 'Referral', 'IndiaMART', 'Justdial', 'Exhibition', 'Walk-in', 'TradeIndia', 'ExportersIndia',
        'Facebook', 'Instagram', 'Google Ads', 'E-mail', 'Other'];
    private const REASONS = ['Price too high', 'Chose a competitor', 'No budget or project dropped', 'Timing, not now', 'Not a genuine enquiry', 'No response', 'Other'];

    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->string('source', 60)->default('Other')->change();
            $t->string('status', 30)->default('new')->change();
            if (!Schema::hasColumn('leads', 'lost_reason_id')) {
                $t->unsignedBigInteger('lost_reason_id')->nullable()->after('lost_reason');
                $t->text('lost_note')->nullable()->after('lost_reason_id');
            }
        });

        if (!Schema::hasTable('lead_sources')) {
            Schema::create('lead_sources', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->string('name', 60);
                $t->boolean('is_system')->default(false);     // 'Other' is the fallback and cannot be removed or switched off
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->timestamps();
                $t->unique(['company_id', 'name']);
            });
        }
        if (!Schema::hasTable('lead_lost_reasons')) {
            Schema::create('lead_lost_reasons', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->string('name', 100);
                $t->boolean('is_system')->default(false);
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->timestamps();
                $t->unique(['company_id', 'name']);
            });
        }

        // Existing companies get the starter lists now; companies created later get them the first time the lists are read.
        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::SOURCES as $i => $name) {
                DB::table('lead_sources')->insertOrIgnore(['company_id' => $companyId, 'name' => $name, 'is_system' => $name === 'Other', 'is_active' => true, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach (self::REASONS as $i => $name) {
                DB::table('lead_lost_reasons')->insertOrIgnore(['company_id' => $companyId, 'name' => $name, 'is_system' => $name === 'Other', 'is_active' => true, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_lost_reasons');
        Schema::dropIfExists('lead_sources');
        Schema::table('leads', function (Blueprint $t) {
            $t->dropColumn(['lost_reason_id', 'lost_note']);
        });
        // The enum columns are not restored: any value added since would no longer fit. Plain strings hold the old values fine.
    }
};
