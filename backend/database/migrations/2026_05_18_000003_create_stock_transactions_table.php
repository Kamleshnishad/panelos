<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->morphs('transactionable');
            $table->enum('type', ['in', 'out', 'adjustment', 'allocation', 'allocation_release'])->default('in');
            $table->decimal('quantity', 12, 2);
            $table->string('unit');
            $table->string('reference_no')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('transaction_date')->useCurrent();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'transactionable_id', 'transactionable_type'], 'stk_trans_company_morphable_idx');
            $table->index(['company_id', 'created_at']);
            $table->index('reference_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
    }
};
