<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — procurement: suppliers, purchase orders + items.
 * Receiving a PO line adds to the linked stock row (via StockService), so the
 * stock_transactions ledger doubles as the goods-receipt record (no separate
 * GRN table). softDeletes on all (BaseModel forces SoftDeletes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('company_id');
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('po_no', 50);
            $table->enum('status', ['ordered', 'partial', 'received', 'cancelled'])->default('ordered');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_pct', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'po_no']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->onDelete('cascade');
            $table->enum('material_kind', ['coil', 'chemical', 'consumable']);
            $table->unsignedBigInteger('stock_id')->comment('coil/chemical/consumable stock row to receive into');
            $table->string('item_name', 150);
            $table->string('unit', 20)->default('kg');
            $table->decimal('quantity', 12, 2);
            $table->decimal('rate', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('received_qty', 12, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
