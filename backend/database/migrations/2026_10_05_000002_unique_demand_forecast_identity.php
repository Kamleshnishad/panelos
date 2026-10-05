<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the newest row per forecast identity so the unique index can be added
        // on databases where repeated "Generate" clicks already created duplicates.
        $dupes = DB::table('demand_forecasts')
            ->select('company_id', 'panel_type_id', 'forecast_date', 'forecast_period_days')
            ->selectRaw('MAX(id) as keep_id')
            ->groupBy('company_id', 'panel_type_id', 'forecast_date', 'forecast_period_days')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $d) {
            DB::table('demand_forecasts')
                ->where('company_id', $d->company_id)
                ->where('forecast_date', $d->forecast_date)
                ->where('forecast_period_days', $d->forecast_period_days)
                ->when($d->panel_type_id === null,
                    fn ($q) => $q->whereNull('panel_type_id'),
                    fn ($q) => $q->where('panel_type_id', $d->panel_type_id))
                ->where('id', '<>', $d->keep_id)
                ->delete();
        }

        Schema::table('demand_forecasts', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'panel_type_id', 'forecast_date', 'forecast_period_days'],
                'demand_forecasts_identity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('demand_forecasts', function (Blueprint $table) {
            $table->dropUnique('demand_forecasts_identity_unique');
        });
    }
};
