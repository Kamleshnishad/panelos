<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 5.8 (audit LP-08). When two leads for the same buyer are merged the second one is soft-deleted and points here at the lead it went into, so the trace
 * is kept (and its number and IndiaMART reference still stop the same enquiry being imported again, because a deleted lead still holds its unique keys).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            if (!Schema::hasColumn('leads', 'merged_into_lead_id')) {
                $t->foreignId('merged_into_lead_id')->nullable()->after('quotation_id')->constrained('leads')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $t) => $t->dropConstrainedForeignId('merged_into_lead_id'));
    }
};
