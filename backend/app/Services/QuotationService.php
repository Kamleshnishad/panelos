<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationItemSize;
use Illuminate\Support\Facades\DB;

class QuotationService
{
    // ── Pricing engine constants ──────────────────────────────────────────

    // Extra ₹/SQM for each skin thickness above 0.40mm baseline (string keys — float keys silently truncate to int)
    private array $skinThicknessPremium = ['0.30' => -50, '0.35' => -25, '0.40' => 0, '0.45' => 30, '0.50' => 60, '0.60' => 100];

    // Extra ₹/SQM for panel thickness above 30mm baseline
    private array $thicknessPremium = [30 => 0, 40 => 80, 50 => 160, 60 => 240, 75 => 320, 80 => 360, 100 => 480, 120 => 600, 150 => 750, 200 => 1000];

    // PIR density premium over PUF
    private array $densityPremium = ['PUF' => 0, 'PIR' => 150];

    // Customer type multipliers
    private array $customerMultiplier = ['retail' => 1.00, 'wholesale' => 0.95, 'distributor' => 0.90, 'corporate' => 1.00];

    // Quality grade multipliers
    private array $qualityMultiplier = ['High' => 1.00, 'Medium' => 0.97, 'Standard' => 0.93];

    // HSN codes by panel type category
    private array $hsnCodes = ['panel' => '39259010', 'accessory_gi' => '73089090', 'accessory_al' => '76169990', 'installation' => '994568', 'transport' => '996511'];

    // ── Create ────────────────────────────────────────────────────────────

    public function create(array $data): Quotation
    {
        return DB::transaction(function () use ($data) {
            $companyId = $data['company_id'];
            $company   = Company::findOrFail($companyId);
            $customer  = Customer::where('company_id', $companyId)->findOrFail($data['customer_id']);

            $quotationNo = $this->generateQuotationNumber($companyId, $data['quotation_prefix'] ?? 'SCP');
            $isInterState = $this->detectInterState($company, $customer);

            $quotation = Quotation::create([
                'company_id'        => $companyId,
                'quotation_no'      => $quotationNo,
                'quotation_prefix'  => $data['quotation_prefix'] ?? 'SCP',
                'revision_number'   => 1,
                'parent_quotation_id' => null,
                'customer_id'       => $customer->id,
                // BOQ-first flow: a BOQ is created without rates as its own stage,
                // then converted to a 'draft' quotation when sales prices it.
                'status'            => !empty($data['as_boq']) ? 'boq' : 'draft',
                'project_name'      => $data['project_name'] ?? null,
                'project_location'  => $data['project_location'] ?? null,
                'quality_grade'     => $data['quality_grade'] ?? 'High',
                'validity_days'     => $data['validity_days'] ?? 10,
                'quoted_on'         => $data['quoted_on'] ?? now()->toDateString(),
                'valid_until'       => now()->addDays($data['validity_days'] ?? 10)->toDateString(),
                'discount_pct'      => $data['discount_pct'] ?? 0,
                'transport_fixed'   => $data['transport_fixed'] ?? false,
                'transport_amount'  => $data['transport_amount'] ?? 0,
                'advance_pct'       => $data['advance_pct'] ?? 50,
                'is_inter_state'    => $isInterState,
                'notes'             => $data['notes'] ?? null,
                // Zero placeholders — recalculated below
                'panel_subtotal' => 0, 'accessory_subtotal' => 0, 'installation_amount' => 0,
                'subtotal' => 0, 'discount_amount' => 0, 'taxable_amount' => 0,
                'cgst_amount' => 0, 'sgst_amount' => 0, 'igst_amount' => 0,
                'tax_amount' => 0, 'round_off' => 0, 'total_amount' => 0,
                'advance_amount' => 0, 'balance_amount' => 0, 'total_sqm' => 0,
            ]);

            $this->saveItems($quotation, $data['panel_rows'] ?? [], $data['quality_grade'] ?? 'High', $customer);
            $this->saveAccessories($quotation, $data['accessories'] ?? []);
            $this->recalculate($quotation);

            return $quotation->load('items.sizes', 'items.panelType', 'accessories', 'customer');
        });
    }

    // ── Update ────────────────────────────────────────────────────────────

    public function update(Quotation $quotation, array $data): Quotation
    {
        return DB::transaction(function () use ($quotation, $data) {
            if (!in_array($quotation->status, ['boq', 'draft', 'sent'])) {
                throw new \Exception('Only BOQs, draft or sent quotations can be edited.');
            }

            $company  = $quotation->company;
            // Tenant guard: a quotation may only point at a customer in its own company.
            $customer = Customer::where('company_id', $quotation->company_id)
                ->findOrFail($data['customer_id'] ?? $quotation->customer_id);

            $quotation->update([
                'customer_id'     => $customer->id,
                'project_name'    => $data['project_name'] ?? $quotation->project_name,
                'project_location'=> $data['project_location'] ?? $quotation->project_location,
                'quality_grade'   => $data['quality_grade'] ?? $quotation->quality_grade,
                'validity_days'   => $data['validity_days'] ?? $quotation->validity_days,
                'quoted_on'       => $data['quoted_on'] ?? $quotation->quoted_on,
                'valid_until'     => now()->addDays($data['validity_days'] ?? $quotation->validity_days)->toDateString(),
                'discount_pct'    => $data['discount_pct'] ?? $quotation->discount_pct,
                'transport_fixed' => $data['transport_fixed'] ?? $quotation->transport_fixed,
                'transport_amount'=> $data['transport_amount'] ?? $quotation->transport_amount,
                'advance_pct'     => $data['advance_pct'] ?? $quotation->advance_pct,
                'is_inter_state'  => $this->detectInterState($company, $customer),
                'notes'           => $data['notes'] ?? $quotation->notes,
            ]);

            if (isset($data['panel_rows'])) {
                // Delete old sizes first (cascade will handle item sizes)
                foreach ($quotation->items as $item) {
                    $item->sizes()->delete();
                }
                $quotation->items()->delete();
                $this->saveItems($quotation, $data['panel_rows'], $data['quality_grade'] ?? $quotation->quality_grade, $customer);
            }

            if (isset($data['accessories'])) {
                $quotation->accessories()->detach();
                $this->saveAccessories($quotation, $data['accessories']);
            }

            $this->recalculate($quotation);

            return $quotation->fresh('items.sizes', 'items.panelType', 'accessories', 'customer');
        });
    }

    // ── Revision system ───────────────────────────────────────────────────

    public function revise(Quotation $original): Quotation
    {
        return DB::transaction(function () use ($original) {
            // Lock the original
            $original->update(['status' => 'revised']);

            $revNum = $original->revisions()->count() + 2; // v1 is original, v2 is first revision, etc.
            $newNo  = $original->quotation_no . '-v' . $revNum;

            $newQuotation = $original->replicate([
                'status', 'sent_at', 'accepted_at', 'rejected_at', 'expired_at',
            ]);
            $newQuotation->quotation_no      = $newNo;
            $newQuotation->status            = 'draft';
            $newQuotation->revision_number   = $revNum;
            $newQuotation->parent_quotation_id = $original->id;
            $newQuotation->save();

            // Copy items
            foreach ($original->items()->with('sizes')->get() as $item) {
                $newItem = $item->replicate(['quotation_id']);
                $newItem->quotation_id = $newQuotation->id;
                $newItem->save();

                foreach ($item->sizes as $size) {
                    $newSize = $size->replicate(['quotation_item_id', 'sqm']); // sqm is a generated column
                    $newSize->quotation_item_id = $newItem->id;
                    $newSize->save();
                }
            }

            // Copy accessories
            foreach ($original->accessories as $acc) {
                $newQuotation->accessories()->attach($acc->id, [
                    'quantity'    => $acc->pivot->quantity,
                    'unit_price'  => $acc->pivot->unit_price,
                    'amount'      => $acc->pivot->amount,
                    'type'        => $acc->pivot->type        ?? 'standard',
                    'description' => $acc->pivot->description ?? null,
                    'unit'        => $acc->pivot->unit        ?? 'NOS',
                    'door_type'   => $acc->pivot->door_type   ?? null,
                    'door_width'  => $acc->pivot->door_width  ?? null,
                    'door_height' => $acc->pivot->door_height ?? null,
                ]);
            }

            return $newQuotation->load('items.sizes', 'items.panelType', 'accessories', 'customer');
        });
    }

    // ── Status transitions ────────────────────────────────────────────────

    public function send(Quotation $quotation): Quotation
    {
        if ($quotation->status !== 'draft') {
            throw new \Exception('Only draft quotations can be sent.');
        }
        if ($quotation->items()->count() === 0) {
            throw new \Exception('Cannot send a quotation with no panel items.');
        }
        // BOQ-first flow: sales must enter rates before the quotation can be sent.
        if ($quotation->items()->where('rate_per_sqm', '<=', 0)->exists()) {
            throw new \Exception('Rates are pending. Enter rates for all panel rows before sending.');
        }

        $quotation->update(['status' => 'sent', 'sent_at' => now()]);
        return $quotation->fresh();
    }

    /**
     * Convert a BOQ (rate-less technical stage) into a priced draft quotation.
     * Sales then enters rates on the resulting draft before sending.
     */
    public function convertToQuotation(Quotation $quotation): Quotation
    {
        if ($quotation->status !== 'boq') {
            throw new \Exception('Only a BOQ can be converted to a quotation.');
        }
        if ($quotation->items()->count() === 0) {
            throw new \Exception('Cannot convert a BOQ with no panel items.');
        }
        $quotation->update(['status' => 'draft']);
        return $quotation->fresh('items.sizes', 'items.panelType', 'accessories', 'customer');
    }

    public function accept(Quotation $quotation): Quotation
    {
        if ($quotation->status !== 'sent') {
            throw new \Exception('Only sent quotations can be accepted.');
        }
        $quotation->update(['status' => 'accepted', 'accepted_at' => now()]);
        return $quotation->fresh();
    }

    public function reject(Quotation $quotation): Quotation
    {
        if (!in_array($quotation->status, ['draft', 'sent'])) {
            throw new \Exception('Cannot reject quotation in current status.');
        }
        $quotation->update(['status' => 'rejected', 'rejected_at' => now()]);
        return $quotation->fresh();
    }

    public function delete(Quotation $quotation): bool
    {
        if (!in_array($quotation->status, ['boq', 'draft'])) {
            throw new \Exception('Only BOQs or draft quotations can be deleted.');
        }
        return $quotation->delete();
    }

    public function expire(Quotation $quotation): Quotation
    {
        if (!in_array($quotation->status, ['draft', 'sent'])) {
            throw new \Exception('Only draft or sent quotations can be expired.');
        }
        $quotation->update(['status' => 'expired', 'expired_at' => now()]);
        return $quotation->fresh();
    }

    public function duplicate(Quotation $original): Quotation
    {
        return DB::transaction(function () use ($original) {
            $newNo = $this->generateQuotationNumber($original->company_id, $original->quotation_prefix ?? 'SCP');

            $newQuotation = $original->replicate([
                'quotation_no', 'status', 'revision_number', 'parent_quotation_id',
                'sent_at', 'accepted_at', 'rejected_at', 'expired_at',
            ]);
            $newQuotation->quotation_no      = $newNo;
            $newQuotation->status            = 'draft';
            $newQuotation->revision_number   = 1;
            $newQuotation->parent_quotation_id = null;
            $newQuotation->quoted_on         = now()->toDateString();
            $newQuotation->valid_until       = now()->addDays($original->validity_days ?? 10)->toDateString();
            $newQuotation->save();

            foreach ($original->items()->with('sizes')->get() as $item) {
                $newItem = $item->replicate(['quotation_id']);
                $newItem->quotation_id = $newQuotation->id;
                $newItem->save();
                foreach ($item->sizes as $size) {
                    $newSize = $size->replicate(['quotation_item_id', 'sqm']); // sqm is a generated column
                    $newSize->quotation_item_id = $newItem->id;
                    $newSize->save();
                }
            }

            foreach ($original->accessories as $acc) {
                $newQuotation->accessories()->attach($acc->id, [
                    'quantity'    => $acc->pivot->quantity,
                    'unit_price'  => $acc->pivot->unit_price,
                    'amount'      => $acc->pivot->amount,
                    'type'        => $acc->pivot->type        ?? 'standard',
                    'description' => $acc->pivot->description ?? null,
                    'unit'        => $acc->pivot->unit        ?? 'NOS',
                    'door_type'   => $acc->pivot->door_type   ?? null,
                    'door_width'  => $acc->pivot->door_width  ?? null,
                    'door_height' => $acc->pivot->door_height ?? null,
                ]);
            }

            $this->recalculate($newQuotation);

            return $newQuotation->load('items.sizes', 'items.panelType', 'accessories', 'customer');
        });
    }

    // ── Pricing engine ────────────────────────────────────────────────────

    public function calculateRate(PanelType $panelType, array $rowData, string $qualityGrade, Customer $customer): float
    {
        $baseRate = $panelType->base_price ?? 850;

        // Thickness premium
        $thickness = (int) ($rowData['thickness'] ?? 50);
        $baseRate += $this->thicknessPremium[$thickness] ?? 0;

        // Top skin thickness premium (use string key to avoid float truncation)
        $topThick = number_format((float) ($rowData['top_skin_thickness'] ?? 0.40), 2);
        $baseRate += $this->skinThicknessPremium[$topThick] ?? 0;

        // Bottom skin thickness premium
        $botThick = number_format((float) ($rowData['bottom_skin_thickness'] ?? 0.40), 2);
        $baseRate += ($this->skinThicknessPremium[$botThick] ?? 0) * 0.5; // bottom contributes 50%

        // Density premium
        $densityType = $rowData['density_type'] ?? 'PUF';
        $baseRate += $this->densityPremium[$densityType] ?? 0;

        // RIBBED surface premium
        if (($rowData['top_surface'] ?? 'PLAIN') === 'RIBBED') {
            $baseRate += 30;
        }

        // Customer type multiplier
        $custType = $customer->type ?? 'retail';
        $multiplier = $this->customerMultiplier[$custType] ?? 1.0;

        // Quality grade multiplier
        $qualMult = $this->qualityMultiplier[$qualityGrade] ?? 1.0;

        $finalRate = $baseRate * $multiplier * $qualMult;

        // Round to nearest 5
        return round($finalRate / 5) * 5;
    }

    // ── Internal helpers ──────────────────────────────────────────────────

    private function saveItems(Quotation $quotation, array $panelRows, string $qualityGrade, Customer $customer): void
    {
        foreach ($panelRows as $idx => $row) {
            $panelType = PanelType::where('company_id', $quotation->company_id)
                ->findOrFail($row['panel_type_id']);

            // Calculate totals from size rows
            $totalSqm    = 0;
            $totalAmount = 0;

            // Auto-calculate rate if not overridden
            $sizeRows = $row['sizes'] ?? [];
            $firstRate = null;

            foreach ($sizeRows as $sr) {
                $sqm = ($sr['length_mm'] / 1000) * (1000 / 1000) * $sr['nos'];
                $totalSqm += $sqm;
            }

            foreach ($sizeRows as $sr) {
                $sqm = ($sr['length_mm'] / 1000) * (1000 / 1000) * $sr['nos'];
                // BOQ-first flow: store the rate exactly as entered. 0 = "rate pending"
                // (sales enters it later). The pricing engine is only a suggestion
                // surfaced via the /suggested-rate endpoint, never applied silently.
                $rate = (float) ($sr['rate_per_sqm'] ?? 0);
                $firstRate = $firstRate ?? $rate;
                $totalAmount += $sqm * $rate;
            }

            $avgRate = $totalSqm > 0 ? $totalAmount / $totalSqm : ($firstRate ?? 0);

            $item = QuotationItem::create([
                'quotation_id'           => $quotation->id,
                'panel_type_id'          => $panelType->id,
                'application'            => $row['application'] ?? null,
                'thickness'              => $row['thickness'] ?? null,
                'density_type'           => $row['density_type'] ?? 'PUF',
                'density_kgm3'           => $row['density_kgm3'] ?? 40,
                'top_skin_material'      => $row['top_skin_material'] ?? 'PPGI',
                'top_skin_thickness'     => $row['top_skin_thickness'] ?? 0.40,
                'top_color'              => $row['top_color'] ?? 'Off White',
                'top_color_ral'          => $row['top_color_ral'] ?? null,
                'top_surface'            => $row['top_surface'] ?? 'PLAIN',
                'bottom_skin_material'   => $row['bottom_skin_material'] ?? 'PPGI',
                'bottom_skin_thickness'  => $row['bottom_skin_thickness'] ?? 0.40,
                'bottom_color'           => $row['bottom_color'] ?? 'Off White',
                'bottom_color_ral'       => $row['bottom_color_ral'] ?? null,
                'bottom_surface'         => $row['bottom_surface'] ?? 'PLAIN',
                'guard_film'             => $row['guard_film'] ?? false,
                'cello_tap'              => $row['cello_tap'] ?? false,
                'fixing_system'          => $row['fixing_system'] ?? null,
                'hsn_code'               => $row['hsn_code'] ?? $this->hsnCodes['panel'],
                'total_sqm'              => $totalSqm,
                'rate_per_sqm'           => $avgRate,
                'amount'                 => $totalAmount,
                'quantity'               => $totalSqm,
                'unit_price'             => $avgRate,
                'sort_order'             => $idx,
            ]);

            // Save size rows
            foreach ($sizeRows as $si => $sr) {
                $sqm  = ($sr['length_mm'] / 1000) * (1000 / 1000) * $sr['nos'];
                $rate = (float) ($sr['rate_per_sqm'] ?? 0);   // 0 = pending

                QuotationItemSize::create([
                    'quotation_item_id' => $item->id,
                    'length_mm'         => $sr['length_mm'],
                    'width_mm'          => 1000,
                    'nos'               => $sr['nos'],
                    'rate_per_sqm'      => $rate,
                    'amount'            => $sqm * $rate,
                    'sort_order'        => $si,
                ]);
            }
        }
    }

    private function saveAccessories(Quotation $quotation, array $accessories): void
    {
        $installationTotal = 0;

        foreach ($accessories as $acc) {
            $type = $acc['type'] ?? null;
            $qty  = (float) ($acc['qty'] ?? $acc['quantity'] ?? 1);
            $rate = (float) ($acc['rate'] ?? $acc['unit_price'] ?? 0);
            $amt  = $qty * $rate;

            if ($type === 'installation') {
                $installationTotal += $amt;
                continue;
            }

            // door/custom/standard — if a real accessory_id (integer) provided, attach to pivot
            $accId = $acc['accessory_id'] ?? null;
            if ($accId && is_numeric($accId)) {
                $quotation->accessories()->attach((int) $accId, [
                    'quantity'    => $qty,
                    'unit_price'  => $rate,
                    'amount'      => $amt,
                    'type'        => $type ?? 'standard',
                    'description' => $acc['description'] ?? null,
                    'unit'        => $acc['unit'] ?? 'NOS',
                    'door_type'   => $acc['door_type']   ?? null,
                    'door_width'  => $acc['door_width']  ?? null,
                    'door_height' => $acc['door_height'] ?? null,
                ]);
            }
            // door/window rows without a master accessory_id add their amount to installation_amount
            if (in_array($type, ['door', 'custom']) && (!$accId || !is_numeric($accId))) {
                $installationTotal += $amt;
            }
        }

        if ($installationTotal > 0) {
            $quotation->update(['installation_amount' => $installationTotal]);
        }
    }

    /** Public entry point to recompute a quotation's totals (used after accessory changes). */
    public function recalculateTotals(Quotation $quotation): void
    {
        $this->recalculate($quotation);
    }

    private function recalculate(Quotation $quotation): void
    {
        $quotation->load('items', 'accessories');

        $panelSub   = $quotation->items->sum('amount');
        $accSub     = $quotation->accessories->sum(fn($a) => $a->pivot->amount ?? 0);
        $install    = (float) ($quotation->installation_amount ?? 0);
        $totalSqm   = $quotation->items->sum('total_sqm');

        $subtotal       = $panelSub + $accSub + $install;
        $discountAmt    = $subtotal * ($quotation->discount_pct / 100);
        $taxableAmt     = $subtotal - $discountAmt;
        $gstTotal       = $taxableAmt * 0.18;

        $isInter = (bool) $quotation->is_inter_state;
        $cgst = $isInter ? 0 : $gstTotal / 2;
        $sgst = $isInter ? 0 : $gstTotal / 2;
        $igst = $isInter ? $gstTotal : 0;

        $transport  = $quotation->transport_fixed ? (float)($quotation->transport_amount ?? 0) : 0;
        $rawTotal   = $taxableAmt + $gstTotal + $transport;
        $roundOff   = round($rawTotal) - $rawTotal;
        $grandTotal = $rawTotal + $roundOff;

        $advance    = $grandTotal * ($quotation->advance_pct / 100);
        $balance    = $grandTotal - $advance;

        $quotation->update([
            'panel_subtotal'      => $panelSub,
            'accessory_subtotal'  => $accSub,
            'installation_amount' => $install,
            'total_sqm'           => $totalSqm,
            'subtotal'            => $subtotal,
            'discount_amount'     => $discountAmt,
            'taxable_amount'      => $taxableAmt,
            'cgst_amount'         => $cgst,
            'sgst_amount'         => $sgst,
            'igst_amount'         => $igst,
            'tax_amount'          => $gstTotal,
            'round_off'           => $roundOff,
            'total_amount'        => $grandTotal,
            'advance_amount'      => $advance,
            'balance_amount'      => $balance,
        ]);
    }

    private function detectInterState(Company $company, Customer $customer): bool
    {
        // Use GSTIN first 2 digits as state code
        $companyState  = $this->stateFromGstin($company->gstin ?? '');
        $customerState = $customer->state_code ?? '';

        if (!$companyState || !$customerState) return false;
        return strtoupper($companyState) !== strtoupper($customerState);
    }

    private function stateFromGstin(string $gstin): string
    {
        // GSTIN format: 27XXXXX → first 2 digits = state code number
        // Map numeric to alpha
        $map = [
            '01'=>'JK','02'=>'HP','03'=>'PB','04'=>'CH','05'=>'UT','06'=>'HR','07'=>'DL',
            '08'=>'RJ','09'=>'UP','10'=>'BR','11'=>'SK','12'=>'AR','13'=>'NL','14'=>'MN',
            '15'=>'MZ','16'=>'TR','17'=>'ML','18'=>'AS','19'=>'WB','20'=>'JH','21'=>'OD',
            '22'=>'CG','23'=>'MP','24'=>'GJ','25'=>'DD','26'=>'DN','27'=>'MH','28'=>'AP',
            '29'=>'KA','30'=>'GA','31'=>'LD','32'=>'KL','33'=>'TN','34'=>'PY','35'=>'AN',
            '36'=>'TG','37'=>'AP','38'=>'LA',
        ];
        $num = substr($gstin, 0, 2);
        return $map[$num] ?? '';
    }

    private function generateQuotationNumber(int $companyId, string $prefix): string
    {
        // Financial year: April 1 start
        $today  = now();
        $fyYear = $today->month >= 4 ? $today->year : $today->year - 1;
        $fyStart = \Carbon\Carbon::create($fyYear, 4, 1);
        $fyEnd   = \Carbon\Carbon::create($fyYear + 1, 3, 31, 23, 59, 59);

        // Only look at root quotations (not revision suffixes like SCP-2026-001-v2)
        // Pattern: PREFIX-YYYY-NNN (exactly 3 parts when split on '-')
        $all = Quotation::where('company_id', $companyId)
            ->whereBetween('created_at', [$fyStart, $fyEnd])
            ->whereRaw("quotation_no REGEXP ?", ['^' . preg_quote($prefix, '/') . '-[0-9]{4}-[0-9]+$'])
            ->orderByDesc('id')
            ->value('quotation_no');

        $seq = 1;
        if ($all) {
            // Format is PREFIX-YYYY-NNN — last segment is always the sequence
            $parts   = explode('-', $all);
            $lastNum = (int) end($parts);
            if ($lastNum > 0) $seq = $lastNum + 1;
        }

        return sprintf('%s-%d-%03d', $prefix, $fyYear + 1, $seq); // e.g. SCP-2026-001
    }

    // ── Public read methods ───────────────────────────────────────────────

    public function getDetails(Quotation $quotation): array
    {
        $quotation->load('items.sizes', 'items.panelType', 'accessories', 'customer', 'parent', 'revisions');

        return array_merge($quotation->toArray(), [
            'items' => $quotation->items->map(function ($item) {
                return array_merge($item->toArray(), [
                    'panel_type' => $item->panelType?->toArray(),
                    'sizes'      => $item->sizes->toArray(),
                ]);
            })->toArray(),
        ]);
    }

    public function list(int $companyId, array $filters = [])
    {
        $query = Quotation::where('company_id', $companyId)
            ->with('customer', 'items');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            // Quotations list never shows un-converted BOQs; the BOQ Register
            // explicitly passes status='boq' to fetch them.
            $query->where('status', '!=', 'boq');
        }
        if (!empty($filters['customer_id'])) $query->where('customer_id', $filters['customer_id']);
        if (!empty($filters['from_date']))   $query->whereDate('quoted_on', '>=', $filters['from_date']);
        if (!empty($filters['to_date']))     $query->whereDate('quoted_on', '<=', $filters['to_date']);
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('quotation_no', 'like', "%{$search}%")
                  ->orWhere('project_name', 'like', "%{$search}%")
                  ->orWhereHas('customer', fn($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $sortBy    = $filters['sort_by']    ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query;
    }

    public function getSuggestedRate(int $panelTypeId, array $rowData, string $qualityGrade, int $customerId): float
    {
        $panelType = PanelType::findOrFail($panelTypeId);
        $customer  = Customer::findOrFail($customerId);
        return $this->calculateRate($panelType, $rowData, $qualityGrade, $customer);
    }
}
