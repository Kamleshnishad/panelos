<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // All columns confirmed already present in DB from prior migrations.
        // This migration is a no-op kept for record-keeping.
    }

    public function down(): void
    {
        // No-op: up() creates nothing, and these columns are owned by other
        // migrations. Dropping them here would corrupt the schema on rollback.
    }
};
