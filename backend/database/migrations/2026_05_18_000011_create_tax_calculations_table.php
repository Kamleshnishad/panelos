<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->onDelete('cascade');
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('taxable_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('sgst_amount', 12, 2)->nullable()->default(0);
            $table->decimal('cgst_amount', 12, 2)->nullable()->default(0);
            $table->decimal('igst_amount', 12, 2)->nullable()->default(0);
            $table->timestamp('created_at');

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_calculations');
    }
};
