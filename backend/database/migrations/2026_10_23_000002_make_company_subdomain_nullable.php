<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `companies.subdomain` was NOT NULL, but the subdomain concept is not used:
 * tenancy is resolved from the authenticated user's company_id (BelongsToTenant),
 * never from the request host. Nothing in the app reads the column for behaviour —
 * it is generated at signup, used as a firstOrCreate lookup key by two console
 * commands, and shown in the super-admin list.
 *
 * Requiring it therefore bought nothing and cost a lot: it made
 * `Company::create(['name' => ...])` throw, which killed 184 tests in setUp()
 * across 19 files — every test covering tax, GST, invoicing, payments and
 * reporting. The column stays (signup keeps filling it, so the data is there if
 * subdomains are ever switched on) and keeps its unique index; MySQL and SQLite
 * both allow multiple NULLs under a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('subdomain')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('subdomain')->nullable(false)->change();
        });
    }
};
