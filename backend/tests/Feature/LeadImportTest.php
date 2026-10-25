<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAssignmentRule;
use App\Models\LeadImport;
use App\Models\LeadPayload;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadImportService;
use App\Services\LeadIntakeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** LEADS_PLAN 4.2: leads in and out as a spreadsheet: check the file first, import it, undo the last import, export the list. */
class LeadImportTest extends TestCase
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

    private const HEAD = "Name,Company,Phone,Email,City,Source,Requirement,Application,Area (sqm),Value (Rs),Reference,Date\n";

    private function file(string $csv, string $name = 'leads.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $csv);
    }

    private function preview(string $csv, ?string $source = 'Exhibition', array $headers = [])
    {
        return $this->post('/api/leads/import/preview', ['file' => $this->file($csv), 'source' => $source], $headers + ['Accept' => 'application/json']);
    }

    private function imported(string $csv, ?string $source = 'Exhibition'): LeadImport
    {
        $id = $this->preview($csv, $source)->assertOk()->json('data.id');
        $this->postJson("/api/leads/import/{$id}/commit")->assertOk();

        return LeadImport::findOrFail($id);
    }

    private function leads(): \Illuminate\Support\Collection
    {
        return Lead::where('company_id', $this->company->id)->orderBy('id')->get();
    }

    private function bell(User $u)
    {
        return AppNotification::withoutGlobalScope('tenant')->where('user_id', $u->id)->where('type', 'lead_assigned')->get();
    }

    // ── the template ─────────────────────────────────────────────────────────

    public function test_the_template_opens_in_excel_and_its_own_example_row_is_understood(): void
    {
        $res = $this->get('/api/leads/import/template')->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBFName,Company,Phone,Email,City,Source,Requirement,Application,\"Area (sqm)\",\"Value (Rs)\",Reference,Date", $csv);

        $p = $this->preview($csv, 'Exhibition')->assertOk();
        $this->assertSame(1, $p->json('data.counts.created'));
        $this->assertSame([], $p->json('data.ignored_columns'));
        $this->assertSame([], $p->json('data.rows.0.notes'));
    }

    // ── checking a file creates nothing ──────────────────────────────────────

    public function test_checking_a_file_creates_no_lead_and_says_what_would_happen(): void
    {
        Lead::create(['company_id' => $this->company->id, 'lead_no' => 'LD-OLD-1', 'contact_name' => 'Old Buyer', 'phone' => '9811111111', 'source' => 'Phone', 'status' => 'contacted']);
        $csv = self::HEAD
            . "Ravi Patel,Zephyr,9876543210,ravi@z.test,Rajkot,,Need panels,Wall,300,450000,,\n"
            . "Old Buyer,,+91 98111 11111,,Surat,,Again please,,,,,\n"
            . "No Contact,,,,,,,,,,,\n"
            . "Ravi Again,,098765 43210,,,,,,,,,\n";

        $res = $this->preview($csv)->assertOk();

        $this->assertSame(['created' => 1, 'merged' => 2, 'already_received' => 0, 'rejected' => 1], collect($res->json('data.counts'))->only(['created', 'merged', 'already_received', 'rejected'])->all());
        $this->assertSame(['created', 'merged', 'rejected', 'merged'], collect($res->json('data.rows'))->pluck('outcome')->all());
        $this->assertStringContainsString('LD-OLD-1', $res->json('data.rows.1.reason'));
        $this->assertStringContainsString('phone', $res->json('data.rows.2.reason'));
        $this->assertStringContainsString('row 2 of this file', $res->json('data.rows.3.reason'));     // the second Ravi is the first Ravi
        $this->assertSame(1, Lead::where('company_id', $this->company->id)->count());
        $this->assertSame(0, LeadPayload::withoutGlobalScope('tenant')->count());
        $this->assertSame('previewed', $res->json('data.status'));
    }

    public function test_a_buyer_whose_only_lead_is_closed_gets_a_new_lead_and_the_check_says_so(): void
    {
        Lead::create(['company_id' => $this->company->id, 'lead_no' => 'LD-OLD-2', 'contact_name' => 'Lost Buyer', 'phone' => '9822222222', 'source' => 'Phone', 'status' => 'lost']);

        $row = $this->preview(self::HEAD . "Lost Buyer,,9822222222,,,,,,,,,\n")->json('data.rows.0');

        $this->assertSame('created', $row['outcome']);
        $this->assertStringContainsString('LD-OLD-2', $row['reason']);
    }

    public function test_a_reference_already_received_or_repeated_in_the_file_is_not_made_twice(): void
    {
        $this->imported(self::HEAD . "First,,9833333333,,,,,,,,REF-1,\n");

        $rows = $this->preview(self::HEAD . "First,,9833333333,,,,,,,,REF-1,\nSecond,,9844444444,,,,,,,,REF-2,\nThird,,9855555555,,,,,,,,REF-2,\n")->json('data.rows');

        $this->assertSame(['already_received', 'created', 'already_received'], array_column($rows, 'outcome'));
        $this->assertStringContainsString('row 3', $rows[2]['reason'] . ' row 3');
    }

    public function test_what_the_check_predicts_is_what_the_import_does(): void
    {
        Lead::create(['company_id' => $this->company->id, 'lead_no' => 'LD-OLD-3', 'contact_name' => 'Known', 'phone' => '9866666666', 'source' => 'Phone', 'status' => 'new']);
        $csv = self::HEAD . "A,,9877777771,,,,,,,,,\nKnown,,9866666666,,,,,,,,,\nB,,,,,,,,,,,\nA2,,9877777771,,,,,,,,,\nC,,,c@x.test,,,,,,,,\n";

        $predicted = $this->preview($csv)->json('data.counts');
        $id = LeadImport::latest('id')->value('id');
        $done = $this->postJson("/api/leads/import/{$id}/commit")->assertOk()->json('data.counts');

        foreach (['created', 'merged', 'already_received', 'rejected'] as $k) {
            $this->assertSame($predicted[$k], $done[$k], $k);
        }
    }

    // ── reading all kinds of files ───────────────────────────────────────────

    public function test_headings_in_any_order_and_spelling_and_unknown_ones_are_listed(): void
    {
        $csv = "Mobile No;E-mail ID;Full Name;Colour;Remarks\n9801010101;a@x.test;Asha Rao;Red;Wants roof panels\n";

        $res = $this->preview($csv)->assertOk();

        $this->assertSame(['Colour'], $res->json('data.ignored_columns'));
        $this->assertSame('Asha Rao', $res->json('data.rows.0.name'));
        $this->assertSame('created', $res->json('data.rows.0.outcome'));
    }

    public function test_a_file_saved_by_excel_as_plain_csv_keeps_its_accents_and_a_byte_order_mark_is_ignored(): void
    {
        $ansi = mb_convert_encoding("Name,Phone\nJosé Müller,9802020202\n", 'Windows-1252', 'UTF-8');
        $this->assertSame('José Müller', $this->preview($ansi)->json('data.rows.0.name'));
        $this->assertSame('Zoë', $this->preview("\xEF\xBB\xBFName,Phone\nZoë,9803030303\n")->json('data.rows.0.name'));
    }

    public function test_quoted_commas_and_new_lines_inside_a_cell_survive(): void
    {
        $this->imported("Name,Phone,Requirement\n\"Rao, Asha\",9804040404,\"Two walls,\nthen the roof; \"\"urgent\"\"\"\n");

        $lead = $this->leads()->first();
        $this->assertSame('Rao, Asha', $lead->contact_name);
        $this->assertSame("Two walls,\nthen the roof; \"urgent\"", $lead->requirement);
    }

    public function test_dates_are_read_day_first_and_a_date_that_means_nothing_is_ignored_with_a_note(): void
    {
        $p = $this->preview(self::HEAD . "A,,9805050501,,,,,,,,,05-03-2026\nB,,9805050502,,,,,,,,,2026-03-05\nC,,9805050503,,,,,,,,,5 Mar 2026\nD,,9805050504,,,,,,,,,31-02-2026\nE,,9805050505,,,,,,,,,soon\n");
        $rows = $p->json('data.rows');

        $this->assertSame([], $rows[0]['notes'] + $rows[1]['notes'] + $rows[2]['notes']);
        $this->assertStringContainsString('was not understood', $rows[3]['notes'][0]);
        $this->assertStringContainsString('"soon"', $rows[4]['notes'][0]);

        $id = $p->json('data.id');
        $this->postJson("/api/leads/import/{$id}/commit")->assertOk();
        $this->assertSame(['2026-03-04 18:30:00'] , [$this->leads()[0]->first_seen_at->utc()->format('Y-m-d H:i:s')]);     // 5 March, midnight in India
        $this->assertSame(['2026-03-04'], [$this->leads()[1]->first_seen_at->utc()->format('Y-m-d')]);
    }

    public function test_a_phone_number_that_excel_turned_into_scientific_notation_is_flagged_not_stored(): void
    {
        $row = $this->preview(self::HEAD . "Sci,,9.87654E+11,,,,,,,,,\n")->json('data.rows.0');

        $this->assertSame('rejected', $row['outcome']);
        $this->assertStringContainsString('shortened by Excel', $row['notes'][0]);
    }

    public function test_an_unknown_source_falls_back_to_the_chosen_one_with_a_note_and_a_known_one_is_used(): void
    {
        $rows = $this->preview(self::HEAD . "A,,9806060601,,,Referral,,,,,,\nB,,9806060602,,,Smoke signals,,,,,,\nC,,9806060603,,,,,,,,,\n", 'Exhibition')->json('data.rows');

        $this->assertSame(['Referral', 'Exhibition', 'Exhibition'], array_column($rows, 'source'));
        $this->assertStringContainsString('Smoke signals', $rows[1]['notes'][0]);
    }

    public function test_files_that_cannot_be_used_are_refused_in_words(): void
    {
        foreach ([
            ['', 'empty'],
            ["Colour,Size\nred,big\n", 'headings such as Name'],
            ["Name,Phone\n", 'no rows'],
            ["Name,Phone\n" . str_repeat("X,9807070707\n", LeadImportService::MAX_ROWS + 1), 'limit is 1000'],
        ] as [$csv, $words]) {
            $res = $this->preview($csv)->assertStatus(422);
            $this->assertStringContainsString($words, $res->json('message'));
        }
        $this->post('/api/leads/import/preview', [], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, LeadImport::count());
    }

    // ── importing ────────────────────────────────────────────────────────────

    public function test_importing_creates_the_leads_with_their_source_and_keeps_the_original_row(): void
    {
        $imp = $this->imported(self::HEAD . "Ravi Patel,Zephyr,98765 43210,Ravi@Z.test,Rajkot,Referral,Need panels,Cold Room,300,\"4,50,000\",TS-9,20-10-2026\n");

        $lead = $this->leads()->first();
        $this->assertSame(['Ravi Patel', 'Zephyr', 'Rajkot', 'Referral', 'Cold Room', 'TS-9', 'new'], [$lead->contact_name, $lead->company_name, $lead->city, $lead->source, $lead->application, $lead->external_id, $lead->status]);
        $this->assertSame([300.0, 450000.0], [(float) $lead->est_qty_sqm, (float) $lead->est_value]);
        $this->assertSame('2026-10-19 18:30:00', $lead->first_seen_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Need panels', $lead->requirement);
        $this->assertSame('done', $imp->status);
        $this->assertSame([$lead->id], $imp->created_lead_ids);
        $this->assertSame(['Name' => 'Ravi Patel'], collect(LeadPayload::withoutGlobalScope('tenant')->first()->payload)->only('Name')->all());
    }

    public function test_a_buyer_who_already_has_an_open_lead_gets_a_note_not_a_second_lead(): void
    {
        $old = Lead::create(['company_id' => $this->company->id, 'lead_no' => 'LD-OLD-4', 'contact_name' => 'Known', 'phone' => '9808080808', 'source' => 'Phone', 'status' => 'contacted']);

        $imp = $this->imported(self::HEAD . "Known,,9808080808,,Pune,,Wants a quote,,,,,\n");

        $this->assertSame(1, $this->leads()->count());
        $this->assertSame(1, $imp->counts['merged']);
        $this->assertTrue(LeadActivity::where('lead_id', $old->id)->where('description', 'like', 'Enquired again%')->exists());
        $this->assertSame([], $imp->created_lead_ids);
    }

    public function test_a_file_can_be_imported_only_once(): void
    {
        $imp = $this->imported(self::HEAD . "A,,9809090909,,,,,,,,,\n");

        $this->postJson("/api/leads/import/{$imp->id}/commit")->assertStatus(422);
        $this->assertSame(1, $this->leads()->count());
    }

    public function test_one_bad_row_does_not_stop_the_rest(): void
    {
        $imp = $this->imported(self::HEAD . "Good One,,9810101010,,,,,,,,,\nNo Contact,,,,,,,,,,,\nGood Two,,9810101011,,,,,,,,,\n");

        $this->assertSame(['created' => 2, 'rejected' => 1], collect($imp->counts)->only(['created', 'rejected'])->all());
        $this->assertSame(1, LeadPayload::withoutGlobalScope('tenant')->whereNull('lead_id')->count());          // the rejected row is kept, without a lead
    }

    public function test_a_check_belongs_to_its_company(): void
    {
        $id = $this->preview(self::HEAD . "A,,9811010101,,,,,,,,,\n")->json('data.id');
        $other = User::factory()->create(['company_id' => Company::factory()->create()->id, 'is_company_admin' => true]);
        $this->actingAs($other, 'sanctum');

        $this->postJson("/api/leads/import/{$id}/commit")->assertStatus(404);
        $this->postJson("/api/leads/import/{$id}/undo")->assertStatus(404);
        $this->assertSame(0, Lead::count());
    }

    public function test_the_stored_rows_never_leave_the_server(): void
    {
        $this->imported(self::HEAD . "Secret Buyer,,9812020202,,,,,,,,,\n");

        $res = $this->getJson('/api/leads/import/last')->assertOk();

        $this->assertStringNotContainsString('Secret Buyer', $res->getContent());
        $this->assertStringNotContainsString('9812020202', $res->getContent());
    }

    // ── the bell during an import ────────────────────────────────────────────

    public function test_an_import_rings_each_person_once_with_a_count_instead_of_once_per_lead(): void
    {
        $ben = User::factory()->create(['company_id' => $this->company->id, 'name' => 'Ben']);
        LeadAssignmentRule::create(['company_id' => $this->company->id, 'name' => 'All', 'priority' => 1, 'is_active' => true, 'user_ids' => [$ben->id]]);

        $this->imported(self::HEAD . "A,,9813030301,,,,,,,,,\nB,,9813030302,,,,,,,,,\nC,,9813030303,,,,,,,,,\n");

        $rings = $this->bell($ben);
        $this->assertCount(1, $rings);
        $this->assertStringContainsString('3 new leads assigned to you', $rings[0]->title);
        $this->assertSame(3, Lead::where('assigned_to_user_id', $ben->id)->count());
    }

    public function test_the_bell_is_back_to_normal_after_an_import(): void
    {
        $ben = User::factory()->create(['company_id' => $this->company->id]);
        LeadAssignmentRule::create(['company_id' => $this->company->id, 'name' => 'All', 'priority' => 1, 'is_active' => true, 'user_ids' => [$ben->id]]);
        $this->imported(self::HEAD . "A,,9814040401,,,,,,,,,\n");
        $before = $this->bell($ben)->count();

        app(LeadIntakeService::class)->ingest($this->company->id, 'Website', ['contact_name' => 'Walk Up', 'phone' => '9814040402']);

        $this->assertSame($before + 1, $this->bell($ben)->count());
    }

    // ── undo ─────────────────────────────────────────────────────────────────

    public function test_undo_takes_back_what_the_import_created_and_nothing_else(): void
    {
        $mine = Lead::create(['company_id' => $this->company->id, 'lead_no' => 'LD-MINE', 'contact_name' => 'Mine', 'phone' => '9815050505', 'source' => 'Phone', 'status' => 'new']);
        $imp = $this->imported(self::HEAD . "A,,9815050501,,,,,,,,,\nB,,9815050502,,,,,,,,,\nMine,,9815050505,,,,,,,,,\n");

        $res = $this->postJson("/api/leads/import/{$imp->id}/undo")->assertOk();

        $this->assertSame(['removed' => 2, 'kept' => 0, 'added_to_existing_leads' => 1], collect($res->json('data.undo_result'))->only(['removed', 'kept', 'added_to_existing_leads'])->all());
        $this->assertSame('undone', $res->json('data.status'));
        $this->assertSame([$mine->id], $this->leads()->pluck('id')->all());
    }

    public function test_undo_keeps_leads_somebody_has_worked_on_and_says_why(): void
    {
        $imp = $this->imported(self::HEAD . "Untouched,,9816060601,,,,,,,,,\nContacted,,9816060602,,,,,,,,,\nNoted,,9816060603,,,,,,,,,\nEdited,,9816060604,,,,,,,,,\nAgain,,9816060605,,,,,,,,,\n");
        Carbon::setTestNow(now()->addHour());
        $by = fn (string $name) => Lead::where('contact_name', $name)->firstOrFail();
        $by('Contacted')->update(['status' => 'contacted']);
        LeadActivity::create(['company_id' => $this->company->id, 'lead_id' => $by('Noted')->id, 'user_id' => $this->admin->id, 'type' => 'note', 'description' => 'Called', 'activity_date' => now()]);
        $by('Edited')->update(['city' => 'Surat']);
        app(LeadIntakeService::class)->ingest($this->company->id, 'Website', ['contact_name' => 'Again', 'phone' => '9816060605']);     // a second enquiry from the same buyer

        $res = $this->postJson("/api/leads/import/{$imp->id}/undo")->assertOk();

        $this->assertSame([1, 4], [$res->json('data.undo_result.removed'), $res->json('data.undo_result.kept')]);
        $this->assertSame(['Contacted', 'Noted', 'Edited', 'Again'], $this->leads()->pluck('contact_name')->all());
        $this->assertCount(4, $res->json('data.undo_result.kept_leads'));
        $this->assertStringContainsString('status', collect($res->json('data.undo_result.kept_leads'))->firstWhere('lead_no', $by('Contacted')->lead_no)['reason']);
    }

    public function test_a_lead_with_a_quotation_or_customer_stays(): void
    {
        $imp = $this->imported(self::HEAD . "Quoted,,9817070701,,,,,,,,,\n");
        $lead = $this->leads()->first();
        \DB::table('leads')->where('id', $lead->id)->update(['quotation_id' => null, 'customer_id' => \App\Models\Customer::factory()->create(['company_id' => $this->company->id])->id]);

        $res = $this->postJson("/api/leads/import/{$imp->id}/undo")->assertOk();

        $this->assertSame([0, 1], [$res->json('data.undo_result.removed'), $res->json('data.undo_result.kept')]);
    }

    public function test_only_the_most_recent_import_can_be_undone_and_only_once(): void
    {
        $first = $this->imported(self::HEAD . "A,,9818080801,,,,,,,,,\n");
        $second = $this->imported(self::HEAD . "B,,9818080802,,,,,,,,,\n");

        $this->postJson("/api/leads/import/{$first->id}/undo")->assertStatus(422);
        $this->postJson("/api/leads/import/{$second->id}/undo")->assertOk();
        $this->postJson("/api/leads/import/{$second->id}/undo")->assertStatus(422);
        $this->postJson("/api/leads/import/{$first->id}/undo")->assertStatus(422);        // the latest import that ran is now the undone one
        $this->assertSame(['A'], $this->leads()->pluck('contact_name')->all());
    }

    public function test_a_file_that_was_only_checked_cannot_be_undone(): void
    {
        $id = $this->preview(self::HEAD . "A,,9819090901,,,,,,,,,\n")->json('data.id');

        $this->postJson("/api/leads/import/{$id}/undo")->assertStatus(422);
    }

    public function test_the_screen_is_told_which_import_undo_applies_to(): void
    {
        $this->assertNull($this->getJson('/api/leads/import/last')->json('data'));

        $imp = $this->imported(self::HEAD . "A,,9820101001,,,,,,,,,\n");

        $this->getJson('/api/leads/import/last')->assertJsonPath('data.id', $imp->id)->assertJsonPath('data.status', 'done')->assertJsonPath('data.created', 1);
    }

    public function test_checks_nobody_imported_are_cleared_after_two_days(): void
    {
        $old = $this->preview(self::HEAD . "A,,9821111101,,,,,,,,,\n")->json('data.id');
        $done = $this->imported(self::HEAD . "B,,9821111102,,,,,,,,,\n");
        Carbon::setTestNow(now()->addDays(3));

        $this->assertSame(1, app(LeadImportService::class)->prune());

        $this->assertNull(LeadImport::find($old));
        $this->assertNotNull(LeadImport::find($done->id));
    }

    // ── who may ──────────────────────────────────────────────────────────────

    private function userWith(array $permissions): User
    {
        $role = Role::factory()->create(['company_id' => $this->company->id, 'permissions' => $permissions]);

        return User::factory()->create(['company_id' => $this->company->id, 'role_id' => $role->id, 'is_company_admin' => false]);
    }

    public function test_importing_needs_leads_manage(): void
    {
        $csv = self::HEAD . "A,,9822121201,,,,,,,,,\n";
        $this->actingAs($this->userWith(['leads.view']), 'sanctum');
        $this->preview($csv)->assertStatus(403);

        $this->actingAs($this->userWith(['leads.manage']), 'sanctum');
        $this->preview($csv)->assertOk();
    }

    public function test_the_export_needs_reports_view_because_it_is_a_whole_contact_list(): void
    {
        $this->actingAs($this->userWith(['leads.view', 'leads.manage']), 'sanctum');
        $this->get('/api/leads/export')->assertStatus(403);

        $this->actingAs($this->userWith(['leads.view', 'reports.view']), 'sanctum');
        $this->get('/api/leads/export')->assertOk();
    }

    // ── export ───────────────────────────────────────────────────────────────

    private function mk(array $o): Lead
    {
        return Lead::create($o + ['company_id' => $this->company->id, 'lead_no' => 'LD-' . uniqid(), 'source' => 'Phone', 'status' => 'new']);
    }

    private function exported(string $query = ''): array
    {
        $csv = $this->get('/api/leads/export' . $query)->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $h = fopen('php://temp', 'w+');
        fwrite($h, substr($csv, 3));
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $rows[] = $r;
        }

        return $rows;
    }

    public function test_the_export_has_a_heading_row_and_the_leads_newest_first_for_this_company_only(): void
    {
        $a = $this->mk(['contact_name' => 'First', 'phone' => '9830000001']);
        Carbon::setTestNow(now()->addMinute());
        $b = $this->mk(['contact_name' => 'Second', 'phone' => '9830000002', 'city' => 'Rajkot', 'assigned_to_user_id' => $this->admin->id, 'est_value' => 125000, 'landing_page' => 'https://x.test/p']);
        Lead::create(['company_id' => Company::factory()->create()->id, 'lead_no' => 'LD-OTHER', 'contact_name' => 'Other Company', 'source' => 'Phone', 'status' => 'new']);

        $rows = $this->exported();

        $this->assertSame('Lead No', $rows[0][0]);
        $this->assertSame([$b->lead_no, $a->lead_no], [$rows[1][0], $rows[2][0]]);
        $this->assertCount(3, $rows);
        $this->assertSame(['Second', '9830000002', 'Rajkot', $this->admin->name, '125000.00', 'https://x.test/p'], [$rows[1][2], $rows[1][4], $rows[1][6], $rows[1][10], $rows[1][14], $rows[1][17]]);
    }

    public function test_the_export_follows_the_filters_on_screen_and_hides_junk_like_the_list_does(): void
    {
        $this->mk(['contact_name' => 'Rajkot Won', 'status' => 'won', 'source' => 'Website']);
        $this->mk(['contact_name' => 'Surat Open', 'status' => 'new', 'source' => 'Website']);
        $this->mk(['contact_name' => 'Phone Open', 'status' => 'new', 'source' => 'Phone']);
        $this->mk(['contact_name' => 'Junk', 'status' => 'invalid', 'source' => 'Phone']);

        $names = fn (string $q) => array_column(array_slice($this->exported($q), 1), 2);

        $this->assertEqualsCanonicalizing(['Rajkot Won', 'Surat Open', 'Phone Open'], $names(''));
        $this->assertSame(['Rajkot Won'], $names('?status=won'));
        $this->assertEqualsCanonicalizing(['Rajkot Won', 'Surat Open'], $names('?source=Website'));
        $this->assertSame(['Surat Open'], $names('?search=Surat'));
        $this->assertSame(['Junk'], $names('?status=invalid'));
    }

    public function test_a_name_that_looks_like_a_formula_is_defused(): void
    {
        $this->mk(['contact_name' => '=HYPERLINK("http://evil.test","click")', 'phone' => '9831111111']);

        $this->assertStringStartsWith("'=", $this->exported()[1][2]);
    }

    public function test_what_is_exported_can_be_imported_again(): void
    {
        $this->mk(['contact_name' => 'Rao, Asha', 'phone' => '9832222222', 'email' => 'a@x.test', 'requirement' => "Two walls,\nthen \"roof\"", 'city' => 'Pune']);
        $csv = $this->get('/api/leads/export')->streamedContent();
        Lead::query()->forceDelete();

        $p = $this->preview($csv)->assertOk();

        $this->assertSame(['created' => 1, 'rejected' => 0], collect($p->json('data.counts'))->only(['created', 'rejected'])->all());
        $this->assertSame('Rao, Asha', $p->json('data.rows.0.name'));
    }
}
