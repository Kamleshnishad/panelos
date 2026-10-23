<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quotation lines that are not a master accessory: installation, custom items and doors/windows
 * typed straight into the form. They used to be added into quotations.installation_amount and the
 * row itself thrown away, so the edit form could not show them and saving a quotation silently
 * reset the amount to 0 (PB-045). quotations.installation_amount stays as the authoritative sum
 * of these rows, so every total, PDF and invoice keeps working unchanged.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('quotation_lines')) {
            Schema::create('quotation_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
                $t->string('type', 20);                        // installation | door | custom
                $t->string('description', 500)->nullable();
                $t->string('unit', 20)->default('LS');
                $t->decimal('quantity', 10, 2)->default(1);
                $t->decimal('unit_price', 12, 2)->default(0);
                $t->decimal('amount', 12, 2)->default(0);
                $t->string('hsn_code', 20)->nullable();
                $t->string('door_type', 20)->nullable();
                $t->unsignedSmallInteger('door_width')->nullable();
                $t->unsignedSmallInteger('door_height')->nullable();
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->timestamps();
                $t->index('quotation_id');
            });
        }

        $this->backfill();
    }

    /**
     * Every existing quotation that carries an installation amount gets one lump-sum line for it, so the
     * edit form shows it and a save keeps it. Safe to repeat: quotations that already have lines are skipped.
     */
    public function backfill(): void
    {
        DB::table('quotations')
            ->where('installation_amount', '>', 0)
            ->whereNotIn('id', DB::table('quotation_lines')->select('quotation_id'))
            ->chunkById(200, function ($quotations) {
                $now = now();
                DB::table('quotation_lines')->insert($quotations->map(fn ($q) => [
                    'quotation_id' => $q->id, 'type' => 'installation', 'description' => 'Installation / site work',
                    'unit' => 'LS', 'quantity' => 1, 'unit_price' => $q->installation_amount, 'amount' => $q->installation_amount,
                    'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
    }
};
