<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production stage sign-off (PB-031 / PB-032 / FR-001).
 *
 * Until now the stage system was decoration: BatchStageLogService::
 * areAllStagesCompleted() existed but was never called, and completeBatch()
 * checked only that the batch was in_progress — so a batch could be completed,
 * QC'd and dispatched having logged zero stages. Nor was anything signed off:
 * batch_stage_logs recorded only who logged the step.
 *
 * Enforcement is OPT-IN per company, deliberately:
 *   - areAllStagesCompleted() returns false when a company has no stages at all,
 *     so switching it on globally would make batches impossible to complete for
 *     any tenant not using stages;
 *   - batches already in flight have no stage logs and would be stranded.
 * A tenant turns `enforce_production_stages` on once their stages are set up and
 * their open batches are clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('enforce_production_stages')->default(false)->after('financial_year_start');
            // Four-eyes: when on, the approver may not be the person who ran the
            // step. Off by default — a single-shift factory may have one person.
            $table->boolean('stage_approval_four_eyes')->default(false)->after('enforce_production_stages');
        });

        Schema::table('production_stages', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('is_active');
        });

        Schema::table('batch_stage_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->after('logged_by_user_id');
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->string('rejection_reason', 500)->nullable()->after('rejected_at');
        });

        // enum() can't be extended portably through Blueprint. SQLite stores
        // enums as varchar, so it needs nothing.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE batch_stage_logs MODIFY COLUMN status
                 ENUM('pending','in_progress','pending_approval','completed','rejected')
                 NOT NULL DEFAULT 'pending'"
            );
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['enforce_production_stages', 'stage_approval_four_eyes']);
        });
        Schema::table('production_stages', function (Blueprint $table) {
            $table->dropColumn('requires_approval');
        });
        Schema::table('batch_stage_logs', function (Blueprint $table) {
            $table->dropColumn(['approved_by_user_id', 'approved_at', 'rejected_at', 'rejection_reason']);
        });
    }
};
