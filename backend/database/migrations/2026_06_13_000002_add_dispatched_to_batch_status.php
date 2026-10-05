<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE production_batches MODIFY COLUMN status ENUM('draft','in_progress','completed','qc_pending','qc_passed','qc_failed','dispatched') DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE production_batches MODIFY COLUMN status ENUM('draft','in_progress','completed','qc_pending','qc_passed','qc_failed') DEFAULT 'draft'");
        }
    }
};
