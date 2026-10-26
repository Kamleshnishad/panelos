<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WasteReasonCode extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    public const CATEGORIES = ['defect', 'process_scrap', 'handling', 'transit', 'supplier', 'stock_adjust'];

    /** Seeded for every tenant on first use (WR plan §2); tenants edit or deactivate them. */
    public const DEFAULTS = [
        ['DELAM',      'Delamination / weak bond',          'defect',        'Mix ratio, low sheet temperature, curing time vs line speed'],
        ['VOID',       'Voids / under-fill',                'defect',        'Pour distribution, shot volume vs thickness, line speed vs foam reactivity'],
        ['THICK',      'Thickness out of tolerance',        'defect',        'Line speed sync, belt pressure, dosing'],
        ['DENSITY',    'Density off target',                'defect',        'Chemical temperature (18–22 °C), ratio, recipe'],
        ['WAVY',       'Waviness / sheet deformation',      'defect',        'Sheet tension, decoiler settings, roll-former alignment'],
        ['LENGTH',     'Wrong length / not square',         'defect',        'Saw calibration, length encoder'],
        ['SCRATCH',    'Surface scratch / paint damage',    'defect',        'Guard film, handling, stacking'],
        ['DENT',       'Dent',                              'handling',      'Forklift, stacking, storage'],
        ['STARTUP',    'Line start-up scrap',               'process_scrap', null],
        ['CHANGEOVER', 'Changeover scrap',                  'process_scrap', 'Thickness, colour or core change'],
        ['TRIM',       'Saw trim / off-cut',                'process_scrap', null],
        ['TRANSIT',    'Damaged in transit',                'transit',       'Loading, strapping, vehicle'],
        ['COIL_DEF',   'Coil defect (shade / coating)',     'supplier',      'Raise a claim on the coil supplier'],
        ['EXPIRED',    'Expired material',                  'stock_adjust',  null],
    ];

    protected $fillable = ['company_id', 'code', 'label', 'category', 'root_cause_hint', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];

    public static function ensureDefaults(int $companyId): void
    {
        if (static::withoutGlobalScope('tenant')->where('company_id', $companyId)->exists()) {
            return;
        }
        foreach (self::DEFAULTS as $i => [$code, $label, $category, $hint]) {
            static::create([
                'company_id' => $companyId, 'code' => $code, 'label' => $label,
                'category' => $category, 'root_cause_hint' => $hint, 'sort_order' => $i,
            ]);
        }
    }
}
