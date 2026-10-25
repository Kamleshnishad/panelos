<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadEngagement;
use App\Services\LeadScoring;
use App\Services\LeadStages;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** LEADS_PLAN 5.8 (scoring, LP-06): a 0 to 100 score and hot / warm / cold, worked out when leads are read, filterable and sortable, and explained in words. */
class LeadScoringTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'UTC'));
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true]);
        $this->actingAs($this->admin, 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lead(array $o = []): Lead
    {
        static $n = 0;
        $n++;

        return Lead::create($o + ['company_id' => $this->company->id, 'lead_no' => "SC-{$n}", 'contact_name' => "Buyer {$n}", 'source' => 'Phone', 'status' => 'new']);
    }

    /** A person touched the lead this many hours ago. */
    private function touch(Lead $lead, int $hoursAgo, ?int $userId = null): Lead
    {
        LeadActivity::create(['company_id' => $this->company->id, 'lead_id' => $lead->id, 'user_id' => $userId ?? $this->admin->id, 'type' => 'call', 'description' => 'Spoke', 'activity_date' => now()->subHours($hoursAgo)]);

        return $lead;
    }

    private function score(Lead $lead): ?int
    {
        $row = $this->getJson('/api/leads?per_page=100')->assertOk()->json('data');

        return collect($row)->firstWhere('id', $lead->id)['score'] ?? null;
    }

    private function detail(Lead $lead): array
    {
        return $this->getJson("/api/leads/{$lead->id}")->assertOk()->json('data');
    }

    public function test_the_score_adds_up_from_stage_size_activity_contact_and_follow_up(): void
    {
        $lead = $this->touch($this->lead(['status' => 'qualified', 'est_value' => 150000, 'phone' => '9811122233', 'next_follow_up_date' => '2026-11-11']), 2);

        $d = $this->detail($lead);

        $this->assertSame(70, $d['score']);                          // 20 stage + 14 size + 25 activity + 6 phone + 5 follow-up
        $this->assertSame('hot', $d['temperature']);
        $this->assertSame(['stage' => 20, 'size' => 14, 'activity' => 25, 'contact' => 6, 'follow_up' => 5], collect($d['score_detail']['parts'])->pluck('points', 'key')->all());
        $this->assertSame(70, $d['score_detail']['score']);
        $this->assertSame('Qualified, 50% chance of winning', collect($d['score_detail']['parts'])->firstWhere('key', 'stage')['why']);
    }

    public function test_the_four_example_leads_land_where_a_salesperson_would_expect(): void
    {
        $bare = $this->lead();                                                                                                                     // 4 + 0 + 25 + 0 + 0
        $reachable = $this->lead(['phone' => '9811122233', 'email' => 'a@b.test']);                                                                // 4 + 0 + 25 + 10 + 0
        $mid = $this->touch($this->lead(['status' => 'contacted', 'est_value' => 100000, 'phone' => '9822233344', 'next_follow_up_date' => '2026-11-11']), 72);   // 10 + 14 + 15 + 6 + 5
        $big = $this->touch($this->lead(['status' => 'quoted', 'est_value' => 500000, 'phone' => '9833344455', 'email' => 'c@d.test', 'next_follow_up_date' => '2026-11-10']), 1);   // 30 + 20 + 25 + 10 + 5

        $this->assertSame([29, 'cold'], [$this->score($bare), $this->detail($bare)['temperature']]);
        $this->assertSame([39, 'warm'], [$this->score($reachable), $this->detail($reachable)['temperature']]);
        $this->assertSame([50, 'warm'], [$this->score($mid), $this->detail($mid)['temperature']]);
        $this->assertSame([90, 'hot'], [$this->score($big), $this->detail($big)['temperature']]);
    }

    public function test_the_temperature_boundaries_are_65_and_35(): void
    {
        $this->assertSame([null, 'cold', 'cold', 'warm', 'warm', 'hot', 'hot'], array_map([LeadScoring::class, 'temperature'], [null, 0, 34, 35, 64, 65, 100]));
    }

    public function test_activity_counts_in_steps_at_2_7_and_14_days(): void
    {
        $points = [];
        foreach ([0, 48, 49, 168, 169, 336, 337, 2000] as $hours) {
            $points[$hours] = collect($this->detail($this->touch($this->lead(), $hours))['score_detail']['parts'])->firstWhere('key', 'activity')['points'];
        }

        $this->assertSame([0 => 25, 48 => 25, 49 => 15, 168 => 15, 169 => 5, 336 => 5, 337 => 0, 2000 => 0], $points);
    }

    public function test_a_lead_nobody_has_touched_is_judged_from_the_day_it_arrived(): void
    {
        $lead = $this->lead();
        $this->assertSame(25, collect($this->detail($lead)['score_detail']['parts'])->firstWhere('key', 'activity')['points']);

        Carbon::setTestNow(now()->addDays(10));
        $d = $this->detail($lead);

        $this->assertSame(5, collect($d['score_detail']['parts'])->firstWhere('key', 'activity')['points']);
        $this->assertSame('Nobody has touched it since it arrived 10 days ago', collect($d['score_detail']['parts'])->firstWhere('key', 'activity')['why']);
    }

    public function test_the_system_and_notes_without_a_person_do_not_count_as_a_touch(): void
    {
        $lead = $this->lead();
        LeadActivity::create(['company_id' => $this->company->id, 'lead_id' => $lead->id, 'user_id' => null, 'type' => 'note', 'description' => 'system line', 'activity_date' => now()]);
        Carbon::setTestNow(now()->addDays(20));

        $this->assertSame(0, collect($this->detail($lead)['score_detail']['parts'])->firstWhere('key', 'activity')['points']);
    }

    public function test_size_uses_value_or_area_whichever_is_bigger(): void
    {
        $size = fn (array $o) => collect($this->detail($this->lead($o))['score_detail']['parts'])->firstWhere('key', 'size')['points'];

        $this->assertSame([0, 8, 8, 14, 14, 20, 20, 14], [
            $size([]), $size(['est_value' => 1]), $size(['est_qty_sqm' => 10]), $size(['est_value' => 100000]), $size(['est_qty_sqm' => 200]),
            $size(['est_value' => 500000]), $size(['est_qty_sqm' => 1000]), $size(['est_value' => 99999, 'est_qty_sqm' => 250]),
        ]);
    }

    public function test_follow_up_counts_only_when_it_is_today_or_later(): void
    {
        $f = fn (?string $d) => collect($this->detail($this->lead(['next_follow_up_date' => $d]))['score_detail']['parts'])->firstWhere('key', 'follow_up');

        $this->assertSame([5, 5, 0, 0], [$f('2026-11-10')['points'], $f('2026-12-01')['points'], $f('2026-11-09')['points'], $f(null)['points']]);
        $this->assertSame(['Follow-up is overdue', 'No follow-up planned'], [$f('2026-11-09')['why'], $f(null)['why']]);
    }

    public function test_contact_details_phone_six_email_four(): void
    {
        $c = fn (array $o) => collect($this->detail($this->lead($o))['score_detail']['parts'])->firstWhere('key', 'contact')['points'];

        $this->assertSame([0, 6, 4, 10, 0], [$c([]), $c(['phone' => '9811122233']), $c(['email' => 'a@b.test']), $c(['phone' => '9811122233', 'email' => 'a@b.test']), $c(['phone' => ''])]);
    }

    public function test_a_lead_on_hold_is_always_cold_and_closed_leads_have_no_score(): void
    {
        $hold = $this->touch($this->lead(['status' => 'on_hold', 'est_value' => 900000, 'phone' => '9811122233', 'email' => 'a@b.test', 'next_follow_up_date' => '2026-11-20']), 1);
        $d = $this->detail($hold);
        $this->assertSame([34, 'cold', 0], [$d['score'], $d['temperature'], collect($d['score_detail']['parts'])->firstWhere('key', 'stage')['points']]);
        $this->assertSame(34, $this->score($hold));
        $this->assertSame('cold', $this->detail($this->lead(['status' => 'on_hold']))['temperature']);

        foreach (['won', 'lost', 'invalid'] as $s) {
            $d = $this->detail($this->lead(['status' => $s]));
            $this->assertSame([null, null, null], [$d['score'], $d['temperature'], $d['score_detail']], $s);
        }
    }

    public function test_the_stage_part_follows_the_companys_chance_of_winning_and_not_its_name(): void
    {
        $lead = $this->lead(['status' => 'contacted']);
        $stage = fn () => collect($this->detail($lead)['score_detail']['parts'])->firstWhere('key', 'stage')['points'];
        $this->assertSame(10, $stage());
        $id = collect($this->getJson('/api/settings/lead-stages')->json('data.stages'))->firstWhere('key', 'contacted')['id'];

        $this->putJson("/api/settings/lead-stages/{$id}", ['name' => 'Called', 'probability' => 80])->assertOk();

        $this->assertSame(32, $stage());                              // 80 % x 0.4
        $this->postJson('/api/settings/lead-stages', ['name' => 'Site visit', 'probability' => 60])->assertCreated();
        $visit = $this->lead(['status' => 'site_visit']);
        $this->assertSame(24, collect($this->detail($visit)['score_detail']['parts'])->firstWhere('key', 'stage')['points']);
    }

    public function test_the_sql_score_and_the_words_always_agree(): void
    {
        $this->postJson('/api/settings/lead-stages', ['name' => 'Site visit', 'probability' => 65])->assertCreated();
        $rows = [];
        foreach (['new', 'contacted', 'qualified', 'quoted', 'site_visit', 'on_hold'] as $status) {
            foreach ([[null, null], [90000, null], [null, 250], [700000, 30]] as [$value, $sqm]) {
                foreach ([[], ['phone' => '9811122233'], ['phone' => '9811122233', 'email' => 'x@y.test']] as $contact) {
                    $rows[] = $this->lead(['status' => $status, 'est_value' => $value, 'est_qty_sqm' => $sqm, 'next_follow_up_date' => count($rows) % 3 === 0 ? '2026-11-10' : (count($rows) % 3 === 1 ? '2026-11-01' : null)] + $contact);
                }
            }
        }
        foreach ($rows as $i => $lead) {
            if ($i % 4 !== 3) {
                $this->touch($lead, [0, 30, 100, 200, 400][$i % 5]);
            }
        }

        $mismatch = [];
        foreach ($rows as $lead) {
            $fresh = Lead::find($lead->id);
            LeadEngagement::attach($fresh);
            LeadScoring::attach($fresh);
            $words = LeadScoring::explain($fresh)['score'];
            if ((int) $fresh->score !== $words) {
                $mismatch[] = [$lead->id, (int) $fresh->score, $words];
            }
        }

        $this->assertSame([], $mismatch);
        $this->assertCount(72, $rows);
    }

    public function test_the_list_can_be_filtered_by_temperature(): void
    {
        $cold = $this->lead();
        $warm = $this->lead(['phone' => '9811122233', 'email' => 'a@b.test']);
        $hot = $this->touch($this->lead(['status' => 'quoted', 'est_value' => 500000, 'phone' => '9833344455']), 1);
        $won = $this->lead(['status' => 'won']);

        $ids = fn (string $t) => collect($this->getJson("/api/leads?temperature={$t}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$cold->id], $ids('cold'));
        $this->assertSame([$warm->id], $ids('warm'));
        $this->assertSame([$hot->id], $ids('hot'));
        $this->assertEqualsCanonicalizing([$cold->id, $warm->id, $hot->id, $won->id], collect($this->getJson('/api/leads?temperature=bogus')->json('data'))->pluck('id')->all());
    }

    public function test_the_list_can_be_sorted_by_score_with_closed_leads_last(): void
    {
        $low = $this->lead();
        $won = $this->lead(['status' => 'won']);
        $high = $this->touch($this->lead(['status' => 'quoted', 'est_value' => 500000, 'phone' => '9833344455']), 1);
        $mid = $this->lead(['phone' => '9811122233', 'email' => 'a@b.test']);
        $order = fn (string $q) => collect($this->getJson("/api/leads?sort=score{$q}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$high->id, $mid->id, $low->id, $won->id], $order(''));
        $this->assertSame([$low->id, $mid->id, $high->id, $won->id], $order('&dir=asc'));
    }

    public function test_the_board_takes_the_temperature_filter_and_its_cards_carry_the_score(): void
    {
        $this->lead();
        $hot = $this->touch($this->lead(['status' => 'quoted', 'est_value' => 500000, 'phone' => '9833344455']), 1);

        $cols = collect($this->getJson('/api/leads/board?temperature=hot')->assertOk()->json('data.columns'))->keyBy('status');

        $this->assertSame([0, 1], [$cols['new']['count'], $cols['quoted']['count']]);
        $this->assertSame([$hot->id, 'hot'], [$cols['quoted']['leads'][0]['id'], $cols['quoted']['leads'][0]['temperature']]);
    }

    public function test_the_insight_counts_open_leads_by_temperature(): void
    {
        $this->lead();
        $this->lead();
        $this->lead(['phone' => '9811122233', 'email' => 'a@b.test']);
        $this->touch($this->lead(['status' => 'quoted', 'est_value' => 500000, 'phone' => '9833344455']), 1);
        $this->lead(['status' => 'won']);
        $this->lead(['status' => 'lost']);

        $this->assertSame(['hot' => 1, 'warm' => 1, 'cold' => 2], $this->getJson('/api/leads/dashboard')->assertOk()->json('data.temperature'));
    }

    public function test_a_saved_view_keeps_the_temperature_and_drops_an_unknown_one(): void
    {
        $ok = $this->postJson('/api/leads/views', ['name' => 'Hot ones', 'filters' => ['temperature' => 'hot', 'sort' => 'score']])->assertCreated();
        $bad = $this->postJson('/api/leads/views', ['name' => 'Odd', 'filters' => ['temperature' => 'boiling']])->assertCreated();

        $this->assertSame(['hot', 'score'], [$ok->json('data.filters.temperature'), $ok->json('data.filters.sort')]);
        $this->assertNull($bad->json('data.filters.temperature'));
    }

    public function test_the_score_drifts_down_by_itself_as_a_lead_goes_quiet(): void
    {
        $lead = $this->touch($this->lead(['status' => 'qualified', 'est_value' => 150000, 'phone' => '9811122233', 'next_follow_up_date' => '2026-11-30']), 1);
        $before = $this->score($lead);

        Carbon::setTestNow(now()->addDays(20));

        $this->assertSame([70, 'hot'], [$before, LeadScoring::temperature($before)]);
        $this->assertSame(45, $this->score($lead));                   // activity 25 -> 0; stage 20 + size 14 + contact 6 + follow-up 5 remain
        $this->assertSame('warm', $this->detail($lead)['temperature']);
    }

    public function test_staff_who_see_only_their_own_leads_count_and_filter_only_their_own(): void
    {
        $role = Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view', 'leads.own_only']]);
        $me = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => $role->id]);
        $mine = $this->lead(['assigned_to_user_id' => $me->id]);
        $this->lead(['assigned_to_user_id' => $this->admin->id]);
        $this->actingAs($me, 'sanctum');

        $this->assertSame(1, array_sum($this->getJson('/api/leads/dashboard')->json('data.temperature')));
        $this->assertSame([$mine->id], collect($this->getJson('/api/leads?temperature=cold')->json('data'))->pluck('id')->all());
    }

    public function test_another_companys_stages_do_not_change_this_companys_score(): void
    {
        $other = Company::factory()->create();
        LeadStages::for($other->id);
        \App\Models\LeadStage::withoutGlobalScope('tenant')->where('company_id', $other->id)->where('key', 'contacted')->update(['probability' => 100]);
        LeadStages::forget();
        $lead = $this->lead(['status' => 'contacted']);

        $this->assertSame(10, collect($this->detail($lead)['score_detail']['parts'])->firstWhere('key', 'stage')['points']);
    }

    public function test_the_export_and_import_still_work_with_the_score_columns_around(): void
    {
        $this->lead(['phone' => '9811122233']);

        $this->get('/api/leads/export?temperature=cold')->assertOk();
    }
}
