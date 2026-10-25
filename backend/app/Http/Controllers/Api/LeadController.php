<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Exceptions\LeadCustomerMatchException;
use App\Rules\IndianGstin;
use App\Rules\IndianPincode;
use App\Services\LeadMatcher;
use App\Services\LeadService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    use ApiResponse;

    public function __construct(private LeadService $leads, private \App\Services\LeadListService $lists) {}

    private function cid(Request $r) { return $r->user()->company_id; }

    public function index(Request $r)
    {
        $cid = $this->cid($r);
        $filters = $r->only(['status', 'source', 'assigned_to', 'follow_up', 'search', 'sort', 'dir']);
        $page    = (int) $r->input('page', 1);
        $perPage = (int) $r->input('per_page', 50);

        $paginated = $this->leads->list($cid, $filters, $r->user())->paginate($perPage, ['*'], 'page', $page);

        // Per-status counts for the tabs — unfiltered, server-side (cheap groupBy). "All" leaves the junk (invalid) out; it has its own tab.
        $byStatus = $this->leads->visible($cid, $r->user())
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $counts = ['' => (int) $byStatus->except('invalid')->sum()];
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

    /** What the lead form needs to fill its drop-downs: the company's active sources and lost reasons, and the statuses. */
    public function options(Request $r)
    {
        $cid = $this->cid($r);
        $reasons = collect($this->lists->all('reason', $cid))->where('is_active', true)->map(fn ($x) => ['id' => $x['id'], 'name' => $x['name']])->values();

        return $this->successResponse([
            'sources'      => $this->lists->activeNames('source', $cid),
            'lost_reasons' => $reasons,
            'statuses'     => Lead::STATUSES,
            'own_only'     => $r->user()->ownLeadsOnly(),      // the screen hides the filters that cannot apply to a restricted person
            'transitions'  => Lead::transitions(),     // what each status may move to: the screen offers exactly these
        ], 'Lead options');
    }

    /** A lead this person may open: any of the company's, or only their own when their role is restricted (a lead of someone else is simply not found). */
    private function mine(Request $r, int $id): Lead
    {
        return $this->leads->visible($this->cid($r), $r->user())->findOrFail($id);
    }

    public function dashboard(Request $r)
    {
        return $this->successResponse($this->leads->dashboard($this->cid($r), $r->user()), 'Lead dashboard');
    }

    public function show(Request $r, int $id)
    {
        try {
            $lead = $this->mine($r, $id);
            return $this->successResponse($this->leads->getDetails($lead), 'Lead retrieved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);        // someone else's lead, another company's, or none: always the same plain answer
        } catch (\Exception $e) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);
        }
    }

    /** The raw message(s) this lead arrived as, for support. Kept out of the lead itself on purpose. */
    public function payloads(Request $r, int $id)
    {
        try {
            $lead = $this->mine($r, $id);
            return $this->successResponse($lead->payloads()->get(['id', 'source', 'external_id', 'payload', 'received_at']), 'Original messages retrieved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);        // someone else's lead, another company's, or none: always the same plain answer
        } catch (\Exception $e) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);
        }
    }

    /** The matches, unless the caller has already seen them and sent allow_duplicate. */
    private function duplicatesToWarnAbout(Request $r, array $data, ?int $exceptId = null): array
    {
        if ($r->boolean('allow_duplicate')) {
            return [];
        }

        return $this->leads->findDuplicates($this->cid($r), $data, $exceptId);
    }

    private function duplicateResponse(array $dupes)
    {
        $first = $dupes[0];
        $msg = "This looks like {$first['lead_no']} ({$first['contact_name']}). Open it, or save anyway.";

        return $this->errorResponse(['duplicates' => $dupes], $msg, 'DUPLICATE_LEAD', 409);
    }

    public function store(Request $r)
    {
        try {
            $this->normaliseSource($r);
            $data = $r->validate($this->rules(false, $this->cid($r), null));
            if ($dupes = $this->duplicatesToWarnAbout($r, $data)) {
                return $this->duplicateResponse($dupes);
            }
            // A restricted person would never see a lead made for nobody: it is theirs unless they chose someone else.
            if ($r->user()->ownLeadsOnly() && empty($data['assigned_to_user_id'])) {
                $data['assigned_to_user_id'] = $r->user()->id;
            }
            return $this->createdResponse($this->leads->create($this->cid($r), $data), 'Lead created', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        }
    }

    public function update(Request $r, int $id)
    {
        try {
            $lead = $this->mine($r, $id);
            $this->normaliseSource($r, $lead->source);
            $data = $r->validate($this->rules(true, $this->cid($r), $lead->source));
            if ($r->has('status') && $r->input('status') !== $lead->status) {
                throw ValidationException::withMessages(['status' => 'Change the status with the status action, so it is checked and written to the timeline.']);
            }
            // Only when the phone or e-mail was actually changed to something that belongs to another lead; editing other fields never nags.
            $contact = ['phone' => array_key_exists('phone', $data) ? $data['phone'] : $lead->phone, 'email' => array_key_exists('email', $data) ? $data['email'] : $lead->email];
            $changed = LeadMatcher::phoneKey($contact['phone']) !== $lead->phone_key || LeadMatcher::emailKey($contact['email']) !== $lead->email_key;
            if ($changed && ($dupes = $this->duplicatesToWarnAbout($r, $contact, $lead->id))) {
                return $this->duplicateResponse($dupes);
            }
            return $this->successResponse($this->leads->update($lead, $data), 'Lead updated');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);        // someone else's lead, another company's, or none: always the same plain answer
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'UPDATE_ERROR', 400);
        }
    }

    public function changeStatus(Request $r, int $id)
    {
        try {
            $lead = $this->mine($r, $id);
            $data = $r->validate([
                'status'         => ['required', Rule::in(Lead::STATUSES)],
                'reason'         => 'nullable|string|max:500',
                'hold_until'     => 'nullable|date',
                'lost_reason'    => 'nullable|string|max:255',
                'lost_reason_id' => 'nullable|integer',
                'lost_note'      => 'nullable|string|max:1000',
            ]);

            // A reason picked from the company's list wins: its name is stored (so reports group on it) with the id beside it.
            // Free text still works (older callers); then it is the reason itself, as before.
            $reason = $data['lost_reason'] ?? null;
            $reasonId = null;
            $note = $data['lost_note'] ?? null;
            if (!empty($data['lost_reason_id'])) {
                $picked = $this->lists->activeReason($this->cid($r), (int) $data['lost_reason_id']);
                if (!$picked) {
                    throw ValidationException::withMessages(['lost_reason_id' => 'Choose one of the lost reasons in the list.']);
                }
                $reasonId = $picked['id'];
                $note = $note ?: (($reason && $reason !== $picked['name']) ? $reason : null);
                $reason = $picked['name'];
            }

            return $this->successResponse($this->leads->transition($lead, $data['status'], [
                'lost_reason' => $reason, 'lost_reason_id' => $reasonId, 'lost_note' => $note,
                'reason' => $data['reason'] ?? null, 'hold_until' => $data['hold_until'] ?? null,
            ], $r->user()->id), 'Status updated');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);        // someone else's lead, another company's, or none: always the same plain answer
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'STATUS_ERROR', 400);
        }
    }

    public function addActivity(Request $r, int $id)
    {
        try {
            $lead = $this->mine($r, $id);
            $data = $r->validate([
                'type'        => 'required|in:note,call,email,whatsapp,meeting',
                'description' => 'nullable|string|max:2000',
                'activity_date' => 'nullable|date',
            ]);
            $this->leads->addActivity($lead, $data, $r->user()->id);
            return $this->successResponse($this->leads->getDetails($lead), 'Activity added');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);        // someone else's lead, another company's, or none: always the same plain answer
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'ACTIVITY_ERROR', 400);
        }
    }

    /** Ensure a customer exists for the lead; returns ids to open a prefilled quotation. */
    public function convert(Request $r, int $id)
    {
        try {
            $lead = $this->mine($r, $id);
            $o = $r->validate([
                'customer_id'   => 'nullable|integer',
                'create_new'    => 'nullable|boolean',
                'state_code'    => 'nullable|string|max:40',
                'gstin'         => ['nullable', 'string', 'size:15', new IndianGstin()],
                'address_line1' => 'nullable|string|max:255',
                'pincode'       => ['nullable', 'string', 'max:10', new IndianPincode()],
            ]);
            $customerId = $this->leads->ensureCustomer($lead, $o);
            return $this->successResponse(['lead_id' => $lead->id, 'customer_id' => $customerId], 'Customer ready');
        } catch (LeadCustomerMatchException $e) {
            return $this->errorResponse(['customers' => $e->matches], $e->getMessage(), 'CUSTOMER_EXISTS', 409);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), collect($e->errors())->flatten()->first() ?: 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);        // someone else's lead, another company's, or none: always the same plain answer
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'CONVERT_ERROR', 400);
        }
    }

    public function destroy(Request $r, int $id)
    {
        try {
            $lead = $this->mine($r, $id);
            $this->leads->delete($lead);
            return $this->noContentResponse('Lead deleted');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Lead not found', 'NOT_FOUND', 404);        // someone else's lead, another company's, or none: always the same plain answer
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'DELETE_ERROR', 400);
        }
    }

    /** The source is one of the company's own list (Settings): match it case-insensitively and use the company's spelling. */
    private function normaliseSource(Request $r, ?string $current = null): void
    {
        $source = $r->input('source');
        if (!is_string($source) || $source === '') return;

        $cid = $this->cid($r);
        if ($current !== null && strcasecmp($source, $current) === 0) {
            $r->merge(['source' => $current]);          // unchanged: allowed even if the source has been switched off since
            return;
        }
        if ($match = $this->lists->resolveSource($cid, $source)) {
            $r->merge(['source' => $match]);
        }
    }

    private function rules(bool $update = false, ?int $companyId = null, ?string $currentSource = null): array
    {
        $sources = $companyId ? $this->lists->activeNames('source', $companyId) : [];
        if ($currentSource !== null) $sources[] = $currentSource;
        $req = $update ? 'sometimes|required' : 'required';
        return [
            'contact_name'        => [...explode('|', $req), 'string', 'max:150'],
            'company_name'        => 'nullable|string|max:200',
            'allow_duplicate'     => 'nullable|boolean',
            'phone'               => 'nullable|string|max:30',
            'email'               => 'nullable|email|max:150',
            'city'                => 'nullable|string|max:120',
            'source'              => ['nullable', Rule::in($sources)],
            'requirement'         => 'nullable|string|max:2000',
            'application'         => 'nullable|string|max:40',
            'est_qty_sqm'         => 'nullable|numeric|min:0',
            'est_value'           => 'nullable|numeric|min:0',
            // A new lead starts in an open stage; every later change goes through the status action.
            'status'              => $update ? 'nullable|string' : ['nullable', Rule::in(['new', 'contacted', 'qualified'])],
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
