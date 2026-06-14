<?php

namespace App\Services;

use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;

class QuotationPdfService
{
    public function __construct(private DocumentTemplateService $templates) {}

    /** Resolve the chosen template view (defaults to the current blade). */
    private function view(Quotation $quotation, string $docType, string $fallback, ?string $override = null): string
    {
        if ($override) return $override;
        return $this->templates->viewFor($quotation->company_id, $docType, $fallback);
    }

    /**
     * Generate quotation PDF. $template optionally forces a specific view (preview).
     */
    public function generate(Quotation $quotation, ?string $template = null): \Illuminate\Http\Response
    {
        $quotation->load('items.sizes', 'items.panelType', 'accessories', 'customer', 'company');

        $pdf = Pdf::loadView($this->view($quotation, 'quotation', 'quotations.pdf', $template), [
            'quotation' => $quotation,
        ]);

        return $pdf->download("quotation-{$quotation->quotation_no}.pdf");
    }

    public function stream(Quotation $quotation, ?string $template = null): \Illuminate\Http\Response
    {
        $quotation->load('items.sizes', 'items.panelType', 'accessories', 'customer', 'company');

        $pdf = Pdf::loadView($this->view($quotation, 'quotation', 'quotations.pdf', $template), [
            'quotation' => $quotation,
        ]);

        return $pdf->stream();
    }

    /**
     * Worker copy — BOQ cutting sheet only (no rates/amounts).
     */
    public function boqSheet(Quotation $quotation, bool $download = false, ?string $template = null): \Illuminate\Http\Response
    {
        $quotation->load('items.sizes', 'items.panelType', 'accessories', 'customer', 'company');

        $pdf = Pdf::loadView($this->view($quotation, 'boq', 'quotations.boq_sheet', $template), [
            'quotation' => $quotation,
            'company'   => $quotation->company,
        ])->setPaper('a4', 'portrait');

        $name = "boq-cutting-sheet-{$quotation->quotation_no}.pdf";
        return $download ? $pdf->download($name) : $pdf->stream($name);
    }
}
