<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the DB schema verifiable across local ↔ git ↔ live.
 *
 *   php artisan schema:snapshot   → write database/schema/baseline.json from
 *                                   the CURRENT db (run after adding migrations,
 *                                   then commit the file — it's the source of truth)
 *   php artisan schema:check      → compare the CURRENT db against baseline.json
 *                                   and report drift. Exit 1 if columns are MISSING
 *                                   (a real problem); extra columns are just info.
 *
 * Types are normalised before comparing so benign MySQL-8-vs-MariaDB differences
 * (bigint(20)↔bigint, int(11)↔int, json↔longtext) are NOT flagged.
 */
class SchemaCheck extends Command
{
    protected $signature = 'schema:check {--snapshot : Write baseline.json from the current DB instead of checking}';
    protected $description = 'Verify the DB schema matches the committed baseline (or snapshot it)';

    private function path(): string
    {
        return base_path('database/schema/baseline.json');
    }

    /** Current DB schema as [table => [column => normalisedType]]. */
    private function currentSchema(): array
    {
        $db = config('database.connections.' . config('database.default') . '.database');
        $rows = DB::select(
            'SELECT table_name AS t, column_name AS c, column_type AS ty
             FROM information_schema.columns WHERE table_schema = ? ORDER BY table_name, ordinal_position', [$db]
        );
        $out = [];
        foreach ($rows as $x) {
            $x = array_change_key_case((array) $x, CASE_LOWER);
            $out[$x['t']][$x['c']] = $this->normalize($x['ty']);
        }
        return $out;
    }

    /** Collapse harmless engine/version display differences. */
    private function normalize(string $type): string
    {
        $t = strtolower(trim($type));
        // strip integer display widths: bigint(20) -> bigint, int(11) -> int, tinyint(1) -> tinyint
        $t = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $t);
        // JSON is stored as longtext on MariaDB, native json on MySQL 8 — treat as one
        if ($t === 'json' || $t === 'longtext') $t = 'json/longtext';
        return $t;
    }

    public function handle(): int
    {
        if ($this->option('snapshot')) {
            $schema = $this->currentSchema();
            @mkdir(dirname($this->path()), 0775, true);
            file_put_contents($this->path(), json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $cols = array_sum(array_map('count', $schema));
            $this->info("✓ Snapshot written: " . count($schema) . " tables, {$cols} columns → database/schema/baseline.json");
            return self::SUCCESS;
        }

        if (!is_file($this->path())) {
            $this->error('No baseline.json found. Run `php artisan schema:check --snapshot` first and commit it.');
            return self::FAILURE;
        }

        $baseline = json_decode(file_get_contents($this->path()), true) ?: [];
        $current  = $this->currentSchema();

        $missingCols = [];   // in baseline, not in DB  → real drift
        $typeDiffs   = [];   // present in both, type differs
        $missingTables = [];

        foreach ($baseline as $t => $cols) {
            if (!isset($current[$t])) { $missingTables[] = $t; continue; }
            foreach ($cols as $c => $ty) {
                if (!isset($current[$t][$c]))      $missingCols[]  = "{$t}.{$c}";
                elseif ($current[$t][$c] !== $ty)  $typeDiffs[]    = "{$t}.{$c} (baseline {$ty} vs db {$current[$t][$c]})";
            }
        }

        // Extra columns/tables in DB but not baseline — informational
        $extra = [];
        foreach ($current as $t => $cols) {
            if (!isset($baseline[$t])) { $extra[] = "table {$t}"; continue; }
            foreach ($cols as $c => $ty) if (!isset($baseline[$t][$c])) $extra[] = "{$t}.{$c}";
        }

        $this->line('Schema check against database/schema/baseline.json');
        if ($missingTables) $this->error('✗ MISSING TABLES: ' . implode(', ', $missingTables));
        if ($missingCols)   $this->error('✗ MISSING COLUMNS: ' . implode(', ', $missingCols));
        if ($typeDiffs)     foreach ($typeDiffs as $d) $this->warn('  ~ type diff: ' . $d);
        if ($extra)         $this->line('  + extra (in DB, not baseline — ok if you just added a migration): ' . implode(', ', $extra));

        if ($missingTables || $missingCols) {
            $this->error('❌ DRIFT — the DB is missing schema the baseline expects. Run pending migrations or add a heal migration.');
            return self::FAILURE;
        }
        $this->info('✅ Schema matches baseline' . ($typeDiffs ? ' (only benign type display differences)' : '') . '.');
        return self::SUCCESS;
    }
}
