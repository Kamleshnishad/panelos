<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('plan', 20);
            $table->integer('months')->default(1);
            $table->decimal('total_amount', 12, 2);              // what the tenant paid (GST-inclusive)
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('gst_amount', 12, 2)->default(0);
            $table->decimal('gst_rate', 5, 2)->default(18);
            $table->string('method', 20)->default('manual');     // manual | razorpay
            $table->string('reference', 120)->nullable();        // razorpay_payment_id or note
            $table->string('invoice_no', 40)->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->index('company_id');
            $table->index('created_at');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('utm_source', 60)->nullable()->after('settings');
            $table->string('utm_medium', 60)->nullable()->after('utm_source');
            $table->string('utm_campaign', 80)->nullable()->after('utm_medium');
            $table->string('signup_referrer', 255)->nullable()->after('utm_campaign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign', 'signup_referrer']);
        });
    }
};
