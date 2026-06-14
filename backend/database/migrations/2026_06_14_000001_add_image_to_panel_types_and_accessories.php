<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_types', function (Blueprint $table) {
            if (!Schema::hasColumn('panel_types', 'image')) {
                $table->string('image', 255)->nullable()->after('description');
            }
        });
        Schema::table('accessories', function (Blueprint $table) {
            if (!Schema::hasColumn('accessories', 'image')) {
                $table->string('image', 255)->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('panel_types', function (Blueprint $table) {
            if (Schema::hasColumn('panel_types', 'image')) $table->dropColumn('image');
        });
        Schema::table('accessories', function (Blueprint $table) {
            if (Schema::hasColumn('accessories', 'image')) $table->dropColumn('image');
        });
    }
};
