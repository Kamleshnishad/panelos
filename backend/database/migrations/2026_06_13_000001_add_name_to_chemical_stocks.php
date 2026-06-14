<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chemical_stocks', function (Blueprint $table) {
            // Self-describing name + category since there is no chemicals master table
            $table->string('name', 100)->nullable()->after('chemical_id');
            $table->string('category', 50)->nullable()->after('name')->comment('e.g. Polyol, Isocyanate, Blowing Agent');
            // chemical_id can be null — name is the identifier now
            $table->unsignedBigInteger('chemical_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('chemical_stocks', function (Blueprint $table) {
            $table->dropColumn(['name', 'category']);
        });
    }
};
