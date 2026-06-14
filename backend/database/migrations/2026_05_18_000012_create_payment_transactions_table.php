<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->decimal('amount', 12, 2);
            $table->enum('payment_method', ['bank_transfer', 'cash', 'cheque', 'upi', 'other'])->default('bank_transfer');
            $table->string('reference_no')->nullable();
            $table->timestamp('transaction_date')->useCurrent();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'invoice_id']);
            $table->index(['invoice_id', 'created_at']);
            $table->index('reference_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
