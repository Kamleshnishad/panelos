<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\LeadCustomerMatchException;
use App\Http\Controllers\Controller;
use App\Rules\IndianGstin;
use App\Rules\IndianPincode;
use App\Services\LeadRequirementService;
use App\Services\LeadService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What a lead wants, and "Quote this lead" (LEADS_PLAN 5.4). Under the leads module (reading leads.view, changing leads.manage). Making a quotation or a BOQ
 * also needs quotations.manage, the permission the quotation screen itself asks for, so this is not a way round it. A person restricted to their own leads
 * (leads.own_only) reaches only those. The quotation and BOQ code is only called, never changed.
 */
class LeadRequirementController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadRequirementService $requirement, private LeadService $leads)
    {
    }

    private function lead(Request $r, int $id)
    {
        return $this->leads->visible((int) $r->user()->company_id, $r->user())->findOrFail($id);
    }

    private function body(LeadRequirementService $s, $lead): array
    {
        return ['lines' => $s->lines($lead), 'applications' => LeadRequirementService::APPLICATIONS, 'thicknesses' => LeadRequirementService::THICKNESSES, 'standard_length_mm' => LeadRequirementService::STANDARD_LENGTH_MM];
    }

    public function show(Request $r, int $id)
    {
        try {
            return $this->successResponse($this->body($this->requirement, $this->lead($r, $id)), 'Requirement');
        } catch (ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);
        }
    }

    public function save(Request $r, int $id)
    {
        try {
            $d = $r->validate(['lines' => 'present|array|max:' . LeadRequirementService::MAX_LINES, 'lines.*' => 'array']);
            $lead = $this->lead($r, $id);
            $this->requirement->save($lead, $d['lines'], $r->user());

            return $this->successResponse($this->body($this->requirement, $lead->fresh()) + ['est_qty_sqm' => $lead->fresh()->est_qty_sqm], 'Requirement saved');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);
        }
    }

    /** Make the quotation draft (or BOQ) from the requirement. Accepts the same customer fields as "Convert", and `as_boq`. */
    public function quote(Request $r, int $id)
    {
        try {
            if (!$r->user()->hasPermission('quotations.manage')) {
                return $this->errorResponse([], 'You need permission to create quotations to do this.', 'FORBIDDEN', 403);
            }
            $o = $r->validate([
                'as_boq' => 'nullable|boolean', 'customer_id' => 'nullable|integer', 'create_new' => 'nullable|boolean', 'state_code' => 'nullable|string|max:40',
                'gstin' => ['nullable', 'string', 'size:15', new IndianGstin()], 'address_line1' => 'nullable|string|max:255', 'pincode' => ['nullable', 'string', 'max:10', new IndianPincode()],
            ]);

            return $this->createdResponse($this->requirement->quote($this->lead($r, $id), $r->user(), $o), 'Created from the requirement', 201);
        } catch (LeadCustomerMatchException $e) {
            return $this->errorResponse(['customers' => $e->matches], $e->getMessage(), 'CUSTOMER_EXISTS', 409);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);
        }
    }
}
