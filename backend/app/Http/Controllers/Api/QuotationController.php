<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\Customer;
use App\Services\CreditService;
use App\Services\OrderService;
use App\Services\QuotationPdfService;
use App\Services\QuotationService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuotationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private QuotationService    $quotationService,
        private QuotationPdfService $pdfService,
        private OrderService        $orderService,
        private CreditService       $creditService,
    ) {}

    public function index(Request $request)
    {
        try {
            $filters  = $request->only(['status','customer_id','from_date','to_date','search','sort_by','sort_order']);
            $perPage  = $request->query('per_page', 20);
            $page     = $request->query('page', 1);
            $paginated = $this->quotationService->list($request->user()->company_id, $filters)
                ->paginate($perPage, ['*'], 'page', $page);

            return $this->paginatedResponse($paginated->items(), $paginated, 'Quotations retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Failed to list', 'LIST_ERROR', 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $companyId = $request->user()->company_id;
            $validated = $request->validate($this->quotationRules($companyId));

            $validated['company_id'] = $companyId;
            $quotation = $this->quotationService->create($validated);

            return $this->createdResponse($quotation, 'Quotation created', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'CREATE_ERROR', 500);
        }
    }

    public function show(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->successResponse($this->quotationService->getDetails($quotation), 'Quotation retrieved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Quotation not found', 'NOT_FOUND', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Failed', 'SHOW_ERROR', 500);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $companyId = $request->user()->company_id;
            $quotation = Quotation::where('company_id', $companyId)->findOrFail($id);
            $validated = $request->validate($this->quotationRules($companyId, update: true));

            $quotation = $this->quotationService->update($quotation, $validated);
            return $this->successResponse($quotation, 'Quotation updated');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'UPDATE_ERROR', 400);
        }
    }

    public function destroy(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
            $this->quotationService->delete($quotation);
            return $this->noContentResponse('Quotation deleted');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'DELETE_ERROR', 400);
        }
    }

    public function send(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->successResponse($this->quotationService->send($quotation), 'Quotation sent');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'SEND_ERROR', 400);
        }
    }

    /** Convert a BOQ into a priced draft quotation (sales then enters rates). */
    public function convert(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->successResponse($this->quotationService->convertToQuotation($quotation), 'BOQ converted to quotation');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'CONVERT_ERROR', 400);
        }
    }

    /** Inline rate entry from the detail page (rates-pending workflow). */
    public function saveRates(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)
                ->with('items.sizes')->findOrFail($id);
            $data = $request->validate([
                'rates'                => 'required|array|min:1',
                'rates.*.id'           => 'required|integer',
                'rates.*.rate_per_sqm' => 'required|numeric|min:0',
            ]);
            return $this->successResponse($this->quotationService->updateSizeRates($quotation, $data['rates']), 'Rates saved');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'RATES_ERROR', 400);
        }
    }

    public function accept(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->successResponse($this->quotationService->accept($quotation), 'Quotation accepted');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'ACCEPT_ERROR', 400);
        }
    }

    public function reject(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->successResponse($this->quotationService->reject($quotation), 'Quotation rejected');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'REJECT_ERROR', 400);
        }
    }

    public function revise(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
            $newVersion = $this->quotationService->revise($quotation);
            return $this->createdResponse($newVersion, 'Revision created', 201);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'REVISE_ERROR', 400);
        }
    }

    public function createOrder(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
            if ($quotation->status !== 'accepted') {
                return $this->errorResponse([], 'Quotation must be accepted first', 'INVALID_STATUS', 400);
            }

            // Credit-limit guard. Admins may override; non-admins are always blocked.
            $customer = Customer::where('company_id', $request->user()->company_id)
                ->find($quotation->customer_id);
            if ($customer) {
                $credit   = $this->creditService->status($customer, (float) $quotation->total_amount);
                $override = $request->boolean('override_credit_limit') && $request->user()->isAdmin();
                if (!$credit['within_limit'] && !$override) {
                    return $this->errorResponse(
                        $credit,
                        "Credit limit exceeded for {$customer->name}. Outstanding ₹" . number_format($credit['outstanding'], 2)
                            . " + this order ₹" . number_format($credit['new_order'], 2)
                            . " would exceed the ₹" . number_format($credit['credit_limit'], 2)
                            . " limit by ₹" . number_format($credit['over_by'], 2) . '.',
                        'CREDIT_LIMIT_EXCEEDED',
                        422
                    );
                }
            }

            $order = $this->orderService->createFromQuotation($quotation);
            return $this->createdResponse($order, 'Order created', 201);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Failed to create order', 'ORDER_ERROR', 500);
        }
    }

    public function downloadPdf(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->pdfService->generate($quotation);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'PDF failed', 'PDF_ERROR', 500);
        }
    }

    /** Worker copy — BOQ cutting sheet only (no rates). */
    public function downloadBoqSheet(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->pdfService->boqSheet($quotation, $request->boolean('download'));
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'BOQ sheet failed', 'BOQ_PDF_ERROR', 500);
        }
    }

    public function getSuggestedRate(Request $request)
    {
        try {
            $validated = $request->validate([
                'panel_type_id'      => 'required|exists:panel_types,id',
                'customer_id'        => 'required|exists:customers,id',
                'quality_grade'      => 'nullable|in:High,Medium,Standard',
                'thickness'          => 'nullable|integer',
                'density_type'       => 'nullable|in:PUF,PIR,Rockwool,EPS,Glasswool',
                'top_skin_thickness' => 'nullable|numeric',
                'top_surface'        => 'nullable|in:RIBBED,PLAIN',
            ]);

            $rate = $this->quotationService->getSuggestedRate(
                $validated['panel_type_id'],
                $validated,
                $validated['quality_grade'] ?? 'High',
                $validated['customer_id']
            );

            return response()->json(['success' => true, 'data' => ['rate' => $rate]]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function duplicate(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
            $copy = $this->quotationService->duplicate($quotation);
            return $this->createdResponse($copy, 'Quotation duplicated as new draft', 201);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'DUPLICATE_ERROR', 400);
        }
    }

    public function expire(Request $request, int $id)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->successResponse($this->quotationService->expire($quotation), 'Quotation expired');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'EXPIRE_ERROR', 400);
        }
    }

    // ── Shared validation rules ───────────────────────────────────────────

    private function quotationRules(int $companyId, bool $update = false): array
    {
        $req = $update ? 'sometimes|required' : 'required';

        return [
            'customer_id'      => [...explode('|', $req), Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'project_name'     => 'nullable|string|max:255',
            'project_location' => 'nullable|string|max:255',
            'quality_grade'    => 'nullable|in:High,Medium,Standard',
            'validity_days'    => 'nullable|integer|min:1|max:365',
            'quoted_on'        => 'nullable|date',
            'discount_pct'     => 'nullable|numeric|min:0|max:100',
            'transport_fixed'  => 'nullable|boolean',
            'transport_amount' => 'nullable|numeric|min:0',
            'advance_pct'      => 'nullable|numeric|min:0|max:100',
            'notes'            => 'nullable|string|max:2000',
            'quotation_prefix' => 'nullable|string|max:10',
            'as_boq'           => 'nullable|boolean',
            'lead_id'          => 'nullable|integer',
            // Panel rows
            'panel_rows'                            => ($update ? 'sometimes|' : '') . 'required|array|min:1',
            'panel_rows.*.panel_type_id'            => 'required|integer|exists:panel_types,id',
            'panel_rows.*.application'               => 'nullable|in:Wall,Roof,Cold Room,Partition,Clean Room,Ceiling,PEB Shade,Architectural',
            'panel_rows.*.thickness'                => 'required|integer|in:10,25,30,40,50,60,75,80,100,120,150,200',
            'panel_rows.*.density_type'             => 'required|in:PUF,PIR,Rockwool,EPS,Glasswool',
            'panel_rows.*.density_kgm3'             => 'required|numeric|min:10|max:200',
            'panel_rows.*.top_skin_material'        => 'required|in:PPGI,PPGL,SS304,GI,Aluminium',
            'panel_rows.*.top_skin_thickness'       => 'required|in:0.30,0.35,0.40,0.45,0.50,0.60',
            'panel_rows.*.top_color'                => 'nullable|string|max:50',
            'panel_rows.*.top_color_ral'            => 'nullable|string|max:20',
            'panel_rows.*.top_surface'              => 'required|in:RIBBED,PLAIN',
            'panel_rows.*.bottom_skin_material'     => 'required|in:PPGI,PPGL,SS304,GI,Aluminium',
            'panel_rows.*.bottom_skin_thickness'    => 'required|in:0.30,0.35,0.40,0.45,0.50,0.60',
            'panel_rows.*.bottom_color'             => 'nullable|string|max:50',
            'panel_rows.*.bottom_color_ral'         => 'nullable|string|max:20',
            'panel_rows.*.bottom_surface'           => 'nullable|in:RIBBED,PLAIN',
            'panel_rows.*.guard_film'               => 'nullable|boolean',
            'panel_rows.*.cello_tap'                => 'nullable|boolean',
            'panel_rows.*.fixing_system'            => 'nullable|in:Cam-Lock,Secret-Fix,Standing-Seam,Lap-Joint,Visible-Fix',
            'panel_rows.*.hsn_code'                 => 'nullable|string|max:20',
            'panel_rows.*.sizes'                    => 'required|array|min:1',
            'panel_rows.*.sizes.*.length_mm'        => 'required|integer|min:500|max:14000',
            'panel_rows.*.sizes.*.nos'              => 'required|integer|min:1',
            'panel_rows.*.sizes.*.rate_per_sqm'     => 'nullable|numeric|min:0',
            // Accessories
            'accessories'                           => 'nullable|array',
            'accessories.*.type'                    => 'nullable|in:standard,door,installation,custom',
            'accessories.*.accessory_id'            => 'nullable',
            'accessories.*.qty'                     => 'nullable|numeric|min:0',
            'accessories.*.rate'                    => 'nullable|numeric|min:0',
        ];
    }
}
