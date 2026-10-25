<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public const FOLLOW_UPS = ['due', 'overdue', 'today', 'none'];
    public const SORTS = ['created', 'follow_up', 'name', 'updated'];

    /** The leads a person may see: all of the company's, or only the ones assigned to them when their role carries the "own leads only" restriction (5.1). */
    public function visible(int $companyId, ?User $viewer = null): Builder
    {
        $q = Lead::where('company_id', $companyId);

        return $viewer && $viewer->ownLeadsOnly() ? $q->where('assigned_to_user_id', $viewer->id) : $q;
    }

    /**
     * The list as the screen asks for it. Filters: status, source, assigned_to (an id, `me` or `none`), follow_up (due = today or before,
     * overdue = before today, today, none = no date; open leads only), search (name, company, e-mail, lead no, phone in any format), sort + dir.
     */
    public function list(int $companyId, array $filters = [], ?User $viewer = null)
    {
        $q = LeadEngagement::select($this->visible($companyId, $viewer))->with('assignedUser', 'customer');

        if (!empty($filters['status']))      $q->where('status', $filters['status']);
        else                                 $q->where('status', '!=', 'invalid');     // junk only shows when asked for
        if (!empty($filters['source']))      $q->where('source', $filters['source']);

        $to = $filters['assigned_to'] ?? null;
        if ($to === 'none')                  $q->whereNull('assigned_to_user_id');
        elseif ($to === 'me')                $q->where('assigned_to_user_id', $viewer?->id ?? 0);
        elseif (!empty($to))                 $q->where('assigned_to_user_id', $to);

        $today = Carbon::today();
        switch ($filters['follow_up'] ?? null) {
            case 'due':     $q->whereNotIn('status', Lead::CLOSED)->whereNotNull('next_follow_up_date')->whereDate('next_follow_up_date', '<=', $today); break;
            case 'overdue': $q->whereNotIn('status', Lead::CLOSED)->whereNotNull('next_follow_up_date')->whereDate('next_follow_up_date', '<', $today); break;
            case 'today':   $q->whereNotIn('status', Lead::CLOSED)->whereDate('next_follow_up_date', $today); break;
            case 'none':    $q->whereNotIn('status', Lead::CLOSED)->whereNull('next_follow_up_date'); break;
        }

        if (!empty($filters['quiet']))       LeadEngagement::onlyQuiet($q, $companyId);
        if (!empty($filters['unanswered']))  LeadEngagement::onlyUnanswered($q)->whereIn('leads.status', LeadEngagement::OPEN);

        if (!empty($filters['search'])) {
            $s = addcslashes(trim((string) $filters['search']), '\\%_');
            $digits = preg_replace('/\D+/', '', (string) $filters['search']);
            $q->where(function ($w) use ($s, $digits) {
                $w->where('contact_name', 'like', "%{$s}%")->orWhere('company_name', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%")->orWhere('lead_no', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%");
                if (strlen($digits) >= 4) {
                    $w->orWhere('phone_key', 'like', '%' . $digits . '%');           // "98765 43210" finds +91-9876543210
                }
            });
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'created';
        $dir = in_array($filters['dir'] ?? null, ['asc', 'desc'], true) ? $filters['dir'] : (in_array($sort, ['follow_up', 'name'], true) ? 'asc' : 'desc');
        match ($sort) {
            'follow_up' => $q->orderByRaw('next_follow_up_date is null')->orderBy('next_follow_up_date', $dir),    // leads with no date last, whichever way
            'name'      => $q->orderBy('contact_name', $dir),
            'updated'   => $q->orderBy('updated_at', $dir),
            default     => $q->orderBy('created_at', $dir),
        };

        return $q->orderByDesc('id');
    }

    public const BOARD_COLUMNS = ['new', 'contacted', 'qualified', 'quoted', 'on_hold'];
    public const BOARD_PER_COLUMN = 25;

    /**
     * The board (LEADS_PLAN 5.8): one column per open stage and one for leads on hold, each with how many leads, their estimated area and value, and the first
     * BOARD_PER_COLUMN of them (those with the nearest follow-up first). Takes the list's own filters (source, assigned_to, follow_up, search, quiet, unanswered; a
     * `status` is ignored: the columns are the statuses) and respects the viewer's visibility. Closed leads are not on the board: the list has them.
     * @return list<array{status:string,count:int,est_qty_sqm:float,est_value:float,more:int,leads:\Illuminate\Support\Collection}>
     */
    public function board(int $companyId, array $filters = [], ?User $viewer = null): array
    {
        $filters = array_diff_key($filters, ['status' => 1, 'sort' => 1, 'dir' => 1]) + ['sort' => 'follow_up'];
        $out = [];
        foreach (self::BOARD_COLUMNS as $status) {
            $q = $this->list($companyId, $filters + ['status' => $status], $viewer);
            $sums = (clone $q)->reorder()->toBase()->select(DB::raw('count(*) as n, coalesce(sum(est_qty_sqm),0) as sqm, coalesce(sum(est_value),0) as val'))->first();      // select() replaces the list's columns
            $n = (int) ($sums->n ?? 0);
            $out[] = [
                'status' => $status, 'count' => $n, 'est_qty_sqm' => (float) ($sums->sqm ?? 0), 'est_value' => (float) ($sums->val ?? 0),
                'more' => max(0, $n - self::BOARD_PER_COLUMN), 'leads' => (clone $q)->limit(self::BOARD_PER_COLUMN)->get(),
            ];
        }

        return $out;
    }

    public function getDetails(Lead $lead): Lead
    {
        LeadEngagement::attach($lead);

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
        $data['status'] = $data['status'] ?? 'new';

        // Two leads made at the same moment read the same "last number". The unique (company_id, lead_no) key makes the loser fail,
        // so it runs again, reads the new last number and takes the next one (same helper as quotations and invoices; LD-07).
        return \App\Support\DocNumber::retry(
            fn () => DB::transaction(function () use ($data, $companyId) {
                $lead = Lead::create($data + ['lead_no' => $this->generateLeadNumber($companyId)]);
                // Someone was chosen: record it and tell them. Nobody chosen: the company's assignment rules decide (none by default, 2.4).
                $assign = app(LeadAssignmentService::class);
                if (!empty($data['assigned_to_user_id'])) {
                    $assign->announce($lead, null, (int) $data['assigned_to_user_id'], 'by hand', auth()?->id());
                } else {
                    $assign->assignByRules($lead);
                }

                return $lead;
            }),
            5
        );
    }

    public function update(Lead $lead, array $data): Lead
    {
        $from = $lead->assigned_to_user_id;
        $lead->update($data);
        // Reassigning leaves a line in the timeline and tells the new person (and nobody else); see LeadAssignmentService.
        if (array_key_exists('assigned_to_user_id', $data)) {
            $to = $data['assigned_to_user_id'] ? (int) $data['assigned_to_user_id'] : null;
            $actor = auth()?->user();
            app(LeadAssignmentService::class)->announce($lead, $from ? (int) $from : null, $to, $actor ? "by {$actor->name}" : 'by hand', $actor?->id);
        }
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
            if (in_array($to, Lead::CLOSED, true)) {
                app(LeadTaskService::class)->cancelOpen($lead);        // a won, lost or invalid lead has nothing left to do (5.2)
            }
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
        app(LeadAttachmentService::class)->purge($lead);          // its files go with it (5.7)
        $lead->delete();
    }

    /**
     * Prepare a lead for quotation: ensure a linked customer exists (create one
     * from the lead's details if needed). Returns the customer id. The quotation
     * itself is created by the normal QuotationCreate flow, which back-links the
     * lead via lead_id (see QuotationService::create).
     */
    /**
     * Options (all optional): customer_id (use this existing customer), create_new (skip the look-alike check),
     * state_code / state (a code, alpha code or name), gstin, address_line1, pincode.
     *
     * A lead that is already linked returns its customer. Otherwise, unless customer_id or create_new is given, customers with the same
     * phone, e-mail, GSTIN or name are looked for and, if any exist, LeadCustomerMatchException lists them. A new customer needs a state,
     * which may come from the GSTIN, so the quotation's GST split is right.
     */
    public function ensureCustomer(Lead $lead, array $o = []): int
    {
        if ($lead->customer_id && Customer::where('company_id', $lead->company_id)->whereKey($lead->customer_id)->exists()) {
            return $lead->customer_id;
        }

        if (!empty($o['customer_id'])) {
            $customer = Customer::where('company_id', $lead->company_id)->find((int) $o['customer_id']);
            if (!$customer) {
                $this->refuse('customer_id', 'That customer was not found.');
            }
            $this->linkCustomer($lead, $customer, 'linked');

            return $customer->id;
        }

        $gstin = strtoupper(preg_replace('/\s+/', '', (string) ($o['gstin'] ?? ''))) ?: null;
        $matches = $this->customerMatches($lead, $gstin);
        if ($matches && empty($o['create_new'])) {
            throw new \App\Exceptions\LeadCustomerMatchException($matches);
        }
        if ($gstin && collect($matches)->contains(fn ($m) => in_array('gstin', $m['matched_on'], true))) {
            $this->refuse('gstin', 'A customer with this GSTIN already exists. Use that customer instead.');
        }

        $stateCode = \App\Support\GstState::normalize($o['state_code'] ?? $o['state'] ?? null);
        $gstinState = $gstin ? \App\Support\GstState::normalize($gstin) : null;
        if ($stateCode && $gstinState && $stateCode !== $gstinState) {
            $this->refuse('state_code', 'The GSTIN belongs to ' . \App\Support\GstState::name($gstinState) . ', not ' . \App\Support\GstState::name($stateCode) . '.');
        }
        $stateCode ??= $gstinState;
        if (!$stateCode) {
            $this->refuse('state_code', "Choose the customer's state (or enter the GSTIN), so GST is split correctly on the quotation.");
        }

        $name = $lead->company_name ?: $lead->contact_name;
        // Mirror CustomerController::store so all NOT-NULL columns are set. Retried once on a unique-code clash.
        $customer = \App\Support\DocNumber::retry(fn () => Customer::create([
            'company_id'          => $lead->company_id,
            'name'                => $name,
            'code'                => Customer::nextCode($lead->company_id, $name),
            'type'                => 'retail',
            'contact_person'      => $lead->contact_name,
            'email'               => $lead->email,
            'phone'               => $lead->phone ?: '',
            'whatsapp_no'         => null,
            'gstin'               => $gstin,
            'address_line1'       => $o['address_line1'] ?? '',
            'city'                => $lead->city ?: '',
            'state'               => \App\Support\GstState::name($stateCode),
            'state_code'          => $stateCode,
            'pincode'             => $o['pincode'] ?? '',
            'country'             => 'India',
            'credit_limit'        => 0,
            'outstanding_balance' => 0,
            'payment_terms_days'  => 30,
            'is_active'           => true,
        ]));

        $this->linkCustomer($lead, $customer, 'created/linked');

        return $customer->id;
    }

    private function linkCustomer(Lead $lead, Customer $customer, string $how): void
    {
        $lead->update(['customer_id' => $customer->id]);

        LeadActivity::create([
            'company_id'    => $lead->company_id,
            'lead_id'       => $lead->id,
            'user_id'       => auth()?->id(),
            'type'          => 'status_change',
            'description'   => "Customer {$how}: {$customer->name}",
            'activity_date' => now(),
        ]);
    }

    /**
     * Customers of this company that look like the lead's buyer: same phone (last 10 digits, whatever the formatting), e-mail, GSTIN, or name.
     * Name alone is a weak match, so these are only ever offered, never used automatically.
     *
     * @return list<array{id:int,code:string,name:string,phone:?string,email:?string,city:?string,gstin:?string,matched_on:list<string>}>
     */
    public function customerMatches(Lead $lead, ?string $gstin = null): array
    {
        $phoneKey = LeadMatcher::phoneKey($lead->phone);
        $emailKey = LeadMatcher::emailKey($lead->email);
        $names = array_values(array_unique(array_filter([mb_strtolower(trim((string) $lead->company_name)), mb_strtolower(trim((string) $lead->contact_name))])));

        $rows = Customer::withoutGlobalScope('tenant')->where('company_id', $lead->company_id)
            ->where(function ($q) use ($phoneKey, $emailKey, $gstin, $names) {
                if ($phoneKey) $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') LIKE ?", ['%' . $phoneKey]);
                if ($emailKey) $q->orWhereRaw('LOWER(TRIM(email)) = ?', [$emailKey]);
                if ($gstin)    $q->orWhereRaw('UPPER(gstin) = ?', [$gstin]);
                foreach ($names as $n) $q->orWhereRaw('LOWER(TRIM(name)) = ?', [$n]);
            })
            ->orderBy('id')->limit(10)->get();

        return $rows->map(function (Customer $c) use ($phoneKey, $emailKey, $gstin, $names) {
            $digits = preg_replace('/\D+/', '', (string) $c->phone);

            return [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'city' => $c->city, 'gstin' => $c->gstin,
                'matched_on' => array_values(array_filter([
                    $phoneKey && strlen($digits) >= 10 && str_ends_with($digits, $phoneKey) ? 'phone' : null,
                    $emailKey && mb_strtolower(trim((string) $c->email)) === $emailKey ? 'email' : null,
                    $gstin && strtoupper((string) $c->gstin) === $gstin ? 'gstin' : null,
                    in_array(mb_strtolower(trim($c->name)), $names, true) ? 'name' : null,
                ])),
            ];
        })->all();
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
    public function leadsForQuotation(int $companyId, int $quotationId)
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
    public function dashboard(int $companyId, ?User $viewer = null): array
    {
        $byStatus = $this->visible($companyId, $viewer)
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

        $real = fn () => $this->visible($companyId, $viewer)->where('status', '!=', 'invalid');

        $bySource = $real()
            ->selectRaw('source, count(*) as total, sum(case when status = "won" then 1 else 0 end) as won')
            ->groupBy('source')->orderByDesc('total')->get()
            ->map(fn ($r) => [
                'source'   => $r->source,
                'total'    => (int) $r->total,
                'won'      => (int) $r->won,
                'conv_pct' => $r->total > 0 ? round($r->won / $r->total * 100, 1) : 0,
            ]);

        $lostReasons = $this->visible($companyId, $viewer)->where('status', 'lost')
            ->whereNotNull('lost_reason')->where('lost_reason', '!=', '')
            ->selectRaw('lost_reason, count(*) as c')->groupBy('lost_reason')
            ->orderByDesc('c')->limit(5)->get()
            ->map(fn ($r) => ['reason' => $r->lost_reason, 'count' => (int) $r->c]);

        $today = Carbon::today();
        $openFollow = $this->visible($companyId, $viewer)->whereNotIn('status', Lead::CLOSED)->whereNotNull('next_follow_up_date');
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
            'response'       => LeadEngagement::summary($this->visible($companyId, $viewer), $companyId),
        ];
    }

    /** LEAD-<year>-<n>. The year is the year it was made; <n> keeps counting across years (decided in 1.6: existing numbers stay as they are). */
    protected function generateLeadNumber(int $companyId): string
    {
        $year = now()->format('Y');
        $last = Lead::withTrashed()->where('company_id', $companyId)->orderByDesc('id')->value('lead_no');
        $seq = 1;
        if ($last) { $parts = explode('-', $last); $seq = ((int) end($parts)) + 1; }
        return sprintf('LEAD-%s-%04d', $year, $seq);
    }
}
