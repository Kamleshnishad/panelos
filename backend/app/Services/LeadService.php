<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Support\Carbon;

class LeadService
{
    public function list(int $companyId, array $filters = [])
    {
        $q = Lead::where('company_id', $companyId)->with('assignedUser', 'customer');

        if (!empty($filters['status']))      $q->where('status', $filters['status']);
        if (!empty($filters['source']))      $q->where('source', $filters['source']);
        if (!empty($filters['assigned_to'])) $q->where('assigned_to_user_id', $filters['assigned_to']);
        if (!empty($filters['follow_up']) && $filters['follow_up'] === 'due') {
            $q->whereNotNull('next_follow_up_date')
              ->whereDate('next_follow_up_date', '<=', Carbon::today())
              ->whereNotIn('status', ['won', 'lost']);
        }
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $q->where(fn ($w) => $w->where('contact_name', 'like', "%{$s}%")
                ->orWhere('company_name', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")
                ->orWhere('lead_no', 'like', "%{$s}%"));
        }

        return $q->orderByDesc('created_at');
    }

    public function getDetails(Lead $lead): Lead
    {
        return $lead->load('assignedUser', 'customer', 'quotation', 'activities.user');
    }

    public function addActivity(Lead $lead, array $data, ?int $userId = null): LeadActivity
    {
        return LeadActivity::create([
            'company_id'    => $lead->company_id,
            'lead_id'       => $lead->id,
            'user_id'       => $userId,
            'type'          => $data['type'] ?? 'note',
            'description'   => $data['description'] ?? null,
            'activity_date' => $data['activity_date'] ?? now(),
        ]);
    }

    public function create(int $companyId, array $data): Lead
    {
        $data['company_id'] = $companyId;
        $data['lead_no'] = $this->generateLeadNumber($companyId);
        $data['status'] = $data['status'] ?? 'new';
        return Lead::create($data);
    }

    public function update(Lead $lead, array $data): Lead
    {
        $lead->update($data);
        return $lead->fresh('assignedUser', 'customer');
    }

    public function changeStatus(Lead $lead, string $status, ?string $lostReason = null, ?int $userId = null): Lead
    {
        $from = $lead->status;
        $payload = ['status' => $status];
        if ($status === 'lost') $payload['lost_reason'] = $lostReason;
        $lead->update($payload);

        // Auto-log the status change as an activity
        LeadActivity::create([
            'company_id'    => $lead->company_id,
            'lead_id'       => $lead->id,
            'user_id'       => $userId,
            'type'          => 'status_change',
            'description'   => "Status: {$from} → {$status}" . ($lostReason ? " (reason: {$lostReason})" : ''),
            'activity_date' => now(),
        ]);

        return $lead->fresh('assignedUser', 'customer');
    }

    public function delete(Lead $lead): void
    {
        $lead->delete();
    }

    /**
     * Prepare a lead for quotation: ensure a linked customer exists (create one
     * from the lead's details if needed). Returns the customer id. The quotation
     * itself is created by the normal QuotationCreate flow, which back-links the
     * lead via lead_id (see QuotationService::create).
     */
    public function ensureCustomer(Lead $lead): int
    {
        if ($lead->customer_id && Customer::where('company_id', $lead->company_id)->whereKey($lead->customer_id)->exists()) {
            return $lead->customer_id;
        }

        $name = $lead->company_name ?: $lead->contact_name;
        // Mirror CustomerController::store exactly so all NOT-NULL columns are set.
        $customer = Customer::create([
            'company_id'          => $lead->company_id,
            'name'                => $name,
            'code'                => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 4)) . rand(100, 999),
            'type'                => 'retail',
            'contact_person'      => $lead->contact_name,
            'email'               => $lead->email,
            'phone'               => $lead->phone ?: '',
            'whatsapp_no'         => null,
            'gstin'               => null,
            'address_line1'       => '',
            'city'                => $lead->city ?: '',
            'state'               => '',
            'state_code'          => '',
            'pincode'             => '',
            'country'             => 'India',
            'credit_limit'        => 0,
            'outstanding_balance' => 0,
            'payment_terms_days'  => 30,
            'is_active'           => true,
        ]);

        $lead->update(['customer_id' => $customer->id]);

        LeadActivity::create([
            'company_id'    => $lead->company_id,
            'lead_id'       => $lead->id,
            'user_id'       => auth()?->id(),
            'type'          => 'status_change',
            'description'   => "Customer created/linked: {$customer->name}",
            'activity_date' => now(),
        ]);

        return $customer->id;
    }

    /** Called from QuotationService after a quotation is made for a lead. */
    public function linkQuotation(int $leadId, int $companyId, int $quotationId, int $customerId): void
    {
        $lead = Lead::where('company_id', $companyId)->find($leadId);
        if (!$lead) return;
        $lead->update(['status' => 'quoted', 'quotation_id' => $quotationId, 'customer_id' => $customerId]);
        LeadActivity::create([
            'company_id'    => $companyId,
            'lead_id'       => $lead->id,
            'user_id'       => auth()?->id(),
            'type'          => 'status_change',
            'description'   => 'Converted to quotation',
            'activity_date' => now(),
        ]);
    }

    /** Count of leads whose follow-up is due/overdue (for the nav badge). */
    public function followUpDueCount(int $companyId): int
    {
        return Lead::where('company_id', $companyId)
            ->whereNotNull('next_follow_up_date')
            ->whereDate('next_follow_up_date', '<=', Carbon::today())
            ->whereNotIn('status', ['won', 'lost'])
            ->count();
    }

    private function generateLeadNumber(int $companyId): string
    {
        $year = now()->format('Y');
        $last = Lead::withTrashed()->where('company_id', $companyId)->orderByDesc('id')->value('lead_no');
        $seq = 1;
        if ($last) { $parts = explode('-', $last); $seq = ((int) end($parts)) + 1; }
        return sprintf('LEAD-%s-%04d', $year, $seq);
    }
}
