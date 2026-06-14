<?php

namespace App\Services;

use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;

class QuotationPdfService
{
    /**
     * Generate quotation PDF
     */
    public function generate(Quotation $quotation): \Illuminate\Http\Response
    {
        $quotation->load('items.sizes', 'items.panelType', 'accessories', 'customer', 'company');

        $pdf = Pdf::loadView('quotations.pdf', [
            'quotation' => $quotation,
        ]);

        return $pdf->download("quotation-{$quotation->quotation_no}.pdf");
    }

    public function stream(Quotation $quotation): \Illuminate\Http\Response
    {
        $quotation->load('items.sizes', 'items.panelType', 'accessories', 'customer', 'company');

        $pdf = Pdf::loadView('quotations.pdf', [
            'quotation' => $quotation,
        ]);

        return $pdf->stream();
    }

    /**
     * Worker copy — BOQ cutting sheet only (no rates/amounts).
     */
    public function boqSheet(Quotation $quotation, bool $download = false): \Illuminate\Http\Response
    {
        $quotation->load('items.sizes', 'items.panelType', 'accessories', 'customer', 'company');

        $pdf = Pdf::loadView('quotations.boq_sheet', [
            'quotation' => $quotation,
            'company'   => $quotation->company,
        ])->setPaper('a4', 'portrait');

        $name = "boq-cutting-sheet-{$quotation->quotation_no}.pdf";
        return $download ? $pdf->download($name) : $pdf->stream($name);
    }
}
