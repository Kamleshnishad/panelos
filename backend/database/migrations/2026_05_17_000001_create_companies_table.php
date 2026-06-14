<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subdomain')->unique();
            $table->string('logo')->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('pan', 20)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('state_code', 5)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_no', 50)->nullable();
            $table->string('bank_ifsc', 20)->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('authorized_signatory')->nullable();
            $table->string('signatory_phone', 20)->nullable();
            $table->string('primary_color')->default('#1a237e');
            $table->string('secondary_color')->default('#f57f17');
            $table->string('quotation_prefix')->default('SCP');
            $table->string('invoice_prefix')->default('INV');
            $table->string('order_prefix')->default('ORD');
            $table->string('challan_prefix')->default('CH');
            $table->tinyInteger('financial_year_start')->default(4);
            $table->boolean('e_invoice_applicable')->default(false);
            $table->boolean('tcs_applicable')->default(false);
            $table->enum('subscription_plan', ['starter', 'growth', 'pro', 'enterprise'])->default('growth');
            $table->enum('subscription_status', ['active', 'trial', 'expired'])->default('trial');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
