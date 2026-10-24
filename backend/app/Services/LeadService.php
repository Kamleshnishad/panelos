<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function list(int $companyId, array $filters = [])
    {
        $q = Lead::where('company_id', $companyId)->with('assignedUser', 'customer');

        if (!empty($filters['status']))      $q->where('status', $filters['status']);
        else                                 $q->where('status', '!=', 'invalid');     // junk only shows when asked for
        if (!empty($filters['source']))      $q->where('source', $filters['source']);
        if (!empty($filters['assigned_to'])) $q->where('assigned_to_user_id', $filters['assigned_to']);
        if (!empty($filters['follow_up']) && $filters['follow_up'] === 'due') {
            $q->whereNotNull('next_follow_up_date')
              ->whereDate('next_follow_up_date', '<=', Carbon::today())
              ->whereNotIn('status', Lead::CLOSED);
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

    /** Leads that look like the same buyer (same phone or e-mail). On update, pass the lead's own id so it never matches itself. */
    public function findDuplicates(int $companyId, array $data, ?int $exceptLeadId = null): array
    {
        return app(LeadMatcher::class)->matches($companyId, $data['phone'] ?? null, $data['email'] ?? null, $exceptLeadId);
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

    /** Kept for existing callers; every change of status goes through transition(). */
    public function changeStatus(Lead $lead, string $status, ?string $lostReason = null, ?int $userId = null, ?int $lostReasonId = null, ?string $lostNote = null): Lead
    {
        return $this->transition($lead, $status, [
            'lost_reason' => $lostReason, 'lost_reason_id' => $lostReasonId, 'lost_note' => $lostNote,
        ], $userId);
    }

    /**
     * The only way a lead's status changes (LEADS_PLAN 1.2): the move must be in Lead::allowedNext(), reopening a closed lead
     * needs a reason, putting a lead on hold needs the date it comes back, and every change writes a timeline entry.
     *
     * Options: lost_reason, lost_reason_id, lost_note (for lost) · reason (note for invalid, required to reopen) · hold_until (on hold)
     */
    public function transition(Lead $lead, string $to, array $o = [], ?int $userId = null): Lead
    {
        $from = $lead->status;
        if ($to === $from) {
            return $lead->fresh('assignedUser', 'customer');       // nothing to change, nothing to log
        }
        if (!in_array($to, Lead::STATUSES, true)) {
            $this->refuse('status', 'That is not a lead status.');
        }
        if (!in_array($to, Lead::allowedNext($from), true)) {
            $this->refuse('status', "A lead cannot go from {$this->label($from)} to {$this->label($to)}." . ($lead->isClosed() ? ' Reopen it first.' : ''));
        }

        $reason = trim((string) ($o['reason'] ?? ''));
        $payload = ['status' => $to];
        $suffix = '';

        if ($lead->isClosed()) {                                      // a reopen: closed -> open
            if ($reason === '') {
                $this->refuse('reason', 'Say why you are reopening this lead.');
            }
            $payload += ['lost_reason' => null, 'lost_reason_id' => null, 'lost_note' => null];
            $suffix = " (reopened: {$reason})";
        } elseif ($to === 'on_hold') {
            $until = $this->holdDate($o['hold_until'] ?? null);
            $payload['next_follow_up_date'] = $until->toDateString();  // it comes back on that date: the follow-up badge picks it up
            $suffix = " (until {$until->toDateString()}" . ($reason !== '' ? ", {$reason}" : '') . ')';
        } elseif ($to === 'invalid' || $to === 'won') {
            $suffix = $reason !== '' ? " ({$reason})" : '';
        } elseif ($to === 'lost') {
            $lostReason = $o['lost_reason'] ?? null;
            $lostNote = $o['lost_note'] ?? null;
            $payload += ['lost_reason' => $lostReason, 'lost_reason_id' => $o['lost_reason_id'] ?? null, 'lost_note' => $lostNote];
            $suffix = $lostReason ? " (reason: {$lostReason}" . ($lostNote ? " — {$lostNote}" : '') . ')' : '';
        }

        DB::transaction(function () use ($lead, $payload, $from, $to, $suffix, $userId) {
            $lead->update($payload);
            LeadActivity::create([
                'company_id'    => $lead->company_id,
                'lead_id'       => $lead->id,
                'user_id'       => $userId,
                'type'          => 'status_change',
                'description'   => "Status: {$this->label($from)} → {$this->label($to)}{$suffix}",
                'activity_date' => now(),
            ]);
        });

        return $lead->fresh('assignedUser', 'customer');
    }

    private function holdDate($value): Carbon
    {
        try {
            $date = trim((string) $value) === '' ? null : Carbon::parse((string) $value)->startOfDay();   // parse('') would be "now"
        } catch (\Throwable) {
            $date = null;
        }
        if (!$date || $date->lt(Carbon::today())) {
            $this->refuse('hold_until', 'Choose the date this lead comes back (today or later).');
        }

        return $date;
    }

    private function label(string $status): string
    {
        return str_replace('_', ' ', $status);
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
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

    /**
     * Called from QuotationService after a quotation is made for a lead.
     * An open or on-hold lead moves to quoted and remembers the quotation. A won, lost or invalid lead keeps its status and its
     * original quotation (it was closed on purpose): the new quotation is only noted on the timeline. Reopen it first to work it again.
     */
    public function linkQuotation(int $leadId, int $companyId, int $quotationId, int $customerId): void
    {
        $lead = Lead::where('company_id', $companyId)->find($leadId);
        if (!$lead) return;

        $number = \App\Models\Quotation::withoutGlobalScope('tenant')->where('company_id', $companyId)->whereKey($quotationId)->value('quotation_no') ?? "#{$quotationId}";

        if ($lead->isClosed()) {
            if (!$lead->customer_id) $lead->update(['customer_id' => $customerId]);
            LeadActivity::create([
                'company_id' => $companyId, 'lead_id' => $lead->id, 'user_id' => auth()?->id(), 'type' => 'note',
                'description' => "Quotation {$number} was made for this lead. It stays {$this->label($lead->status)}; reopen it to work it again.",
                'activity_date' => now(),
            ]);

            return;
        }

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

    /**
     * "Won" means an order was created from the lead's quotation (LEADS_PLAN 1.3). Called by OrderService after the order exists.
     * The lead is found through the quotation and its revisions. An open or on-hold lead becomes won and the timeline says which order;
     * a lead that is already won is left alone (so a repeat does nothing); a lost or invalid lead is never reopened, it only gets a note.
     */
    public function markWonFromOrder(\App\Models\Order $order, ?int $userId = null): void
    {
        foreach ($this->leadsForQuotation((int) $order->company_id, (int) $order->quotation_id) as $lead) {
            $this->winFromOrder($lead, $order->order_no, $userId ?? auth()?->id());
        }
    }

    private function winFromOrder(Lead $lead, string $orderNo, ?int $userId, string $how = ''): bool
    {
        if ($lead->status === 'won') {
            return false;
        }
        if ($lead->isClosed()) {
            LeadActivity::create([
                'company_id' => $lead->company_id, 'lead_id' => $lead->id, 'user_id' => $userId, 'type' => 'note',
                'description' => "Order {$orderNo} was created from this lead's quotation. It stays {$this->label($lead->status)}; reopen it to mark it won.",
                'activity_date' => now(),
            ]);

            return false;
        }
        $this->transition($lead, 'won', ['reason' => "order {$orderNo} created{$how}"], $userId);

        return true;
    }

    /** @return \Illuminate\Support\Collection<int,Lead> leads whose quotation is this one or any earlier version of it */
    private function leadsForQuotation(int $companyId, int $quotationId)
    {
        $ids = [];
        for ($id = $quotationId, $guard = 0; $id && !in_array($id, $ids, true) && $guard < 25; $guard++) {
            $ids[] = $id;
            $id = (int) \App\Models\Quotation::withoutGlobalScope('tenant')->where('company_id', $companyId)->whereKey($id)->value('parent_quotation_id');
        }

        return Lead::withoutGlobalScope('tenant')->where('company_id', $companyId)->whereIn('quotation_id', $ids)->get();
    }

    /**
     * One-off catch-up for leads whose order was created before this rule existed. Dry run by default: returns what it would change and
     * changes nothing until $apply is true.
     *
     * @return list<array{lead_no:string,lead_id:int,status:string,order_no:string,action:string}>
     */
    public function backfillWon(?int $companyId = null, bool $apply = false): array
    {
        $leads = Lead::withoutGlobalScope('tenant')->whereNotNull('quotation_id')->where('status', '!=', 'won')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))->orderBy('id')->get();

        $rows = [];
        foreach ($leads as $lead) {
            $orderNo = $this->orderNoForLead($lead);
            if (!$orderNo) continue;
            $closed = $lead->isClosed();
            $rows[] = ['lead_no' => $lead->lead_no, 'lead_id' => $lead->id, 'status' => $lead->status, 'order_no' => $orderNo,
                       'action' => $closed ? 'left alone (closed: reopen by hand if it should be won)' : 'would become won'];
            if ($apply && !$closed) {
                $this->winFromOrder($lead, $orderNo, null, ' (backfill)');
            }
        }

        return $rows;
    }

    private function orderNoForLead(Lead $lead): ?string
    {
        $ids = [];
        for ($id = (int) $lead->quotation_id; $id && !in_array($id, $ids, true) && count($ids) < 25;) {
            $ids[] = $id;
            $id = (int) \App\Models\Quotation::withoutGlobalScope('tenant')->where('company_id', $lead->company_id)->whereKey($id)->value('parent_quotation_id');
        }

        // revisions of the lead's quotation carry the order, so look down the chain as well as up
        $down = $ids;
        for ($i = 0; $i < 25; $i++) {
            $next = \App\Models\Quotation::withoutGlobalScope('tenant')->where('company_id', $lead->company_id)->whereIn('parent_quotation_id', $down)->pluck('id')->diff($ids)->all();
            if (!$next) break;
            $ids = array_merge($ids, $next);
            $down = $next;
        }

        return \App\Models\Order::withoutGlobalScope('tenant')->where('company_id', $lead->company_id)->whereIn('quotation_id', $ids)->orderBy('id')->value('order_no');
    }

    /** Count of leads whose follow-up is due/overdue (for the nav badge). */
    public function followUpDueCount(int $companyId): int
    {
        return Lead::where('company_id', $companyId)
            ->whereNotNull('next_follow_up_date')
            ->whereDate('next_follow_up_date', '<=', Carbon::today())
            ->whereNotIn('status', Lead::CLOSED)
            ->count();
    }

    /** Funnel + source conversion + follow-up + lost-reason summary. Invalid (junk) leads are counted on their own and left out of everything else. */
    public function dashboard(int $companyId): array
    {
        $byStatus = Lead::where('company_id', $companyId)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->toArray();

        $funnelOut = [];
        foreach (['new', 'contacted', 'qualified', 'quoted', 'won', 'lost'] as $s) {
            $funnelOut[] = ['status' => $s, 'count' => (int) ($byStatus[$s] ?? 0)];
        }
        $won = (int) ($byStatus['won'] ?? 0);
        $lost = (int) ($byStatus['lost'] ?? 0);
        $invalid = (int) ($byStatus['invalid'] ?? 0);
        $onHold = (int) ($byStatus['on_hold'] ?? 0);
        $total = (int) array_sum($byStatus) - $invalid;
        $closed = $won + $lost;

        $real = fn () => Lead::where('company_id', $companyId)->where('status', '!=', 'invalid');

        $bySource = $real()
            ->selectRaw('source, count(*) as total, sum(case when status = "won" then 1 else 0 end) as won')
            ->groupBy('source')->orderByDesc('total')->get()
            ->map(fn ($r) => [
                'source'   => $r->source,
                'total'    => (int) $r->total,
                'won'      => (int) $r->won,
                'conv_pct' => $r->total > 0 ? round($r->won / $r->total * 100, 1) : 0,
            ]);

        $lostReasons = Lead::where('company_id', $companyId)->where('status', 'lost')
            ->whereNotNull('lost_reason')->where('lost_reason', '!=', '')
            ->selectRaw('lost_reason, count(*) as c')->groupBy('lost_reason')
            ->orderByDesc('c')->limit(5)->get()
            ->map(fn ($r) => ['reason' => $r->lost_reason, 'count' => (int) $r->c]);

        $today = Carbon::today();
        $openFollow = Lead::where('company_id', $companyId)->whereNotIn('status', Lead::CLOSED)->whereNotNull('next_follow_up_date');
        $overdue = (clone $openFollow)->whereDate('next_follow_up_date', '<', $today)->count();
        $dueToday = (clone $openFollow)->whereDate('next_follow_up_date', $today)->count();
        $thisWeek = (clone $openFollow)->whereDate('next_follow_up_date', '>', $today)
            ->whereDate('next_follow_up_date', '<=', $today->copy()->addDays(7))->count();

        return [
            'total'          => $total,
            'open'           => $total - $closed,
            'won'            => $won,
            'lost'           => $lost,
            'on_hold'        => $onHold,
            'invalid'        => $invalid,
            'win_rate'       => $closed > 0 ? round($won / $closed * 100, 1) : 0,
            'funnel'         => $funnelOut,
            'by_source'      => $bySource,
            'lost_reasons'   => $lostReasons,
            'follow_ups'     => ['overdue' => $overdue, 'today' => $dueToday, 'this_week' => $thisWeek],
        ];
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
