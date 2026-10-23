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

        $view = $this->view($quotation, 'quotation', 'quotations.pdf', $template);
        $pdf = Pdf::loadView($view, [
            'quotation' => $quotation,
        ]);
        $this->stampPageNumbers($pdf, 'quotation', $view);

        return $pdf->download("quotation-{$quotation->quotation_no}.pdf");
    }

    public function stream(Quotation $quotation, ?string $template = null): \Illuminate\Http\Response
    {
        $quotation->load('items.sizes', 'items.panelType', 'accessories', 'customer', 'company');

        $view = $this->view($quotation, 'quotation', 'quotations.pdf', $template);
        $pdf = Pdf::loadView($view, [
            'quotation' => $quotation,
        ]);
        $this->stampPageNumbers($pdf, 'quotation', $view);

        return $pdf->stream();
    }

    /**
     * "Page x of y" for templates that opt in (registry `page_numbers`). The total is only known
     * once the document has been laid out, so it is drawn on every page after rendering. Does
     * nothing for any other template, so their output is untouched.
     */
    private function stampPageNumbers($pdf, string $docType, string $view): void
    {
        if (!$this->templates->wantsPageNumbers($docType, $view)) return;

        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');
        $size = 8.5;
        $width = $metrics->getTextWidth('Page 00 of 00', $font, $size);

        $canvas->page_text(
            ($canvas->get_width() - $width) / 2, $canvas->get_height() - 25.4,
            'Page {PAGE_NUM} of {PAGE_COUNT}', $font, $size, [0.349, 0.38, 0.435],
        );
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
