<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add a 'boq' status so a BOQ can exist as a distinct, rate-less stage
     * before it is converted into a priced quotation.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE quotations MODIFY status ENUM('boq','draft','sent','accepted','rejected','revised','expired') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        // Re-home any remaining BOQs to draft before shrinking the enum
        DB::table('quotations')->where('status', 'boq')->update(['status' => 'draft']);
        DB::statement("ALTER TABLE quotations MODIFY status ENUM('draft','sent','accepted','rejected','revised','expired') NOT NULL DEFAULT 'draft'");
    }
};
