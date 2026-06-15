<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->json('plan_prices')->nullable()->after('platform_sac');   // {starter:..,growth:..,..}
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->enum('type', ['percent', 'flat'])->default('percent');
            $table->decimal('value', 10, 2);                 // % or ₹ off
            $table->integer('max_uses')->nullable();         // null = unlimited
            $table->integer('used_count')->default(0);
            $table->date('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', fn (Blueprint $t) => $t->dropColumn('plan_prices'));
        Schema::dropIfExists('coupons');
    }
};
