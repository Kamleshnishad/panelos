<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partial dispatch (FT-03). A dispatch used to copy every item of its batch and a batch allowed exactly one dispatch.
 * Sites take material in phases, so a dispatch now carries chosen pieces (nos) of chosen size rows.
 *
 *  - dispatch_items.order_item_id        which order line the row belongs to (NULL on dispatches made before this change:
 *                                        those covered the whole batch, and the service treats them that way)
 *  - dispatches.batch_id                 was UNIQUE (concurrency migration 2026_06_22_000002) to stop a double click creating two
 *                                        dispatches; that is now exactly what a second load is. The batch row is locked while a
 *                                        dispatch is created instead. A plain index replaces the unique one first, because the
 *                                        foreign key to production_batches needs an index on the column to keep existing.
 *  - dispatch_item_sizes                 the pieces of each size row on a dispatch; what is left to send is the order's
 *                                        nos minus the nos on live (not cancelled) dispatches
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('dispatch_items', function (Blueprint $t) {
            if (!Schema::hasColumn('dispatch_items', 'order_item_id')) {
                $t->unsignedBigInteger('order_item_id')->nullable()->index();
            }
        });

        $indexes = collect(Schema::getIndexes('dispatches'));
        if (!$indexes->contains('name', 'dispatches_batch_id_index')) {
            Schema::table('dispatches', fn (Blueprint $t) => $t->index('batch_id', 'dispatches_batch_id_index'));
        }
        if ($indexes->contains('name', 'dispatches_batch_id_unique')) {
            Schema::table('dispatches', fn (Blueprint $t) => $t->dropUnique('dispatches_batch_id_unique'));
        }

        if (!Schema::hasTable('dispatch_item_sizes')) {
            Schema::create('dispatch_item_sizes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('dispatch_item_id')->constrained('dispatch_items')->cascadeOnDelete();
                $t->unsignedBigInteger('order_item_size_id')->index();
                $t->unsignedInteger('length_mm');     // snapshot, so a challan never depends on the order row staying put
                $t->unsignedInteger('width_mm');
                $t->unsignedInteger('nos');
                $t->decimal('sqm', 12, 4);
                $t->decimal('amount', 12, 2);
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_item_sizes');
        // Restoring the one-dispatch-per-batch rule fails if a batch already has several; that is the honest outcome.
        Schema::table('dispatches', fn (Blueprint $t) => $t->unique('batch_id', 'dispatches_batch_id_unique'));
        Schema::table('dispatch_items', function (Blueprint $t) {
            $t->dropColumn('order_item_id');
        });
    }
};
