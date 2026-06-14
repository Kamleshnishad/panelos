<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0 — allow 'consumable' as a low-stock alert item_type so consumable
 * reorder alerts can be created (was only coil/chemical).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE low_stock_alerts MODIFY COLUMN item_type ENUM('coil','chemical','consumable')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE low_stock_alerts MODIFY COLUMN item_type ENUM('coil','chemical')");
    }
};
