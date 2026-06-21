<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-H2 — counter for failed OTP attempts so a code can be invalidated after
 * N wrong tries (brute-force lockout). Defensive: only adds if missing.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'two_factor_attempts')) {
            Schema::table('users', fn (Blueprint $t) => $t->unsignedTinyInteger('two_factor_attempts')->default(0));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'two_factor_attempts')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('two_factor_attempts'));
        }
    }
};
