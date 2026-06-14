<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->date('snapshot_date');
            $table->integer('total_invoices');
            $table->decimal('total_revenue', 15, 2);
            $table->decimal('average_invoice_value', 12, 2);
            $table->integer('total_quantity_sold');
            $table->decimal('total_inventory_value', 15, 2);
            $table->integer('total_stock_units');
            $table->decimal('accounts_receivable', 15, 2);
            $table->integer('invoices_overdue');
            $table->decimal('tax_collected', 12, 2);
            $table->integer('active_customers');
            $table->integer('top_panel_type_id')->nullable();
            $table->string('performance_status'); // 'excellent', 'good', 'average', 'poor'
            $table->timestamps();

            $table->index(['company_id', 'snapshot_date']);
            $table->unique(['company_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_snapshots');
    }
};
