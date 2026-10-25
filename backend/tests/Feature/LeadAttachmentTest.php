<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAttachment;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadAttachmentService;
use App\Services\LeadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** LEADS_PLAN 5.7: drawings, photos, BOQ sheets and documents kept on a lead, privately, with only real files of known kinds accepted. */
class LeadAttachmentTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true, 'name' => 'Asha']);
        $this->actingAs($this->admin, 'sanctum');
    }

    private function lead(array $o = []): Lead
    {
        static $n = 0;
        $n++;

        return Lead::create($o + ['company_id' => $this->company->id, 'lead_no' => "LD-{$n}", 'contact_name' => 'Ravi', 'source' => 'Phone', 'status' => 'new', 'assigned_to_user_id' => $this->admin->id]);
    }

    /** A small file with the right first bytes for its kind. */
    private function file(string $name, ?string $content = null): UploadedFile
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $content ??= match ($ext) {
            'pdf' => "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF",
            'jpg', 'jpeg' => "\xFF\xD8\xFF\xE0" . str_repeat('x', 100),
            'png' => "\x89PNG\r\n\x1a\n" . str_repeat('x', 100),
            'webp' => 'RIFF' . pack('V', 100) . 'WEBP' . str_repeat('x', 100),
            'xlsx', 'docx' => "PK\x03\x04" . str_repeat('x', 100),
            'xls', 'doc' => "\xD0\xCF\x11\xE0" . str_repeat('x', 100),
            'dwg' => 'AC1027' . str_repeat("\0", 100),
            default => "a,b\n1,2\n",
        };

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function up(Lead $l, UploadedFile $f, array $extra = [])
    {
        return $this->post("/api/leads/{$l->id}/attachments", ['file' => $f] + $extra, ['Accept' => 'application/json']);
    }

    // ── adding ───────────────────────────────────────────────────────────────

    public function test_every_accepted_kind_can_be_added_and_gets_a_sensible_label(): void
    {
        $l = $this->lead();
        $expect = ['plan.pdf' => 'document', 'site.jpg' => 'photo', 'site2.JPEG' => 'photo', 'front.png' => 'photo', 'view.webp' => 'photo', 'layout.dwg' => 'drawing',
            'layout.dxf' => 'drawing', 'boq.xlsx' => 'boq', 'old.xls' => 'boq', 'items.csv' => 'boq', 'brief.docx' => 'document', 'brief.doc' => 'document'];

        foreach ($expect as $name => $kind) {
            $this->up($l, $this->file($name))->assertStatus(201)->assertJsonPath('data.kind', $kind);
        }
        $this->assertSame(count($expect), LeadAttachment::where('lead_id', $l->id)->count());
    }

    public function test_the_file_is_stored_privately_under_a_random_name_and_the_screen_never_sees_the_path(): void
    {
        $l = $this->lead();

        $res = $this->up($l, $this->file('Cold room drawing v3.pdf'))->assertStatus(201);

        $a = LeadAttachment::firstOrFail();
        Storage::disk('local')->assertExists($a->path);
        $this->assertMatchesRegularExpression("#^lead-attachments/{$this->company->id}/{$l->id}/[0-9a-f-]{36}\.pdf$#", $a->path);
        $this->assertStringNotContainsString('path', json_encode($res->json()));
        $this->assertSame('Cold room drawing v3.pdf', $res->json('data.name'));
        $this->assertSame(['Asha', true], [$res->json('data.uploaded_by'), $res->json('data.viewable')]);
        $this->assertStringNotContainsString('lead-attachments', $this->getJson("/api/leads/{$l->id}/attachments")->getContent());
    }

    public function test_the_person_may_choose_the_label_and_a_made_up_one_is_ignored(): void
    {
        $l = $this->lead();

        $this->up($l, $this->file('a.pdf'), ['kind' => 'drawing'])->assertJsonPath('data.kind', 'drawing');
        $this->up($l, $this->file('b.pdf'), ['kind' => 'secret'])->assertJsonPath('data.kind', 'document');
    }

    public function test_adding_and_removing_are_lines_on_the_timeline(): void
    {
        $l = $this->lead();
        $id = $this->up($l, $this->file('plan.pdf'), ['kind' => 'drawing'])->json('data.id');

        $this->assertStringStartsWith('File added: plan.pdf (Drawing, 1 KB)', LeadActivity::where('lead_id', $l->id)->latest('id')->value('description'));
        $this->deleteJson("/api/leads/attachments/{$id}")->assertNoContent();
        $this->assertSame('File removed: plan.pdf', LeadActivity::where('lead_id', $l->id)->latest('id')->value('description'));
    }

    // ── refusing the wrong things ────────────────────────────────────────────

    public function test_kinds_that_could_run_in_a_browser_or_on_a_server_are_refused(): void
    {
        $l = $this->lead();

        foreach (['x.html', 'x.svg', 'x.php', 'x.js', 'x.exe', 'x.zip', 'x.sh', 'x.pdf.exe', 'noextension'] as $name) {
            $this->up($l, $this->file($name, '<script>alert(1)</script>'))->assertStatus(422)->assertJsonValidationErrors('file');
        }
        $this->assertSame(0, LeadAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_file_that_is_not_what_its_name_says_is_refused(): void
    {
        $l = $this->lead();

        foreach (['fake.pdf', 'fake.png', 'fake.jpg', 'fake.xlsx', 'fake.xls', 'fake.webp', 'fake.dwg'] as $name) {
            $res = $this->up($l, $this->file($name, "MZ\x90\x00 this is a program, not a document"))->assertStatus(422);
            $this->assertStringContainsString('does not look like a real', $res->json('message'), $name);
        }
        $this->up($l, $this->file('data.csv', "a\0b"))->assertStatus(422);
        $this->assertSame(0, LeadAttachment::count());
    }

    public function test_empty_and_oversize_files_and_a_missing_file_are_refused(): void
    {
        $l = $this->lead();

        $this->up($l, UploadedFile::fake()->createWithContent('empty.pdf', ''))->assertStatus(422);
        $big = UploadedFile::fake()->create('big.pdf', 15 * 1024 + 1, 'application/pdf');
        $this->up($l, $big)->assertStatus(422);
        $this->post("/api/leads/{$l->id}/attachments", [], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, LeadAttachment::count());
    }

    public function test_a_lead_keeps_at_most_thirty_files(): void
    {
        $l = $this->lead();
        for ($i = 0; $i < LeadAttachmentService::MAX_FILES; $i++) {            // straight into the table: the upload limiter (20 a minute) would stop a loop of requests
            LeadAttachment::create(['company_id' => $this->company->id, 'lead_id' => $l->id, 'kind' => 'boq', 'original_name' => "f{$i}.csv", 'path' => "lead-attachments/x/f{$i}.csv", 'extension' => 'csv', 'size_bytes' => 10]);
        }

        $this->up($l, $this->file('one-too-many.csv'))->assertStatus(422);
        $this->assertSame(LeadAttachmentService::MAX_FILES, LeadAttachment::count());
    }

    public function test_the_name_shown_is_cleaned_but_the_stored_name_is_never_the_users(): void
    {
        $l = $this->lead();

        $id = $this->up($l, UploadedFile::fake()->createWithContent("..\\..\\etc\\pass\"wd<1>.csv", "a,b\n"))->assertStatus(201)->json('data.id');

        $a = LeadAttachment::find($id);
        $this->assertSame('passwd1.csv', $a->original_name);
        $this->assertStringNotContainsString('passwd', $a->path);
        $this->assertStringStartsWith("lead-attachments/{$this->company->id}/{$l->id}/", $a->path);
    }

    // ── reading ──────────────────────────────────────────────────────────────

    public function test_the_list_names_files_newest_first_with_the_limits_for_the_screen(): void
    {
        $l = $this->lead();
        $this->up($l, $this->file('first.csv'));
        $this->up($l, $this->file('second.pdf'));

        $d = $this->getJson("/api/leads/{$l->id}/attachments")->assertOk()->json('data');

        $this->assertSame(['second.pdf', 'first.csv'], array_column($d['files'], 'name'));
        $this->assertSame([15, 30], [$d['max_mb'], $d['max_files']]);
        $this->assertStringContainsString('.dwg', $d['accept']);
        $this->assertStringNotContainsString('.html', $d['accept']);
    }

    public function test_downloading_gives_the_file_with_its_own_name_as_an_attachment_and_never_lets_the_browser_guess(): void
    {
        $l = $this->lead();
        $id = $this->up($l, $this->file('Cold room drawing.pdf'))->json('data.id');

        $res = $this->get("/api/leads/attachments/{$id}")->assertOk();

        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Cold room drawing.pdf', $res->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', $res->streamedContent());
    }

    public function test_only_pdfs_and_pictures_can_be_shown_in_place(): void
    {
        $l = $this->lead();
        $pdf = $this->up($l, $this->file('a.pdf'))->json('data.id');
        $sheet = $this->up($l, $this->file('b.csv'))->json('data.id');

        $this->assertStringContainsString('inline', $this->get("/api/leads/attachments/{$pdf}?inline=1")->headers->get('Content-Disposition'));
        $this->assertStringContainsString('attachment', $this->get("/api/leads/attachments/{$sheet}?inline=1")->headers->get('Content-Disposition'));
    }

    public function test_a_file_that_has_gone_from_the_disk_says_so_instead_of_failing(): void
    {
        $l = $this->lead();
        $id = $this->up($l, $this->file('a.pdf'))->json('data.id');
        Storage::disk('local')->delete(LeadAttachment::find($id)->path);

        $this->get("/api/leads/attachments/{$id}")->assertStatus(404)->assertJsonPath('error_code', 'FILE_MISSING');
    }

    // ── removing ─────────────────────────────────────────────────────────────

    public function test_removing_deletes_the_file_and_deleting_the_lead_deletes_all_its_files(): void
    {
        $l = $this->lead();
        $a = $this->up($l, $this->file('a.pdf'))->json('data.id');
        $this->up($l, $this->file('b.csv'));
        $paths = LeadAttachment::pluck('path', 'id');

        $this->deleteJson("/api/leads/attachments/{$a}")->assertNoContent();
        Storage::disk('local')->assertMissing($paths[$a]);

        app(LeadService::class)->delete($l);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, LeadAttachment::count());
    }

    // ── who may ──────────────────────────────────────────────────────────────

    private function userWith(array $perms): User
    {
        return User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => $perms])->id]);
    }

    public function test_viewing_needs_leads_view_and_adding_or_removing_needs_leads_manage(): void
    {
        $l = $this->lead();
        $id = $this->up($l, $this->file('a.pdf'))->json('data.id');

        $this->actingAs($this->userWith(['leads.view']), 'sanctum');
        $this->getJson("/api/leads/{$l->id}/attachments")->assertOk();
        $this->get("/api/leads/attachments/{$id}")->assertOk();
        $this->up($l, $this->file('b.pdf'))->assertStatus(403);
        $this->deleteJson("/api/leads/attachments/{$id}")->assertStatus(403);

        $this->actingAs($this->userWith([]), 'sanctum');
        $this->getJson("/api/leads/{$l->id}/attachments")->assertStatus(403);
        $this->get("/api/leads/attachments/{$id}")->assertStatus(403);
    }

    public function test_another_company_cannot_list_download_add_or_remove(): void
    {
        $l = $this->lead();
        $id = $this->up($l, $this->file('secret.pdf'))->json('data.id');
        $stranger = User::factory()->create(['company_id' => Company::factory()->create()->id, 'is_company_admin' => true]);
        $this->actingAs($stranger, 'sanctum');

        $this->getJson("/api/leads/{$l->id}/attachments")->assertStatus(404);
        $this->get("/api/leads/attachments/{$id}")->assertStatus(404);
        $this->up($l, $this->file('x.pdf'))->assertStatus(404);
        $this->deleteJson("/api/leads/attachments/{$id}")->assertStatus(404);
        $this->assertSame(1, LeadAttachment::withoutGlobalScope('tenant')->count());
    }

    public function test_a_person_restricted_to_their_own_leads_reaches_only_those_files(): void
    {
        $theirs = $this->lead(['assigned_to_user_id' => $this->admin->id]);
        $id = $this->up($theirs, $this->file('theirs.pdf'))->json('data.id');
        $me = $this->userWith(['leads.view', 'leads.manage', 'leads.own_only']);
        $mine = $this->lead(['assigned_to_user_id' => $me->id]);
        $this->actingAs($me, 'sanctum');
        $mineId = $this->up($mine, $this->file('mine.pdf'))->assertStatus(201)->json('data.id');

        $this->getJson("/api/leads/{$theirs->id}/attachments")->assertStatus(404);
        $this->get("/api/leads/attachments/{$id}")->assertStatus(404);
        $this->deleteJson("/api/leads/attachments/{$id}")->assertStatus(404);
        $this->up($theirs, $this->file('x.pdf'))->assertStatus(404);
        $this->get("/api/leads/attachments/{$mineId}")->assertOk();
    }

    public function test_the_file_is_not_reachable_by_any_public_address(): void
    {
        $l = $this->lead();
        $this->up($l, $this->file('a.pdf'));
        $a = LeadAttachment::firstOrFail();

        $this->assertFalse(Storage::disk('public')->exists($a->path));
        $this->app['auth']->forgetGuards();
        $this->assertContains($this->get('/storage/' . $a->path)->getStatusCode(), [403, 404], 'the framework refuses an unsigned address for a private file');
    }
}
