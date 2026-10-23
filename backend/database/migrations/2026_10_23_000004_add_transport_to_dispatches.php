<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actual transport is settled at DISPATCH, not at quotation (PB-021).
 *
 * The quotation's transport_amount is only ever an estimate — the real cost
 * depends on which vehicle is available on the day and changes several times
 * before the goods move. There was nowhere to record what it actually came to,
 * which is why transport never reached an invoice at all.
 *
 * vehicle_no also fixes a latent no-op: NotificationDispatchService already
 * reads $dispatch->vehicle_no for the "goods dispatched" message, and the
 * column never existed, so that line was always blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            $table->decimal('transport_amount', 12, 2)->default(0)->after('tracking_number');
            $table->string('transporter_name', 150)->nullable()->after('transport_amount');
            $table->string('vehicle_no', 30)->nullable()->after('transporter_name');
        });
    }

    public function down(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropColumn(['transport_amount', 'transporter_name', 'vehicle_no']);
        });
    }
};
