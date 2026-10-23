<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Quotation;

/**
 * Turns a quotation into ready-to-print blocks for the PDF templates that use it.
 * The template then only lays things out — the wording, grouping and rounding live
 * here, so several templates cannot drift apart. Reads fields that exist today;
 * anything not stored yet (legal name, delivery address…) is simply absent and
 * the template hides it.
 */
class QuotationDocumentService
{
    /** Mirrors QuotationService::recalculate(), which hard-codes 18%. */
    private const GST_RATE = 0.18;
    private const DEFAULT_ACCENT = '#2B50E0';
    private const INSTALLATION_SAC = '994568';
    private const DEFAULT_ACCESSORY_HSN = '73089090';
    private const DEFAULT_DOOR_HSN = '73083000';

    public function build(Quotation $q): array
    {
        $q->loadMissing('items.sizes', 'items.panelType', 'accessories', 'lines', 'customer', 'company');
        $company = $q->company;
        $customer = $q->customer;

        $lines = $this->lines($q, $company);
        $legalName = trim((string) $company->legal_name) !== '' ? $company->legal_name : $company->name;
        $title = $company->quotation_title === 'QUOTATION' ? 'QUOTATION' : 'PROFORMA INVOICE';
        $documentWord = $title === 'QUOTATION' ? 'quotation' : 'Proforma Invoice';
        $totalWords = 'Rupees ' . InvoiceService::amountInWords((float) $q->total_amount) . ' Only';
        $advancePct = (float) $q->advance_pct;
        $inter = (bool) $q->is_inter_state;

        return [
            'accent' => $this->accent($company->primary_color ?? null),
            'title'  => $title,
            'title_note' => "This is a {$documentWord} and not a valid tax document.",
            'company' => [
                'name'    => $company->name,
                'legal_name' => $legalName,
                'certification' => trim((string) $company->certification_line) ?: null,
                'logo'    => $company->image_data_uri,
                'address' => $this->address([$company->address_line1, $company->city, $company->state], $company->pincode),
                'phone'   => $company->phone,
                'email'   => $company->email,
                'gstin'   => $company->gstin,
                'pan'     => $company->pan,
            ],
            'meta' => [
                'no'            => $q->quotation_no,
                'revision'      => (int) $q->revision_number,
                'date'          => $q->quoted_on?->format('d/m/Y'),
                'valid_until'   => $q->valid_until?->format('d/m/Y'),
                'validity_days' => $q->validity_days,
                'status'        => strtoupper((string) $q->status),
                'inter_state'   => $inter,
                'gst_type'      => $inter ? 'Inter-state · IGST' : 'Intra-state · CGST + SGST',
                'supply_to'     => $this->placeOfSupply($customer),
            ],
            'buyer' => [
                'name'    => $customer->name,
                'address' => trim(implode(', ', array_filter([$customer->address_line1, $customer->address_line2]))),
                'locality' => $this->address([$customer->city, $customer->state], $customer->pincode),
                'gstin'   => $customer->gstin,
                'pan'     => $customer->pan,
                'contact' => $customer->contact_person,
                'phone'   => $customer->phone,
                'whatsapp' => $customer->whatsapp_no,
                'email'   => $customer->email,
            ],
            'project' => [
                'name'     => $q->project_name,
                'location' => $q->project_location,
                'delivery_address' => trim((string) $q->delivery_address) ?: null,
                'site_contact' => trim(implode(' · ', array_filter([$q->site_contact_name, $q->site_contact_phone]))) ?: null,
                'quality'  => $q->quality_grade,
                'prepared_by' => trim(implode(' · ', array_filter([$company->authorized_signatory, $company->signatory_phone]))),
            ],
            'lines'  => $lines,
            'totals' => [
                'panel'        => (float) $q->panel_subtotal,
                'accessories'  => (float) $q->accessory_subtotal,
                'installation' => (float) $q->installation_amount,
                'subtotal'     => (float) $q->subtotal,
                'discount_pct' => (float) $q->discount_pct,
                'discount'     => (float) $q->discount_amount,
                'taxable'      => (float) $q->taxable_amount,
                'cgst'         => (float) $q->cgst_amount,
                'sgst'         => (float) $q->sgst_amount,
                'igst'         => (float) $q->igst_amount,
                'transport'    => ($q->transport_fixed && $q->transport_amount > 0) ? (float) $q->transport_amount : null,
                'round_off'    => (float) $q->round_off,
                'total'        => (float) $q->total_amount,
                'advance_pct'  => $advancePct,
                'advance'      => (float) $q->advance_amount,
                'balance'      => (float) $q->balance_amount,
                'total_sqm'    => (float) $q->total_sqm,
            ],
            'words'   => $totalWords,
            'hsn'     => $this->hsnSummary($lines, (float) $q->discount_pct, $inter, $q),
            'bank'    => [
                'account_name' => $legalName,
                'bank'         => $company->bank_name,
                'branch'       => $company->bank_branch,
                'account_no'   => $company->bank_account_no,
                'ifsc'         => $company->bank_ifsc,
            ],
            'terms'      => $this->terms($q, $company, $legalName, $documentWord, $this->customOr($company->quotation_terms, config('quotation_terms.terms', []), fn ($t) => isset($t['title'], $t['text']))),
            'exclusions' => array_map(fn ($t) => $this->resolve($t, $q, $company, $legalName, $documentWord),
                $this->customOr($company->quotation_exclusions, config('quotation_terms.exclusions', []), 'is_string')),
            'notes'      => trim((string) $q->notes) ?: null,
            'signatory'  => [
                'name'  => $company->authorized_signatory,
                'phone' => $company->signatory_phone,
            ],
        ];
    }

    /** Only a plain 6-digit hex colour ever reaches the stylesheet. */
    private function accent(?string $colour): string
    {
        return ($colour && preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) ? $colour : self::DEFAULT_ACCENT;
    }

    private function address(array $parts, ?string $pincode): string
    {
        return trim(implode(', ', array_filter($parts)) . ($pincode ? ' ' . $pincode : ''));
    }

    private function placeOfSupply($customer): ?string
    {
        if (!$customer->state && !$customer->state_code) return null;
        return trim($customer->state . ($customer->state_code ? " ({$customer->state_code})" : ''));
    }

    /** Numbered lines in the order a reader expects: panels, accessories and doors, then installation. */
    private function lines(Quotation $q, Company $company): array
    {
        $lines = [];
        $sr = 0;

        foreach ($q->items as $item) {
            $title = 'SUPPLY OF ' . strtoupper($company->name) . ' MAKE '
                . (in_array($item->density_type, ['PUF', 'PIR']) ? 'CONTINUOUS LINE ' : '')
                . strtoupper((string) $item->density_type) . ' PANEL'
                . ($item->application ? ' — ' . strtoupper($item->application) : '')
                . ' ' . $item->thickness . 'MM THICK.';

            $finish = array_filter([$item->guard_film ? 'Guard film' : null, $item->cello_tap ? 'Cello tape' : null]);

            $lines[] = [
                'sr' => ++$sr, 'kind' => 'panel', 'title' => $title,
                'subtitle' => strtoupper((string) $item->panelType?->name),
                'specs' => array_filter([
                    'Core'        => trim($item->density_type . ' · ' . (float) $item->density_kgm3 . ' (±2) kg/m³'),
                    'Fixing'      => $item->fixing_system,
                    'Top skin'    => $this->skin($item, 'top'),
                    'Bottom skin' => $this->skin($item, 'bottom'),
                    'Finish'      => $finish ? implode(' · ', $finish) : null,
                ]),
                'sizes' => $item->sizes->map(fn ($s) => [
                    'length' => $s->length_mm, 'width' => $s->width_mm, 'nos' => $s->nos,
                    'sqm' => (float) $s->sqm, 'rate' => (float) $s->rate_per_sqm, 'amount' => (float) $s->amount,
                    'dl' => $s->length_mm < 2000,   // produced at double length, cut to size
                ])->all(),
                'hsn' => $item->hsn_code, 'qty' => (float) $item->total_sqm, 'uom' => 'SQM',
                'rate' => (float) $item->rate_per_sqm, 'amount' => (float) $item->amount,
            ];
        }

        // Accessories first, then doors (stable within each group).
        $ordered = $q->accessories->sortBy(fn ($a) => ($a->pivot->type ?? '') === 'door' ? 1 : 0)->values();

        foreach ($ordered as $acc) {
            $p = $acc->pivot;
            $isDoor = ($p->type ?? '') === 'door';
            $subtitle = null;
            if ($isDoor) {
                $size = ($p->door_width && $p->door_height) ? "{$p->door_width} × {$p->door_height} mm" : null;
                $subtitle = trim(implode(' · ', array_filter([
                    $p->door_type ? ucfirst(str_replace('_', ' ', $p->door_type)) : null, $size,
                ])));
            }
            $desc = trim((string) ($p->description ?: $acc->description));

            $lines[] = [
                'sr' => ++$sr, 'kind' => $isDoor ? 'door' : 'accessory',
                'title' => strtoupper($acc->name), 'subtitle' => $subtitle ?: null,
                // A description that only repeats the name adds nothing.
                'description' => ($desc !== '' && strcasecmp($desc, $acc->name) !== 0) ? $desc : null,
                'specs' => [], 'sizes' => [],
                'hsn' => $acc->hsn_code ?: self::DEFAULT_ACCESSORY_HSN,
                'qty' => (float) $p->quantity, 'uom' => $p->unit ?: ($acc->unit ?: 'NOS'),
                'rate' => (float) $p->unit_price, 'amount' => (float) $p->amount,
            ];
        }

        // Rows with no master accessory: custom items, doors typed into the form, then installation last.
        foreach (['custom', 'door', 'installation'] as $type) {
            foreach ($q->lines->where('type', $type) as $line) {
                $lines[] = $this->fromLine($line, ++$sr);
            }
        }

        // A quotation that has an installation amount but no rows (cannot happen after the backfill, but
        // never lose money from a printout) prints it as one lump line.
        if ($q->lines->isEmpty() && (float) $q->installation_amount > 0) {
            $lines[] = [
                'sr' => ++$sr, 'kind' => 'installation', 'title' => 'INSTALLATION / SITE WORK',
                'subtitle' => null, 'description' => null, 'specs' => [], 'sizes' => [],
                'hsn' => self::INSTALLATION_SAC, 'qty' => 1.0, 'uom' => 'LS',
                'rate' => (float) $q->installation_amount, 'amount' => (float) $q->installation_amount,
            ];
        }

        return $lines;
    }

    private function fromLine($line, int $sr): array
    {
        $door = $line->type === 'door';
        $desc = trim((string) $line->description);

        $subtitle = null;
        if ($door) {
            $size = ($line->door_width && $line->door_height) ? "{$line->door_width} × {$line->door_height} mm" : null;
            $subtitle = trim(implode(' · ', array_filter([
                $line->door_type ? ucfirst(str_replace('_', ' ', $line->door_type)) : null, $size,
            ]))) ?: null;
        }

        $title = match ($line->type) {
            'door'   => 'DOOR / WINDOW',
            'custom' => strtoupper($desc !== '' ? $desc : 'Custom item'),
            default  => strtoupper($desc !== '' ? $desc : 'Installation / site work'),
        };

        return [
            'sr' => $sr, 'kind' => $line->type, 'title' => $title, 'subtitle' => $subtitle,
            // For a door the description is the form's optional notes; elsewhere it is already the title.
            'description' => $door && $desc !== '' ? $desc : null,
            'specs' => [], 'sizes' => [],
            'hsn' => $line->hsn_code ?: ($door ? self::DEFAULT_DOOR_HSN : self::INSTALLATION_SAC),
            'qty' => (float) $line->quantity, 'uom' => $line->unit ?: ($door ? 'NOS' : 'LS'),
            'rate' => (float) $line->unit_price, 'amount' => (float) $line->amount,
        ];
    }

    private function skin($item, string $side): ?string
    {
        $thickness = $item->{$side . '_skin_thickness'};
        $material  = $item->{$side . '_skin_material'};
        if (!$material) return null;

        $ral = $item->{$side . '_color_ral'};
        $surface = $item->{$side . '_surface'};

        return implode(' · ', array_filter([
            number_format((float) $thickness, 2) . ' mm ' . $material,
            $item->{$side . '_color'},
            $ral ? "RAL {$ral}" : null,
            $surface ? ucfirst(strtolower($surface)) : null,
        ]));
    }

    /**
     * Tax by HSN / SAC. Each row is that code's share of the taxable value (after any
     * discount); the totals row is the quotation's own stored tax, which is authoritative.
     */
    private function hsnSummary(array $lines, float $discountPct, bool $inter, Quotation $q): array
    {
        $factor = 1 - ($discountPct / 100);
        $byHsn = [];
        foreach ($lines as $l) {
            $byHsn[$l['hsn']] = ($byHsn[$l['hsn']] ?? 0) + ($l['amount'] * $factor);
        }

        $rows = [];
        foreach ($byHsn as $hsn => $taxable) {
            $tax = $taxable * self::GST_RATE;
            $rows[] = [
                'hsn' => $hsn, 'taxable' => round($taxable, 2),
                'cgst' => $inter ? 0.0 : round($tax / 2, 2), 'sgst' => $inter ? 0.0 : round($tax / 2, 2),
                'igst' => $inter ? round($tax, 2) : 0.0, 'tax' => round($tax, 2),
            ];
        }

        return [
            'rows' => $rows,
            'total' => [
                'taxable' => (float) $q->taxable_amount, 'cgst' => (float) $q->cgst_amount,
                'sgst' => (float) $q->sgst_amount, 'igst' => (float) $q->igst_amount, 'tax' => (float) $q->tax_amount,
            ],
        ];
    }

    /**
     * Terms with their placeholders filled. Template text is escaped first and the
     * values are escaped as they are inserted, so the result is safe to print unescaped;
     * the filled-in values are bold.
     *
     * @return array<int, array{title:string, text:string}>
     */
    private function terms(Quotation $q, Company $company, string $legalName, string $documentWord, array $terms): array
    {
        return array_map(fn ($t) => ['title' => $t['title'], 'text' => $this->resolve($t['text'], $q, $company, $legalName, $documentWord)], $terms);
    }

    /** The company's own list when it has a usable one, otherwise the standard wording. */
    private function customOr($custom, array $default, $isValid): array
    {
        if (!is_array($custom) || !$custom) return $default;
        $valid = array_values(array_filter($custom, $isValid));
        return $valid ?: $default;
    }

    private function resolve(string $text, Quotation $q, Company $company, string $legalName, string $documentWord): string
    {
        $advancePct = (float) $q->advance_pct;
        $values = [
            '{company}'        => $legalName,
            '{document}'       => $documentWord,
            '{city}'           => $company->city,
            '{advance_pct}'    => rtrim(rtrim(number_format($advancePct, 2), '0'), '.') . '%',
            '{advance_amount}' => '₹ ' . number_format((float) $q->advance_amount, 2),
            '{balance_pct}'    => rtrim(rtrim(number_format(100 - $advancePct, 2), '0'), '.') . '%',
            '{balance_amount}' => '₹ ' . number_format((float) $q->balance_amount, 2),
            '{validity_days}'  => $q->validity_days . ' days',
            '{quoted_on}'      => $q->quoted_on?->format('d/m/Y'),
            '{valid_until}'    => $q->valid_until?->format('d/m/Y'),
        ];

        $out = e($text);
        foreach ($values as $token => $value) {
            $out = str_replace($token, '<b>' . e(trim((string) $value) !== '' ? (string) $value : '—') . '</b>', $out);
        }
        return $out;
    }
}
