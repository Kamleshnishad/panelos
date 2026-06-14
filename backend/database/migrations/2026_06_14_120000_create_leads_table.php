<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead / Inquiry management — top of the sales funnel (before Quotation).
 * Additive; links out to customers/quotations one-way (those tables untouched).
 * Has company_id + deleted_at so BaseModel is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('lead_no', 50);
            $table->string('contact_name', 150);
            $table->string('company_name', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('city', 120)->nullable();
            $table->enum('source', ['Website', 'Phone', 'WhatsApp', 'Referral', 'IndiaMART', 'Justdial', 'Exhibition', 'Walk-in', 'Other'])->default('Other');
            $table->text('requirement')->nullable();
            $table->string('application', 40)->nullable();
            $table->decimal('est_qty_sqm', 12, 2)->nullable();
            $table->decimal('est_value', 14, 2)->nullable();
            $table->enum('status', ['new', 'contacted', 'qualified', 'quoted', 'won', 'lost'])->default('new');
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->date('next_follow_up_date')->nullable();
            $table->string('lost_reason', 255)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('quotation_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'lead_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'next_follow_up_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
