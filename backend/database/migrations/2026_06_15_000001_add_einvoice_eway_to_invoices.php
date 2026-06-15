<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds e-Invoice (IRN/QR) and e-Way Bill fields to the invoices table.
 * Fields can be populated either via a live GSP API (when keys are configured)
 * or by manual entry from the GST portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // e-Invoice (IRN)
            $table->string('irn', 64)->nullable()->after('terms');
            $table->text('irn_qr')->nullable()->after('irn');           // base64 QR image or raw string
            $table->string('irn_ack_no', 30)->nullable()->after('irn_qr');
            $table->timestamp('irn_ack_date')->nullable()->after('irn_ack_no');
            $table->enum('irn_status', ['none', 'generated', 'cancelled'])->default('none')->after('irn_ack_date');
            $table->string('irn_cancel_reason', 255)->nullable()->after('irn_status');
            $table->timestamp('irn_generated_at')->nullable()->after('irn_cancel_reason');

            // e-Way Bill
            $table->string('eway_bill_no', 20)->nullable()->after('irn_generated_at');
            $table->timestamp('eway_bill_generated_at')->nullable()->after('eway_bill_no');
            $table->timestamp('eway_bill_expiry')->nullable()->after('eway_bill_generated_at');
            $table->enum('eway_bill_status', ['none', 'active', 'cancelled', 'expired'])->default('none')->after('eway_bill_expiry');
            $table->string('eway_transporter_id', 50)->nullable()->after('eway_bill_status');
            $table->string('eway_vehicle_no', 20)->nullable()->after('eway_transporter_id');
            $table->string('eway_transport_mode', 20)->nullable()->after('eway_vehicle_no'); // road/rail/air/ship
            $table->decimal('eway_distance_km', 8, 0)->nullable()->after('eway_transport_mode');
            $table->string('eway_doc_no', 30)->nullable()->after('eway_distance_km');       // transport doc no
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'irn', 'irn_qr', 'irn_ack_no', 'irn_ack_date', 'irn_status',
                'irn_cancel_reason', 'irn_generated_at',
                'eway_bill_no', 'eway_bill_generated_at', 'eway_bill_expiry',
                'eway_bill_status', 'eway_transporter_id', 'eway_vehicle_no',
                'eway_transport_mode', 'eway_distance_km', 'eway_doc_no',
            ]);
        });
    }
};
