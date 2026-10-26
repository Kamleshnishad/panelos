<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WR-2: every rejected panel is a record — how many, which length, why, where it
 * was found and who raised it. A cut line's rejected_nos is the sum of its open
 * records. QC inspections also store measured values; rejection photos reuse
 * the attachments table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('batch_id')->constrained('production_batches')->onDelete('cascade');
            $table->foreignId('cut_line_id')->nullable()->constrained('production_cut_lines')->onDelete('set null');
            $table->foreignId('reason_code_id')->constrained('waste_reason_codes')->onDelete('restrict');
            $table->foreignId('quality_control_id')->nullable()->constrained('quality_controls')->onDelete('set null');
            $table->string('stage_found', 20); // saw · qc · stacking · store
            $table->unsignedInteger('nos');
            $table->unsignedInteger('panel_length_mm');
            $table->unsignedInteger('width_mm')->default(1000);
            $table->decimal('sqm', 12, 4);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('open'); // open · void (WR-3 adds dispositions)
            $table->foreignId('raised_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'reason_code_id']);
        });

        Schema::table('quality_controls', function (Blueprint $table) {
            $table->json('measurements')->nullable()->after('checklist');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE attachments MODIFY type ENUM('qc_photo','stage_photo','delivery_proof','signature','rejection_photo') NOT NULL");
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'])) {
            DB::table('attachments')->where('type', 'rejection_photo')->delete();
            DB::statement("ALTER TABLE attachments MODIFY type ENUM('qc_photo','stage_photo','delivery_proof','signature') NOT NULL");
        }
        Schema::table('quality_controls', function (Blueprint $table) {
            $table->dropColumn('measurements');
        });
        Schema::dropIfExists('production_rejections');
    }
};
