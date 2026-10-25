<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 4.1 (audit LC-03). The website enquiry form: `leads.landing_page` is the page the buyer was on (address without the query string),
 * and `lead_inbound_tokens.allowed_origins` is an optional list of websites (https://www.example.com) that may post with that address from a browser.
 * Empty means any website, which is how the address worked before.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            if (!Schema::hasColumn('leads', 'landing_page')) {
                $t->string('landing_page', 500)->nullable()->after('utm_content');
            }
        });
        Schema::table('lead_inbound_tokens', function (Blueprint $t) {
            if (!Schema::hasColumn('lead_inbound_tokens', 'allowed_origins')) {
                $t->json('allowed_origins')->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn('landing_page'));
        Schema::table('lead_inbound_tokens', fn (Blueprint $t) => $t->dropColumn('allowed_origins'));
    }
};
