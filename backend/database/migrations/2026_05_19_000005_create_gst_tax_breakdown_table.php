<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gst_tax_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('company_id');
            $table->enum('transaction_type', ['B2B', 'B2C', 'B2G', 'EXPORT'])->default('B2B');
            $table->decimal('gst_rate', 5, 2);
            $table->decimal('sgst_amount', 12, 2)->default(0); // State GST
            $table->decimal('cgst_amount', 12, 2)->default(0); // Central GST
            $table->decimal('igst_amount', 12, 2)->default(0); // Integrated GST
            $table->decimal('cess_amount', 12, 2)->default(0); // Additional duty
            $table->decimal('total_tax_amount', 12, 2);
            $table->string('supplier_state', 2)->nullable();
            $table->string('customer_state', 2)->nullable();
            $table->boolean('is_reverse_charge')->default(false);
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['invoice_id', 'company_id']);
            $table->index(['supplier_state', 'customer_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gst_tax_breakdowns');
    }
};
