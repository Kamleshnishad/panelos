<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 5.4 (audit LP-03, LP-04). What the buyer wants, in lines the quotation can start from: panel lines (panel type, application,
 * thickness, area in sqm) and accessory lines (a catalogue accessory or a typed description, and a quantity). Nothing here prices anything.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('lead_requirement_lines')) {
            Schema::create('lead_requirement_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $t->string('kind', 10);                                            // panel | accessory
                $t->foreignId('panel_type_id')->nullable()->constrained('panel_types')->nullOnDelete();
                $t->foreignId('accessory_id')->nullable()->constrained('accessories')->nullOnDelete();
                $t->string('application', 40)->nullable();
                $t->unsignedSmallInteger('thickness')->nullable();                // mm
                $t->decimal('area_sqm', 12, 2)->nullable();                       // panel lines
                $t->decimal('qty', 12, 2)->nullable();                            // accessory lines
                $t->string('description', 150)->nullable();                       // an accessory that is not in the catalogue
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->timestamps();
                $t->softDeletes();
                $t->index(['lead_id', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_requirement_lines');
    }
};
