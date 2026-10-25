<?php

namespace App\Services;

use App\Http\Controllers\Api\ExportController;
use App\Models\Invoice;
use App\Support\GstState;

/**
 * The GST sales register for a period (FR-010): what the accountant needs to prepare the sales return (GSTR-1) without retyping invoices.
 *
 *  - invoices: one line per invoice that still stands (not draft, not cancelled), dated in the period (both ends included), oldest first:
 *    number, date, customer, GSTIN, place of supply, B2B (a valid GSTIN on the customer) or B2C, invoice value, rate, taxable value, CGST / SGST / IGST.
 *  - by_rate: the totals for each GST rate, which is how the return is summarised.
 *  - hsn: the HSN summary: HSN code, description, unit, quantity, rate, taxable value and tax, from the invoice lines.
 *  - warnings: things the accountant should fix before filing (a GSTIN that is not valid, panel types with no HSN code, lines with no tax rate).
 * Amounts are read from the stored invoice and tax rows and never recomputed.
 */
class GstRegisterService
{
    private const GSTIN = '/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public function build(int $companyId, string $from, string $to): array
    {
        $invoices = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('invoice_date', [$from, $to])
            ->with('taxCalculation', 'items.panelType', 'order.customer', 'dispatch.batch.order.customer')
            ->orderBy('invoice_date')->orderBy('id')
            ->get();

        $rows = [];
        $byRate = [];
        $hsn = [];
        $badGstin = [];
        $noHsn = [];
        foreach ($invoices as $inv) {
            $tc = $inv->taxCalculation;
            $customer = $inv->order?->customer ?? $inv->dispatch?->batch?->order?->customer;
            $gstin = strtoupper(trim((string) ($customer?->gstin ?? '')));
            $valid = $gstin !== '' && preg_match(self::GSTIN, $gstin) === 1;
            if ($gstin !== '' && !$valid) {
                $badGstin[$customer->name . ' (' . $gstin . ')'] = true;
            }
            $cgst = (float) ($tc->cgst_amount ?? 0);
            $sgst = (float) ($tc->sgst_amount ?? 0);
            $igst = (float) ($tc->igst_amount ?? 0);
            $taxable = (float) ($tc->taxable_amount ?? $inv->subtotal);
            $rate = (float) ($tc->tax_rate ?? 0);
            $state = $customer ? GstState::forParty($customer) : null;

            $rows[] = [
                'invoice_id'      => $inv->id,
                'invoice_no'      => $inv->invoice_no,
                'invoice_date'    => $inv->invoice_date->format('Y-m-d'),
                'type'            => $valid ? 'B2B' : 'B2C',
                'customer_name'   => $customer?->name ?? 'Unknown',
                'customer_gstin'  => $valid ? $gstin : '',
                'place_of_supply' => $state ? $state . '-' . GstState::name($state) : '',
                'supply'          => $igst > 0 ? 'Inter-State' : 'Intra-State',
                'invoice_value'   => round($inv->getTotal(), 2),
                'tax_rate'        => $rate,
                'taxable_value'   => round($taxable, 2),
                'cgst'            => round($cgst, 2),
                'sgst'            => round($sgst, 2),
                'igst'            => round($igst, 2),
                'total_tax'       => round($cgst + $sgst + $igst, 2),
            ];

            $key = (string) $rate;
            $byRate[$key] ??= ['tax_rate' => $rate, 'invoices' => 0, 'taxable_value' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0];
            $byRate[$key]['invoices']++;
            $byRate[$key]['taxable_value'] += $taxable;
            $byRate[$key]['cgst'] += $cgst;
            $byRate[$key]['sgst'] += $sgst;
            $byRate[$key]['igst'] += $igst;

            foreach ($inv->items as $item) {
                $isPanel = $item->panel_type_id !== null;
                $code = trim((string) ($item->panelType?->hsn_code ?? ''));
                if ($isPanel && $code === '') {
                    $noHsn[$item->panelType?->name ?? 'a removed panel type'] = true;
                }
                $uqc = $isPanel ? 'SQM' : 'OTH';
                $itemRate = (float) $item->tax_rate;
                $k = ($code !== '' ? $code : 'NOT SET') . '|' . $uqc . '|' . $itemRate;
                $hsn[$k] ??= ['hsn' => $code !== '' ? $code : 'Not set', 'description' => $isPanel ? ($item->panelType?->name ?? 'Panel') : 'Accessories & other', 'uqc' => $uqc,
                    'quantity' => 0.0, 'tax_rate' => $itemRate, 'taxable_value' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0];
                $tax = (float) $item->tax_amount;
                $hsn[$k]['quantity'] += (float) $item->quantity;
                $hsn[$k]['taxable_value'] += (float) $item->amount;
                if ($igst > 0) {
                    $hsn[$k]['igst'] += $tax;           // the supply is inter-state: all of it is IGST
                } else {
                    $hsn[$k]['cgst'] += $tax / 2;
                    $hsn[$k]['sgst'] += $tax / 2;
                }
            }
        }

        $round = fn (array $r) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $r);
        $byRate = collect($byRate)->sortBy('tax_rate')->map($round)->values()->all();
        $hsn = collect($hsn)->sortBy([['hsn', 'asc'], ['tax_rate', 'asc']])->map(fn ($r) => $round($r) + ['total_tax' => round($r['cgst'] + $r['sgst'] + $r['igst'], 2)])->values()->all();

        $col = fn (string $c, ?string $type = null) => round(collect($rows)->when($type, fn ($c2) => $c2->where('type', $type))->sum($c), 2);
        $warnings = [];
        if ($badGstin) {
            $warnings[] = count($badGstin) . ' customer GSTIN(s) are not valid and those invoices are shown as B2C: ' . implode(', ', array_slice(array_keys($badGstin), 0, 5)) . (count($badGstin) > 5 ? '…' : '');
        }
        if ($noHsn) {
            $warnings[] = 'These panel types have no HSN code (Master Data → Panel types): ' . implode(', ', array_keys($noHsn)) . '.';
        }
        if (collect($rows)->contains(fn ($r) => $r['tax_rate'] <= 0 && $r['invoice_value'] > 0)) {
            $warnings[] = 'Some invoices carry no GST (no tax rate on them). Check them before filing.';
        }

        return [
            'period'   => ['from' => $from, 'to' => $to],
            'totals'   => [
                'invoices'      => count($rows),
                'b2b_invoices'  => collect($rows)->where('type', 'B2B')->count(),
                'b2c_invoices'  => collect($rows)->where('type', 'B2C')->count(),
                'invoice_value' => $col('invoice_value'),
                'taxable_value' => $col('taxable_value'),
                'cgst'          => $col('cgst'),
                'sgst'          => $col('sgst'),
                'igst'          => $col('igst'),
                'total_tax'     => $col('total_tax'),
                'b2b_taxable'   => $col('taxable_value', 'B2B'),
                'b2c_taxable'   => $col('taxable_value', 'B2C'),
            ],
            'by_rate'  => $byRate,
            'invoices' => $rows,
            'hsn'      => $hsn,
            'warnings' => $warnings,
        ];
    }

    /** One table of the register as CSV text that Excel opens correctly (UTF-8 mark, text formula-guarded). $part is 'invoices' or 'hsn'. */
    public function csv(array $data, string $part): string
    {
        $text = fn ($v) => ExportController::csvSafe($v);
        $num = fn ($v) => sprintf('%.2f', (float) $v);            // plain 1234.50 for a spreadsheet, not the Indian display format
        $lines = [];
        if ($part === 'hsn') {
            $lines[] = ['HSN', 'Description', 'UQC', 'Quantity', 'Rate %', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total Tax'];
            foreach ($data['hsn'] as $r) {
                $lines[] = [$text($r['hsn']), $text($r['description']), $r['uqc'], $num($r['quantity']), $num($r['tax_rate']), $num($r['taxable_value']), $num($r['cgst']), $num($r['sgst']), $num($r['igst']), $num($r['total_tax'])];
            }
            $lines[] = ['TOTAL', '', '', '', '', $num(collect($data['hsn'])->sum('taxable_value')), $num(collect($data['hsn'])->sum('cgst')), $num(collect($data['hsn'])->sum('sgst')), $num(collect($data['hsn'])->sum('igst')), $num(collect($data['hsn'])->sum('total_tax'))];
        } else {
            $lines[] = ['Invoice No', 'Invoice Date', 'Type', 'Customer', 'GSTIN', 'Place of Supply', 'Supply', 'Invoice Value', 'Rate %', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total Tax'];
            foreach ($data['invoices'] as $r) {
                $lines[] = [$text($r['invoice_no']), \Carbon\Carbon::parse($r['invoice_date'])->format('d-m-Y'), $r['type'], $text($r['customer_name']), $text($r['customer_gstin']), $text($r['place_of_supply']), $r['supply'],
                    $num($r['invoice_value']), $num($r['tax_rate']), $num($r['taxable_value']), $num($r['cgst']), $num($r['sgst']), $num($r['igst']), $num($r['total_tax'])];
            }
            $t = $data['totals'];
            $lines[] = ['TOTAL', '', '', '', '', '', '', $num($t['invoice_value']), '', $num($t['taxable_value']), $num($t['cgst']), $num($t['sgst']), $num($t['igst']), $num($t['total_tax'])];
        }

        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        foreach ($lines as $l) {
            fputcsv($h, $l, ',', '"', '');
        }
        rewind($h);
        $out = stream_get_contents($h);
        fclose($h);

        return $out;
    }
}
