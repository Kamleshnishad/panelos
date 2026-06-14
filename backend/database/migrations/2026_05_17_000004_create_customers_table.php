<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['retail', 'wholesale', 'distributor', 'corporate'])->default('retail');
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20);
            $table->string('whatsapp_no', 20)->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('pan', 20)->nullable();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('state_code', 5);
            $table->string('pincode', 10);
            $table->string('country')->default('India');
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->decimal('outstanding_balance', 12, 2)->default(0);
            $table->integer('payment_terms_days')->default(30);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('company_id');
            $table->index(['state_code', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
