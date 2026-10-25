<?php

namespace App\Services;

use App\Models\Accessory;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadRequirementLine;
use App\Models\PanelType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a lead wants, as lines (LEADS_PLAN 5.4), and the one-step "Quote this lead".
 *
 * Panel lines say panel type, application, thickness and area; accessory lines say which accessory and how many. "Quote this lead" turns them into a
 * quotation draft (or a BOQ) by calling QuotationService::create, the same entry point the quotation screen's own save uses, so the quotation and BOQ
 * modules are used exactly as they are and nothing in them is changed. What it fills in, and what it deliberately does not:
 *   - the specification of each panel row starts from the quotation form's own defaults (PUF 40 kg/m3, PPGI 0.40 mm both skins, off white, the panel type's HSN
 *     and surface), because a lead does not say; the team changes them on the quotation like any other row;
 *   - the size rows are a starting point: the area is covered with standard 3000 mm lengths (1000 mm wide), rounded up, so the area is not under-quoted;
 *   - RATES ARE LEFT AT ZERO ("rate pending"), exactly as a BOQ is: the quotation cannot be sent until someone prices it. Nothing is priced silently;
 *   - an accessory from the catalogue comes with its catalogue price; a typed one has no price yet.
 */
class LeadRequirementService
{
    public const MAX_LINES = 30;
    public const APPLICATIONS = ['Wall', 'Roof', 'Cold Room', 'Partition', 'Clean Room', 'Ceiling', 'PEB Shade', 'Architectural'];
    public const THICKNESSES = [10, 25, 30, 40, 50, 60, 75, 80, 100, 120, 150, 200];
    public const STANDARD_LENGTH_MM = 3000;

    public function __construct(private LeadService $leads, private QuotationService $quotations)
    {
    }

    /** @return list<array<string,mixed>> */
    public function lines(Lead $lead): array
    {
        return LeadRequirementLine::where('lead_id', $lead->id)->with(['panelType:id,name,code', 'accessory:id,name,unit'])->orderBy('sort_order')->orderBy('id')->get()->map(fn ($l) => [
            'kind' => $l->kind, 'panel_type_id' => $l->panel_type_id, 'panel_type_name' => $l->panelType?->name, 'accessory_id' => $l->accessory_id,
            'accessory_name' => $l->accessory?->name, 'unit' => $l->accessory?->unit, 'application' => $l->application, 'thickness' => $l->thickness,
            'area_sqm' => $l->area_sqm !== null ? (float) $l->area_sqm : null, 'qty' => $l->qty !== null ? (float) $l->qty : null, 'description' => $l->description,
        ])->all();
    }

    /**
     * Replace the lead's requirement with these lines. The panel area adds up into the lead's estimated sqm (the figure the list and insights show).
     * @return list<array<string,mixed>> the saved lines
     */
    public function save(Lead $lead, array $lines, ?User $actor = null): array
    {
        if (count($lines) > self::MAX_LINES) {
            $this->refuse('lines', 'A requirement can have up to ' . self::MAX_LINES . ' lines.');
        }
        $clean = [];
        foreach (array_values($lines) as $i => $l) {
            $n = $i + 1;
            $kind = $l['kind'] ?? null;
            if ($kind === LeadRequirementLine::PANEL) {
                $clean[] = $this->panelLine($lead, $l, $n, $i);
            } elseif ($kind === LeadRequirementLine::ACCESSORY) {
                $clean[] = $this->accessoryLine($lead, $l, $n, $i);
            } else {
                $this->refuse('lines', "Line {$n}: choose whether it is a panel or an accessory.");
            }
        }

        DB::transaction(function () use ($lead, $clean, $actor) {
            LeadRequirementLine::where('lead_id', $lead->id)->delete();
            foreach ($clean as $row) {
                LeadRequirementLine::create($row + ['company_id' => $lead->company_id, 'lead_id' => $lead->id]);
            }
            $sqm = round(array_sum(array_map(fn ($r) => (float) ($r['area_sqm'] ?? 0), $clean)), 2);
            if ($sqm > 0) {
                $lead->update(['est_qty_sqm' => $sqm]);
            }
            $panels = count(array_filter($clean, fn ($r) => $r['kind'] === LeadRequirementLine::PANEL));
            $this->log($lead, $actor, $clean
                ? "Requirement updated: {$panels} panel line" . ($panels === 1 ? '' : 's') . ($sqm > 0 ? " ({$sqm} sqm)" : '') . ', ' . (count($clean) - $panels) . ' accessor' . (count($clean) - $panels === 1 ? 'y' : 'ies')
                : 'Requirement cleared');
        });

        return $this->lines($lead);
    }

    /**
     * The "Quote this lead" step: the quotation draft (or BOQ with $o['as_boq']) made from the panel and accessory lines, linked to the lead.
     * The customer is made or found exactly as "Convert" does it ($o may carry state_code, gstin, customer_id, create_new ...), so the same questions can come back.
     * @return array{quotation_id:int, quotation_no:string, as_boq:bool, customer_id:int}
     */
    public function quote(Lead $lead, ?User $actor, array $o = []): array
    {
        if ($lead->isClosed()) {
            $this->refuse('lead', 'This lead is closed. Reopen it to quote it.');
        }
        $lines = LeadRequirementLine::where('lead_id', $lead->id)->get();
        if (!$lines->contains(fn ($l) => $l->kind === LeadRequirementLine::PANEL)) {
            $this->refuse('lines', 'Add at least one panel line (panel type, thickness and area) to the requirement first.');
        }

        $customerId = $this->leads->ensureCustomer($lead, $o);
        $asBoq = !empty($o['as_boq']);
        $payload = $this->payload($lead, $customerId, $asBoq);
        if (empty($payload['panel_rows'])) {
            $this->refuse('lines', 'The panel types in this requirement are no longer available. Choose them again.');
        }

        $quotation = $this->quotations->create($payload);

        $panels = $lines->where('kind', LeadRequirementLine::PANEL);
        $this->log($lead, $actor, ($asBoq ? 'BOQ' : 'Quotation draft') . " {$quotation->quotation_no} made from the requirement ({$panels->count()} panel line" . ($panels->count() === 1 ? '' : 's')
            . ', ' . round((float) $panels->sum('area_sqm'), 2) . ' sqm). The sizes are a starting point (' . self::STANDARD_LENGTH_MM . ' mm lengths covering the area) and the specification starts from the usual defaults: '
            . 'please check both. Rates are still to be entered.');

        return ['quotation_id' => $quotation->id, 'quotation_no' => $quotation->quotation_no, 'as_boq' => $asBoq, 'customer_id' => $customerId];
    }

    /** The data `QuotationService::create` takes, shaped like the quotation form's own save (a test posts it to the real API to keep it honest). */
    public function payload(Lead $lead, int $customerId, bool $asBoq = false): array
    {
        $lines = LeadRequirementLine::where('lead_id', $lead->id)->orderBy('sort_order')->orderBy('id')->get();
        $types = PanelType::where('company_id', $lead->company_id)->whereIn('id', $lines->pluck('panel_type_id')->filter()->all())->get()->keyBy('id');
        $items = Accessory::where('company_id', $lead->company_id)->whereIn('id', $lines->pluck('accessory_id')->filter()->all())->get()->keyBy('id');

        $rows = [];
        $acc = [];
        foreach ($lines as $l) {
            if ($l->kind === LeadRequirementLine::PANEL && ($pt = $types->get($l->panel_type_id))) {
                $nos = max(1, (int) ceil(((float) $l->area_sqm) / (self::STANDARD_LENGTH_MM / 1000)));
                $rows[] = [
                    'panel_type_id' => $pt->id, 'application' => $l->application, 'thickness' => (int) $l->thickness,
                    'density_type' => 'PUF', 'density_kgm3' => 40,
                    'top_skin_material' => 'PPGI', 'top_skin_thickness' => 0.40, 'top_color' => 'Off White', 'top_surface' => $pt->default_top_surface,
                    'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => 0.40, 'bottom_color' => 'Off White', 'bottom_surface' => 'PLAIN',
                    'guard_film' => false, 'cello_tap' => false, 'hsn_code' => $pt->hsn_code ?: '39259010',
                    'sizes' => [['length_mm' => self::STANDARD_LENGTH_MM, 'nos' => $nos, 'rate_per_sqm' => 0]],
                ];
            } elseif ($l->kind === LeadRequirementLine::ACCESSORY) {
                if ($l->accessory_id && ($a = $items->get($l->accessory_id))) {
                    $price = (float) ($a->unit_price ?: $a->rate ?: 0);
                    $acc[] = ['type' => 'standard', 'accessory_id' => $a->id, 'qty' => (float) $l->qty, 'rate' => $price, 'unit' => $a->unit ?: 'NOS', 'description' => $a->name];
                } else {
                    $acc[] = ['type' => 'custom', 'qty' => (float) $l->qty, 'rate' => 0, 'unit' => 'NOS', 'description' => $l->description ?: 'Accessory'];
                }
            }
        }

        $name = trim((string) ($lead->company_name ?: $lead->contact_name));

        return array_filter([
            'company_id' => $lead->company_id, 'customer_id' => $customerId, 'as_boq' => $asBoq, 'lead_id' => $lead->id,
            'project_name' => $name !== '' ? mb_substr($name . ($lead->city ? ', ' . $lead->city : ''), 0, 255) : null,
            'project_location' => $lead->city ?: null,
            'panel_rows' => $rows, 'accessories' => $acc,
        ], fn ($v) => $v !== null);
    }

    // ── validation of a line ─────────────────────────────────────────────────

    private function panelLine(Lead $lead, array $l, int $n, int $i): array
    {
        $pt = PanelType::where('company_id', $lead->company_id)->where('is_active', true)->find((int) ($l['panel_type_id'] ?? 0));
        if (!$pt) {
            $this->refuse('lines', "Line {$n}: choose one of your panel types.");
        }
        $thickness = (int) ($l['thickness'] ?? 0);
        if (!in_array($thickness, self::THICKNESSES, true)) {
            $this->refuse('lines', "Line {$n}: choose a thickness (" . implode(', ', self::THICKNESSES) . ' mm).');
        }
        $app = isset($l['application']) && trim((string) $l['application']) !== '' ? trim((string) $l['application']) : null;
        if ($app !== null && !in_array($app, self::APPLICATIONS, true)) {
            $this->refuse('lines', "Line {$n}: choose one of the applications in the list.");
        }
        $area = is_numeric($l['area_sqm'] ?? null) ? round((float) $l['area_sqm'], 2) : 0;
        if ($area <= 0 || $area > 1000000) {
            $this->refuse('lines', "Line {$n}: give the area in square metres (more than 0).");
        }

        return ['kind' => LeadRequirementLine::PANEL, 'panel_type_id' => $pt->id, 'application' => $app, 'thickness' => $thickness, 'area_sqm' => $area, 'sort_order' => $i];
    }

    private function accessoryLine(Lead $lead, array $l, int $n, int $i): array
    {
        $qty = is_numeric($l['qty'] ?? null) ? round((float) $l['qty'], 2) : 0;
        if ($qty <= 0 || $qty > 1000000) {
            $this->refuse('lines', "Line {$n}: give the quantity (more than 0).");
        }
        $id = null;
        $desc = null;
        if (!empty($l['accessory_id'])) {
            $a = Accessory::where('company_id', $lead->company_id)->where('is_active', true)->find((int) $l['accessory_id']);
            if (!$a) {
                $this->refuse('lines', "Line {$n}: choose one of your accessories.");
            }
            $id = $a->id;
        } else {
            $desc = trim((string) ($l['description'] ?? ''));
            if ($desc === '') {
                $this->refuse('lines', "Line {$n}: choose an accessory or describe it.");
            }
            $desc = mb_substr($desc, 0, 150);
        }

        return ['kind' => LeadRequirementLine::ACCESSORY, 'accessory_id' => $id, 'description' => $desc, 'qty' => $qty, 'sort_order' => $i];
    }

    private function log(Lead $lead, ?User $actor, string $text): void
    {
        LeadActivity::create(['company_id' => $lead->company_id, 'lead_id' => $lead->id, 'user_id' => $actor?->id, 'type' => 'note', 'description' => $text, 'activity_date' => now()]);
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
