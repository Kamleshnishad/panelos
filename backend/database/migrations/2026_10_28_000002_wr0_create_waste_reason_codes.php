<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WR-0: per-tenant, editable reasons for rejections and waste. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_reason_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('code', 40);
            $table->string('label', 120);
            // defect · process_scrap · handling · transit · supplier · stock_adjust
            $table->string('category', 20);
            $table->string('root_cause_hint', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_reason_codes');
    }
};
