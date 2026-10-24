<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadPayload;
use App\Services\LeadIntakeResult as R;
use App\Services\LeadIntakeService;
use App\Services\LeadService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** LEADS_PLAN 2.2: the one function every channel calls. */
class LeadIntakeTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private LeadIntakeService $intake;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->company = Company::factory()->create();
        $this->intake = app(LeadIntakeService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enquiry(array $over = []): array
    {
        return $over + [
            'contact_name' => 'Ravi Patel', 'company_name' => 'Zephyr Cold Storage', 'phone' => '+91 98765 43210', 'email' => 'Ravi@Zephyr.test', 'city' => 'Rajkot',
            'requirement' => 'Need 500 sqm of 50mm PUF wall panel', 'external_id' => 'IM-1001', 'campaign' => 'Cold room', 'utm_source' => 'google',
            'first_seen_at' => '2026-11-09 18:30:00', 'est_qty_sqm' => '500', 'est_value' => '₹ 6,50,000',
        ];
    }

    private function log(Lead $l): array
    {
        return LeadActivity::where('lead_id', $l->id)->orderBy('id')->pluck('description')->all();
    }

    // ── a new enquiry ────────────────────────────────────────────────────────

    public function test_a_new_enquiry_becomes_a_lead_with_everything_mapped_and_the_raw_message_kept(): void
    {
        $raw = ['QUERY_ID' => 'IM-1001', 'SENDER' => 'Ravi'];

        $r = $this->intake->ingest($this->company->id, 'indiamart', $this->enquiry(), $raw);

        $this->assertSame(R::CREATED, $r->outcome);
        $l = Lead::find($r->leadId);
        $this->assertSame($r->leadNo, $l->lead_no);
        $this->assertSame('new', $l->status);
        $this->assertSame('IndiaMART', $l->source, 'The company\'s own spelling of the channel.');
        $this->assertSame(['Ravi Patel', 'Zephyr Cold Storage', '+91 98765 43210', 'Ravi@Zephyr.test', 'Rajkot'], [$l->contact_name, $l->company_name, $l->phone, $l->email, $l->city]);
        $this->assertSame(['IM-1001', 'Cold room', 'google'], [$l->external_id, $l->campaign, $l->utm_source]);
        $this->assertSame('2026-11-09 18:30:00', $l->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertEquals(500, $l->est_qty_sqm);
        $this->assertEquals(650000, $l->est_value);
        $this->assertEquals($raw, $l->payloads()->first()->payload, 'The message as the channel sent it, not our reading of it.');
        $this->assertSame(['Received from IndiaMART (ref IM-1001)'], $this->log($l));
    }

    public function test_without_a_raw_message_the_normalised_one_is_kept(): void
    {
        $r = $this->intake->ingest($this->company->id, 'Website', $this->enquiry(['external_id' => null]));

        $this->assertSame('Ravi Patel', Lead::find($r->leadId)->payloads()->first()->payload['contact_name']);
    }

    public function test_a_channel_that_is_not_in_the_companys_list_keeps_its_own_name(): void
    {
        $r = $this->intake->ingest($this->company->id, 'Brand New Portal', $this->enquiry());

        $this->assertSame('Brand New Portal', Lead::find($r->leadId)->source);
    }

    public function test_a_missing_name_falls_back_to_the_company_then_the_phone(): void
    {
        $a = $this->intake->ingest($this->company->id, 'Website', ['company_name' => 'Acme Cold', 'phone' => '9811111111']);
        $b = $this->intake->ingest($this->company->id, 'Website', ['phone' => '9822222222']);

        $this->assertSame('Acme Cold', Lead::find($a->leadId)->contact_name);
        $this->assertSame('9822222222', Lead::find($b->leadId)->contact_name);
    }

    public function test_messy_values_are_trimmed_capped_and_parsed(): void
    {
        $r = $this->intake->ingest($this->company->id, 'Website', [
            'contact_name' => '  ' . str_repeat('N', 400) . '  ', 'phone' => ' 9811111111 ', 'email' => 'not-an-email', 'est_value' => 'lots', 'est_qty_sqm' => '-5',
            'first_seen_at' => 'garbage', 'city' => ['an', 'array'], 'requirement' => true,
        ]);

        $l = Lead::find($r->leadId);
        $this->assertSame(150, mb_strlen($l->contact_name));
        $this->assertSame('9811111111', $l->phone);
        $this->assertNull($l->email);
        $this->assertNull($l->est_value);
        $this->assertNull($l->est_qty_sqm);
        $this->assertNull($l->city);
        $this->assertNull($l->requirement);
        $this->assertNotNull($l->first_seen_at, 'A date that cannot be read becomes "now".');
    }

    public function test_a_first_seen_date_in_the_future_is_clamped_to_now(): void
    {
        $r = $this->intake->ingest($this->company->id, 'Website', $this->enquiry(['first_seen_at' => '2030-01-01']));

        $this->assertSame('2026-11-10 09:00:00', Lead::find($r->leadId)->first_seen_at->format('Y-m-d H:i:s'));
    }

    // ── the same enquiry twice ───────────────────────────────────────────────

    public function test_the_same_enquiry_id_twice_makes_one_lead_and_one_message(): void
    {
        $first = $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());
        $again = $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());

        $this->assertSame(R::CREATED, $first->outcome);
        $this->assertSame(R::ALREADY_RECEIVED, $again->outcome);
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, LeadPayload::count());
    }

    public function test_the_same_id_after_the_lead_was_deleted_is_still_not_made_again(): void
    {
        $first = $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());
        Lead::find($first->leadId)->delete();

        $again = $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());

        $this->assertSame(R::ALREADY_RECEIVED, $again->outcome);
        $this->assertSame(0, Lead::count());
    }

    public function test_a_merged_enquiry_delivered_twice_adds_one_note_only(): void
    {
        $first = $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());
        $second = $this->enquiry(['external_id' => 'IM-1002', 'requirement' => 'Also 200 sqm roof sheets']);

        $one = $this->intake->ingest($this->company->id, 'IndiaMART', $second);
        $two = $this->intake->ingest($this->company->id, 'IndiaMART', $second);

        $this->assertSame(R::MERGED, $one->outcome);
        $this->assertSame(R::ALREADY_RECEIVED, $two->outcome);
        $this->assertCount(2, $this->log(Lead::find($first->leadId)), 'Received + one "enquired again".');
    }

    public function test_the_same_id_from_another_channel_or_company_is_a_different_enquiry(): void
    {
        $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());

        $other = $this->intake->ingest($this->company->id, 'TradeIndia', $this->enquiry(['phone' => '9833333333', 'email' => 'other@x.test']));
        $theirs = $this->intake->ingest(Company::factory()->create()->id, 'IndiaMART', $this->enquiry());

        $this->assertSame(R::CREATED, $other->outcome);
        $this->assertSame(R::CREATED, $theirs->outcome);
    }

    public function test_two_deliveries_at_the_same_instant_end_as_already_received(): void
    {
        // The race: both passed the "seen before?" check; the unique key stops the second insert.
        $service = new class(app(\App\Services\LeadService::class), app(\App\Services\LeadMatcher::class), app(\App\Services\LeadListService::class)) extends LeadIntakeService {
            public int $checks = 0;

            protected function alreadyReceived(int $companyId, string $source, string $externalId): bool
            {
                return ++$this->checks === 1 ? false : parent::alreadyReceived($companyId, $source, $externalId);
            }
        };
        $first = $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());

        $second = $service->ingest($this->company->id, 'IndiaMART', $this->enquiry(['phone' => '9899999999', 'email' => 'x@y.test']));

        $this->assertSame(R::ALREADY_RECEIVED, $second->outcome);
        $this->assertSame(1, Lead::count());
        $this->assertSame($first->leadId, Lead::first()->id);
    }

    // ── the same buyer ───────────────────────────────────────────────────────

    public function test_a_buyer_with_an_open_lead_gets_a_note_not_a_second_lead(): void
    {
        $first = $this->intake->ingest($this->company->id, 'Phone', $this->enquiry(['external_id' => null, 'next_follow_up_date' => null]));
        $lead = Lead::find($first->leadId);
        $lead->update(['next_follow_up_date' => '2026-11-20', 'city' => 'Surat']);

        $r = $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry(['phone' => '098765-43210', 'requirement' => 'Also need roof panels', 'city' => 'Rajkot']));

        $this->assertSame(R::MERGED, $r->outcome);
        $this->assertSame($lead->id, $r->leadId);
        $this->assertSame(1, Lead::count());
        $fresh = $lead->fresh();
        $this->assertSame('2026-11-10', $fresh->next_follow_up_date->toDateString(), 'A fresh enquiry brings the follow-up to today.');
        $this->assertSame('Surat', $fresh->city, 'What someone typed is never overwritten.');
        $this->assertContains('Enquired again on IndiaMART (ref IM-1001): Also need roof panels', $this->log($fresh));
        $this->assertSame(2, $fresh->payloads()->count());
    }

    public function test_a_merge_fills_what_the_lead_was_missing(): void
    {
        $first = $this->intake->ingest($this->company->id, 'Phone', ['contact_name' => 'Ravi', 'phone' => '9876543210', 'external_id' => null]);

        $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());

        $l = Lead::find($first->leadId);
        $this->assertSame('Zephyr Cold Storage', $l->company_name);
        $this->assertSame('Ravi@Zephyr.test', $l->email);
        $this->assertSame('Rajkot', $l->city);
        $this->assertSame('Ravi', $l->contact_name);
    }

    public function test_a_follow_up_already_due_is_left_alone(): void
    {
        $first = $this->intake->ingest($this->company->id, 'Phone', $this->enquiry(['external_id' => null]));
        Lead::find($first->leadId)->update(['next_follow_up_date' => '2026-11-05']);

        $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());

        $this->assertSame('2026-11-05', Lead::find($first->leadId)->next_follow_up_date->toDateString());
    }

    public function test_the_same_email_with_another_phone_is_the_same_buyer(): void
    {
        $first = $this->intake->ingest($this->company->id, 'Phone', $this->enquiry(['external_id' => null]));

        $r = $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry(['phone' => '9000000000', 'email' => 'RAVI@zephyr.test']));

        $this->assertSame(R::MERGED, $r->outcome);
        $this->assertSame($first->leadId, $r->leadId);
    }

    public function test_when_only_closed_leads_match_a_new_lead_is_made_and_names_them(): void
    {
        $old = $this->intake->ingest($this->company->id, 'Phone', $this->enquiry(['external_id' => null]));
        app(LeadService::class)->transition(Lead::find($old->leadId), 'lost', ['lost_reason' => 'Price']);

        $r = $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());

        $this->assertSame(R::CREATED, $r->outcome);
        $this->assertSame([$old->leadNo], $r->similar);
        $this->assertSame('lost', Lead::find($old->leadId)->status, 'The old lead is not reopened.');
        $this->assertSame("Received from IndiaMART (ref IM-1001). Same buyer as {$old->leadNo} (lost).", $this->log(Lead::find($r->leadId))[0]);
    }

    public function test_with_a_closed_and_an_open_match_it_merges_into_the_open_one(): void
    {
        $closed = $this->intake->ingest($this->company->id, 'Phone', $this->enquiry(['external_id' => null]));
        app(LeadService::class)->transition(Lead::find($closed->leadId), 'won');
        $open = app(LeadService::class)->create($this->company->id, ['contact_name' => 'Again', 'source' => 'Phone', 'phone' => '9876543210']);

        $r = $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());

        $this->assertSame(R::MERGED, $r->outcome);
        $this->assertSame($open->id, $r->leadId);
    }

    public function test_another_companys_leads_are_never_matched_or_touched(): void
    {
        $other = Company::factory()->create();
        $theirs = $this->intake->ingest($other->id, 'Phone', $this->enquiry(['external_id' => null]));

        $mine = $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());

        $this->assertSame(R::CREATED, $mine->outcome);
        $this->assertNotSame($theirs->leadId, $mine->leadId);
        $this->assertCount(1, $this->log(Lead::find($theirs->leadId)), 'Their timeline has only its own receipt.');
        $this->assertSame($other->id, Lead::find($theirs->leadId)->company_id);
    }

    // ── malformed ────────────────────────────────────────────────────────────

    public function test_a_message_with_nothing_to_go_on_is_rejected_and_kept(): void
    {
        $r = $this->intake->ingest($this->company->id, 'IndiaMART', ['external_id' => 'IM-9', 'requirement' => 'Need panels'], ['raw' => 'x']);

        $this->assertSame(R::REJECTED, $r->outcome);
        $this->assertNull($r->leadId);
        $this->assertSame(0, Lead::count());
        $p = LeadPayload::first();
        $this->assertNull($p->lead_id);
        $this->assertEquals(['raw' => 'x'], $p->payload);
    }

    public function test_a_name_with_no_way_to_reach_them_is_rejected(): void
    {
        $r = $this->intake->ingest($this->company->id, 'Website', ['contact_name' => 'Ghost', 'phone' => 'n/a', 'email' => 'nope']);

        $this->assertSame(R::REJECTED, $r->outcome);
        $this->assertStringContainsString('phone', $r->reason);
    }

    public function test_a_rejected_message_can_be_sent_again_corrected_with_the_same_id(): void
    {
        $bad = $this->intake->ingest($this->company->id, 'IndiaMART', ['external_id' => 'IM-7', 'contact_name' => 'Ravi']);
        $good = $this->intake->ingest($this->company->id, 'IndiaMART', ['external_id' => 'IM-7', 'contact_name' => 'Ravi', 'phone' => '9876543210']);

        $this->assertSame(R::REJECTED, $bad->outcome);
        $this->assertSame(R::CREATED, $good->outcome);
    }

    public function test_an_empty_message_does_not_throw(): void
    {
        $this->assertSame(R::REJECTED, $this->intake->ingest($this->company->id, '', [])->outcome);
    }

    public function test_the_result_is_readable_as_an_array(): void
    {
        $r = $this->intake->ingest($this->company->id, 'IndiaMART', $this->enquiry());

        $this->assertSame(['outcome' => 'created', 'lead_id' => $r->leadId, 'lead_no' => $r->leadNo, 'reason' => null, 'similar' => []], $r->toArray());
    }
}
