<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WR-0: a batch can be inspected more than once (fail/rework → re-inspect),
 * so quality_controls keeps a history per batch instead of one row. The
 * plain index is added before dropping the unique one because MySQL needs
 * an index to back the batch_id foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_controls', function (Blueprint $table) {
            $table->index('batch_id', 'quality_controls_batch_id_index');
        });
        Schema::table('quality_controls', function (Blueprint $table) {
            $table->dropUnique('quality_controls_batch_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('quality_controls', function (Blueprint $table) {
            $table->unique('batch_id');
        });
        Schema::table('quality_controls', function (Blueprint $table) {
            $table->dropIndex('quality_controls_batch_id_index');
        });
    }
};
