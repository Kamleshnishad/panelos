<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wording that was typed into the PDF templates, made editable per company: the legal entity name
 * (companies.name is the trading name, but "pay to" and "for ..." use the legal one), the document
 * title, an optional certification line, and the terms / exclusions lists. All optional — empty means
 * "use the standard wording", so nothing changes for a company that sets none of them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            if (!Schema::hasColumn('companies', 'legal_name'))          $t->string('legal_name', 255)->nullable()->after('name');
            if (!Schema::hasColumn('companies', 'certification_line'))  $t->string('certification_line', 255)->nullable();
            if (!Schema::hasColumn('companies', 'quotation_title'))     $t->string('quotation_title', 20)->nullable();
            if (!Schema::hasColumn('companies', 'quotation_terms'))     $t->json('quotation_terms')->nullable();
            if (!Schema::hasColumn('companies', 'quotation_exclusions')) $t->json('quotation_exclusions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['legal_name', 'certification_line', 'quotation_title', 'quotation_terms', 'quotation_exclusions']);
        });
    }
};
