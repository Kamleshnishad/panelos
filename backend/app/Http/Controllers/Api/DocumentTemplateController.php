<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Services\DocumentTemplateService;
use App\Services\InvoiceService;
use App\Services\QuotationPdfService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DocumentTemplateController extends Controller
{
    use ApiResponse;

    public function __construct(
        private DocumentTemplateService $svc,
        private QuotationPdfService $quotationPdf,
        private InvoiceService $invoiceService,
    ) {}

    public function index(Request $r)
    {
        return $this->successResponse($this->svc->listForCompany($r->user()->company_id), 'Templates retrieved');
    }

    public function update(Request $r)
    {
        try {
            $data = $r->validate([
                'doc_type'     => 'required|in:quotation,boq,invoice',
                'template_key' => 'required|string|max:50',
            ]);
            $this->svc->setTemplate($r->user()->company_id, $data['doc_type'], $data['template_key']);
            return $this->successResponse($this->svc->listForCompany($r->user()->company_id), 'Template applied');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'APPLY_ERROR', 400);
        }
    }

    /** Stream a PDF rendered with a specific template (for preview before apply). */
    public function preview(Request $r)
    {
        try {
            $cid = $r->user()->company_id;
            $docType = $r->query('doc_type');
            $key = $r->query('template');
            $id = $r->query('id');

            $meta = $this->svc->templatesFor($docType)[$key] ?? null;
            if (!$meta) {
                return $this->errorResponse([], 'Unknown template', 'NOT_FOUND', 404);
            }
            $view = $meta['view'];

            if ($docType === 'quotation' || $docType === 'boq') {
                $q = $id
                    ? Quotation::where('company_id', $cid)->find($id)
                    : Quotation::where('company_id', $cid)->latest('id')->first();
                if (!$q) {
                    return $this->errorResponse([], 'Create a quotation first to preview this template.', 'NO_DATA', 404);
                }
                return $docType === 'boq'
                    ? $this->quotationPdf->boqSheet($q, false, $view)
                    : $this->quotationPdf->stream($q, $view);
            }

            // invoice
            $inv = $id
                ? Invoice::where('company_id', $cid)->find($id)
                : Invoice::where('company_id', $cid)->latest('id')->first();
            if (!$inv) {
                return $this->errorResponse([], 'Create an invoice first to preview this template.', 'NO_DATA', 404);
            }
            $html = $this->invoiceService->generatePdf($inv->id, $cid, $view);
            $pdf = app('dompdf.wrapper');
            $pdf->loadHTML($html);
            $pdf->setPaper('A4');
            return $pdf->stream('preview.pdf');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'PREVIEW_ERROR', 400);
        }
    }
}
