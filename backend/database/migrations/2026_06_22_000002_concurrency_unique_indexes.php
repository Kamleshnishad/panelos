<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 concurrency safety nets — all DEFENSIVE (never fail a deploy on
 * pre-existing data; detect dupes → skip + log with counts).
 *
 *   CONC-H4: one order per quotation        → unique orders(quotation_id)
 *   CONC-H4: one dispatch per batch          → unique dispatches(batch_id)
 *   SCALE-M2: accessory codes are per-tenant → composite unique
 *             accessories(company_id, code) and DROP the old global code unique.
 */
return new class extends Migration {
    public function up(): void
    {
        // NULLs are allowed to repeat by MySQL unique indexes, so nullable FKs are fine.
        $this->addUniqueIfClean('orders', ['quotation_id'], 'orders_quotation_id_unique', 'quotation_id IS NOT NULL');
        $this->addUniqueIfClean('dispatches', ['batch_id'], 'dispatches_batch_id_unique', 'batch_id IS NOT NULL');

        if (Schema::hasTable('accessories')) {
            // 1) make sure the per-tenant composite exists FIRST (protection never lapses)
            $this->addUniqueIfClean('accessories', ['company_id', 'code'], 'accessories_company_id_code_unique', 'code IS NOT NULL');
            // 2) then drop the old GLOBAL single-column code unique (which wrongly blocks
            //    two tenants from reusing a code) — only if the composite is in place.
            $global = $this->singleColumnUnique('accessories', 'code');
            if ($global && $this->hasIndex('accessories', 'accessories_company_id_code_unique')) {
                Schema::table('accessories', fn (Blueprint $t) => $t->dropUnique($global));
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: keep the integrity indexes in place on rollback.
    }

    private function addUniqueIfClean(string $table, array $cols, string $index, string $whereNotNull): void
    {
        if (!Schema::hasTable($table)) return;
        foreach ($cols as $c) {
            if (!Schema::hasColumn($table, $c)) return;
        }
        if ($this->hasIndex($table, $index)) return;

        $colList = implode(',', $cols);
        $row = DB::selectOne("SELECT COUNT(*) AS c FROM (SELECT {$colList} FROM {$table} WHERE {$whereNotNull} GROUP BY {$colList} HAVING COUNT(*) > 1) t");
        $dupes = (int) ($row->c ?? 0);
        if ($dupes > 0) {
            Log::warning("[migration] {$index} SKIPPED — {$dupes} duplicate group(s) in {$table}({$colList}); clean them then re-run this migration.");
            return;
        }
        Schema::table($table, fn (Blueprint $t) => $t->unique($cols, $index));
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    /** Name of a UNIQUE index covering exactly [$col] (excluding PRIMARY), or null. */
    private function singleColumnUnique(string $table, string $col): ?string
    {
        $rows = DB::select(
            'SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND non_unique = 0
             GROUP BY index_name',
            [DB::getDatabaseName(), $table]
        );
        foreach ($rows as $r) {
            $r = array_change_key_case((array) $r, CASE_LOWER);
            if (($r['cols'] ?? null) === $col && strtoupper((string) ($r['index_name'] ?? '')) !== 'PRIMARY') {
                return $r['index_name'];
            }
        }
        return null;
    }
};
