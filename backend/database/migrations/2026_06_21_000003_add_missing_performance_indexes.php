<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adds the two hot-path indexes the audit flagged (SCALE-H6):
 *   - invoices(company_id, due_date)        — AR aging / overdue is the hottest finance query
 *   - lead_activities(company_id, activity_date) — recent-activity ordering
 * Idempotent + column-guarded so it is safe to run against any deployed DB
 * regardless of prior schema drift. Adding an index never changes query
 * results — only speed — so this is a no-risk change.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('invoices', 'company_id') && Schema::hasColumn('invoices', 'due_date')
            && !$this->hasIndex('invoices', 'invoices_company_id_due_date_index')) {
            Schema::table('invoices', fn (Blueprint $t) => $t->index(['company_id', 'due_date']));
        }

        if (Schema::hasTable('lead_activities')
            && Schema::hasColumn('lead_activities', 'company_id')
            && Schema::hasColumn('lead_activities', 'activity_date')
            && !$this->hasIndex('lead_activities', 'lead_activities_company_id_activity_date_index')) {
            Schema::table('lead_activities', fn (Blueprint $t) => $t->index(['company_id', 'activity_date']));
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('invoices', 'invoices_company_id_due_date_index')) {
            Schema::table('invoices', fn (Blueprint $t) => $t->dropIndex('invoices_company_id_due_date_index'));
        }
        if ($this->hasIndex('lead_activities', 'lead_activities_company_id_activity_date_index')) {
            Schema::table('lead_activities', fn (Blueprint $t) => $t->dropIndex('lead_activities_company_id_activity_date_index'));
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $db = DB::getDatabaseName();
        return DB::table('information_schema.statistics')
            ->where('table_schema', $db)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
