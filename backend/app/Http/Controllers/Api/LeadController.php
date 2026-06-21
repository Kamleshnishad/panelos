<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\LeadService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadService $leads) {}

    private function cid(Request $r) { return $r->user()->company_id; }

    public function index(Request $r)
    {
        $cid = $this->cid($r);
        $filters = $r->only(['status', 'source', 'assigned_to', 'follow_up', 'search']);
        $page    = (int) $r->input('page', 1);
        $perPage = (int) $r->input('per_page', 50);

        $paginated = $this->leads->list($cid, $filters)->paginate($perPage, ['*'], 'page', $page);

        // Per-status counts for the tabs — unfiltered, server-side (cheap groupBy)
        $byStatus = Lead::where('company_id', $cid)
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $counts = ['' => (int) $byStatus->sum()];
        foreach ($byStatus as $st => $c) $counts[(string) $st] = (int) $c;

        return response()->json([
            'success' => true,
            'data'    => $paginated->items(),
            'counts'  => $counts,
            'meta'    => ['pagination' => [
                'total'        => $paginated->total(),
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'last_page'    => $paginated->lastPage(),
            ]],
            'message' => 'Leads retrieved',
        ]);
    }

    public function dashboard(Request $r)
    {
        return $this->successResponse($this->leads->dashboard($this->cid($r)), 'Lead dashboard');
    }

    public function show(Request $r, int $id)
    {
        try {
            $lead = Lead::where('company_id', $this->cid($r))->findOrFail($id);
            return $this->successResponse($this->leads->getDetails($lead), 'Lead retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);
        }
    }

    public function store(Request $r)
    {
        try {
            $data = $r->validate($this->rules(false, $this->cid($r)));
            return $this->createdResponse($this->leads->create($this->cid($r), $data), 'Lead created', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        }
    }

    public function update(Request $r, int $id)
    {
        try {
            $lead = Lead::where('company_id', $this->cid($r))->findOrFail($id);
            $data = $r->validate($this->rules(true, $this->cid($r)));
            return $this->successResponse($this->leads->update($lead, $data), 'Lead updated');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'UPDATE_ERROR', 400);
        }
    }

    public function changeStatus(Request $r, int $id)
    {
        try {
            $lead = Lead::where('company_id', $this->cid($r))->findOrFail($id);
            $data = $r->validate([
                'status'      => 'required|in:new,contacted,qualified,quoted,won,lost',
                'lost_reason' => 'nullable|string|max:255',
            ]);
            return $this->successResponse($this->leads->changeStatus($lead, $data['status'], $data['lost_reason'] ?? null, $r->user()->id), 'Status updated');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'STATUS_ERROR', 400);
        }
    }

    public function addActivity(Request $r, int $id)
    {
        try {
            $lead = Lead::where('company_id', $this->cid($r))->findOrFail($id);
            $data = $r->validate([
                'type'        => 'required|in:note,call,email,whatsapp,meeting',
                'description' => 'nullable|string|max:2000',
                'activity_date' => 'nullable|date',
            ]);
            $this->leads->addActivity($lead, $data, $r->user()->id);
            return $this->successResponse($this->leads->getDetails($lead), 'Activity added');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'ACTIVITY_ERROR', 400);
        }
    }

    /** Ensure a customer exists for the lead; returns ids to open a prefilled quotation. */
    public function convert(Request $r, int $id)
    {
        try {
            $lead = Lead::where('company_id', $this->cid($r))->findOrFail($id);
            $customerId = $this->leads->ensureCustomer($lead);
            return $this->successResponse(['lead_id' => $lead->id, 'customer_id' => $customerId], 'Customer ready');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'CONVERT_ERROR', 400);
        }
    }

    public function destroy(Request $r, int $id)
    {
        try {
            $lead = Lead::where('company_id', $this->cid($r))->findOrFail($id);
            $this->leads->delete($lead);
            return $this->noContentResponse('Lead deleted');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'DELETE_ERROR', 400);
        }
    }

    private function rules(bool $update = false, ?int $companyId = null): array
    {
        $req = $update ? 'sometimes|required' : 'required';
        return [
            'contact_name'        => [...explode('|', $req), 'string', 'max:150'],
            'company_name'        => 'nullable|string|max:200',
            'phone'               => 'nullable|string|max:30',
            'email'               => 'nullable|email|max:150',
            'city'                => 'nullable|string|max:120',
            'source'              => 'nullable|in:Website,Phone,WhatsApp,Referral,IndiaMART,Justdial,Exhibition,Walk-in,Other',
            'requirement'         => 'nullable|string|max:2000',
            'application'         => 'nullable|string|max:40',
            'est_qty_sqm'         => 'nullable|numeric|min:0',
            'est_value'           => 'nullable|numeric|min:0',
            'status'              => 'nullable|in:new,contacted,qualified,quoted,won,lost',
            // Scope the assigned user to the caller's company so a tenant can't write
            // a foreign tenant's user id (information disclosure + cross-tenant UI poisoning).
            'assigned_to_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $companyId ? $q->where('company_id', $companyId) : $q),
            ],
            'next_follow_up_date' => 'nullable|date',
            'notes'               => 'nullable|string|max:2000',
        ];
    }
}
