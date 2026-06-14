<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_accessories', function (Blueprint $table) {
            $table->enum('type', ['standard', 'door', 'installation', 'custom'])->default('standard')->after('amount');
            $table->string('description', 500)->nullable()->after('type');
            $table->string('unit', 20)->default('NOS')->after('description');
            $table->enum('door_type', ['sliding_door', 'hinged_door', 'window', 'pass_through'])->nullable()->after('unit');
            $table->unsignedSmallInteger('door_width')->nullable()->after('door_type');
            $table->unsignedSmallInteger('door_height')->nullable()->after('door_width');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_accessories', function (Blueprint $table) {
            $table->dropColumn(['type', 'description', 'unit', 'door_type', 'door_width', 'door_height']);
        });
    }
};
