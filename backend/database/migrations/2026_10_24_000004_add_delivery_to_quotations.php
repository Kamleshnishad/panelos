<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the goods go and who to call there. The buyer's registered address and the delivery site are often
 * different (the client's own proforma carries both); until now the quotation had only a one-line
 * project_location. All optional.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $t) {
            if (!Schema::hasColumn('quotations', 'delivery_address'))    $t->text('delivery_address')->nullable();
            if (!Schema::hasColumn('quotations', 'site_contact_name'))   $t->string('site_contact_name', 100)->nullable();
            if (!Schema::hasColumn('quotations', 'site_contact_phone'))  $t->string('site_contact_phone', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $t) {
            $t->dropColumn(['delivery_address', 'site_contact_name', 'site_contact_phone']);
        });
    }
};
