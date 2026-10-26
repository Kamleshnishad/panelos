<?php

namespace Tests\Feature;

use App\Models\ProductionBatch;
use App\Models\ProductionCutLine;
use App\Models\ProductionRejection;
use App\Models\WasteReasonCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** WR-2: rejected panels are records; a cut line's rejected NOS is the sum of its open records. */
class WasteRejectionWr2Test extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    /** Raise a rejection through the API (shared with the WR-1 test). */
    public static function reject(TestCase $t, ProductionBatch $batch, ProductionCutLine $line, int $nos, string $stage = 'saw', string $code = 'DELAM')
    {
        WasteReasonCode::ensureDefaults($batch->company_id);
        $reason = WasteReasonCode::where('company_id', $batch->company_id)->where('code', $code)->first();

        return $t->postJson("/api/batches/{$batch->id}/rejections", [
            'cut_line_id' => $line->id, 'nos' => $nos, 'reason_code_id' => $reason->id, 'stage_found' => $stage, 'notes' => 'edge',
        ]);
    }

    /** A started batch with one cut line of 3000 mm × 10, all cut. */
    private function runningBatch($admin): array
    {
        $batch = WasteRejectionWr01Test::makeBatch($admin->company_id, [30 => [[3000, 10]]]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/batches/{$batch->id}/start?force=1")->assertStatus(200);
        $line = $batch->fresh()->cutLines->first();
        $this->postJson("/api/batches/{$batch->id}/cut-lines/{$line->id}", ['cut_nos' => 10])->assertStatus(200);
        return [$batch->fresh(), $line->fresh()];
    }

    public function test_rejection_is_recorded_with_reason_and_reduces_good_panels(): void
    {
        [$batch, $line] = $this->runningBatch($this->createAdminUser());

        self::reject($this, $batch, $line, 3)->assertStatus(201)
            ->assertJsonPath('data.nos', 3)->assertJsonPath('data.panel_length_mm', 3000)
            ->assertJsonPath('data.stage_found', 'saw')->assertJsonPath('data.reason.code', 'DELAM')
            ->assertJsonPath('data.status', 'open');
        $this->assertEquals(9.0, (float) ProductionRejection::first()->sqm);
        $this->assertSame(3, $line->fresh()->rejected_nos);
        $this->assertSame(7, $line->fresh()->good_nos);

        // cannot reject more than the good panels left
        self::reject($this, $batch, $line, 8)->assertStatus(400);
        $this->getJson("/api/batches/{$batch->id}/rejections")->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_rejection_needs_an_active_panel_reason_of_this_tenant(): void
    {
        $admin = $this->createAdminUser();
        [$batch, $line] = $this->runningBatch($admin);
        WasteReasonCode::ensureDefaults($admin->company_id);
        $scrap = WasteReasonCode::where('company_id', $admin->company_id)->where('code', 'CHANGEOVER')->first();
        $delam = WasteReasonCode::where('company_id', $admin->company_id)->where('code', 'DELAM')->first();

        $post = fn ($reasonId, $stage = 'saw') => $this->postJson("/api/batches/{$batch->id}/rejections",
            ['cut_line_id' => $line->id, 'nos' => 1, 'reason_code_id' => $reasonId, 'stage_found' => $stage]);

        $post($scrap->id)->assertStatus(400);           // process scrap is not a panel rejection
        $post($delam->id, 'moon')->assertStatus(422);   // unknown stage
        $delam->update(['is_active' => false]);
        $post($delam->id)->assertStatus(400);           // switched off

        $other = $this->createAdminUser();
        WasteReasonCode::ensureDefaults($other->company_id);
        $foreign = WasteReasonCode::where('company_id', $other->company_id)->where('code', 'VOID')->first();
        $post($foreign->id)->assertStatus(400);         // another tenant's reason
        $this->assertSame(0, $line->fresh()->rejected_nos);

        // another tenant cannot see this batch's rejections
        $this->actingAs($other, 'sanctum')->getJson("/api/batches/{$batch->id}/rejections")->assertStatus(404);
    }

    public function test_voiding_a_rejection_returns_the_panels_to_good(): void
    {
        [$batch, $line] = $this->runningBatch($this->createAdminUser());
        $id = self::reject($this, $batch, $line, 2)->json('data.id');

        $this->postJson("/api/batches/{$batch->id}/rejections/{$id}/void", [])->assertStatus(422); // reason required
        $this->postJson("/api/batches/{$batch->id}/rejections/{$id}/void", ['reason' => 'Typed on the wrong line'])
            ->assertStatus(200)->assertJsonPath('data.status', 'void');
        $this->assertSame(0, $line->fresh()->rejected_nos);
        $this->postJson("/api/batches/{$batch->id}/rejections/{$id}/void", ['reason' => 'again'])->assertStatus(400);
    }

    public function test_cut_cannot_go_below_rejected(): void
    {
        [$batch, $line] = $this->runningBatch($this->createAdminUser());
        self::reject($this, $batch, $line, 4)->assertStatus(201);

        $this->postJson("/api/batches/{$batch->id}/cut-lines/{$line->id}", ['cut_nos' => 3])->assertStatus(400);
        $this->postJson("/api/batches/{$batch->id}/cut-lines/{$line->id}", ['cut_nos' => 14])->assertStatus(200)
            ->assertJsonPath('data.good_nos', 10);
    }

    public function test_qc_records_measurements_and_rejections_and_updates_output(): void
    {
        $admin = $this->createAdminUser();
        [$batch, $line] = $this->runningBatch($admin);
        $this->postJson("/api/batches/{$batch->id}/complete")->assertStatus(200);
        $this->assertEquals(30, (float) $batch->fresh()->completed_quantity);
        WasteReasonCode::ensureDefaults($admin->company_id);
        $scratch = WasteReasonCode::where('company_id', $admin->company_id)->where('code', 'SCRATCH')->first();

        $this->postJson("/api/batches/{$batch->id}/qc", [
            'status' => 'fail',
            'measurements' => ['thickness_mm' => 29.4, 'density_kgm3' => 38.5],
            'rejections' => [['cut_line_id' => $line->id, 'nos' => 2, 'reason_code_id' => $scratch->id]],
        ])->assertStatus(201)->assertJsonPath('data.measurements.thickness_mm', 29.4);

        $this->assertSame('qc_failed', $batch->fresh()->status);
        $rej = ProductionRejection::first();
        $this->assertSame('qc', $rej->stage_found);
        $this->assertNotNull($rej->quality_control_id);
        $this->assertSame(8, $line->fresh()->good_nos);
        $this->assertEquals(24, (float) $batch->fresh()->completed_quantity); // 8 × 3.0 m²

        // a bad rejection rolls the whole inspection back
        $this->postJson("/api/batches/{$batch->id}/qc", [
            'status' => 'pass',
            'rejections' => [['cut_line_id' => $line->id, 'nos' => 99, 'reason_code_id' => $scratch->id]],
        ])->assertStatus(400);
        $this->assertSame('qc_failed', $batch->fresh()->status);
        $this->assertSame(1, \App\Models\QualityControl::where('batch_id', $batch->id)->count());
    }

    public function test_store_rejection_after_qc_pass_with_photo(): void
    {
        Storage::fake('public');
        $admin = $this->createAdminUser();
        [$batch, $line] = $this->runningBatch($admin);
        $this->postJson("/api/batches/{$batch->id}/complete")->assertStatus(200);
        $this->postJson("/api/batches/{$batch->id}/qc", ['status' => 'pass'])->assertStatus(201);
        WasteReasonCode::ensureDefaults($admin->company_id);
        $dent = WasteReasonCode::where('company_id', $admin->company_id)->where('code', 'DENT')->first();

        $res = $this->post("/api/batches/{$batch->id}/rejections", [
            'cut_line_id' => $line->id, 'nos' => 1, 'reason_code_id' => $dent->id, 'stage_found' => 'store',
            'photo' => UploadedFile::fake()->image('dent.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertSame('rejection_photo', $res->json('data.attachments.0.type'));
        Storage::disk('public')->assertExists($res->json('data.attachments.0.file_path'));
        $this->assertEquals(27, (float) $batch->fresh()->completed_quantity);
    }

    public function test_draft_batch_cannot_take_rejections(): void
    {
        $admin = $this->createAdminUser();
        $batch = WasteRejectionWr01Test::makeBatch($admin->company_id, [30 => [[3000, 2]]], 'in_progress');
        $line = app(\App\Services\CuttingScheduleService::class)->generateCutLines($batch)->first();
        $batch->update(['status' => 'draft']);

        $this->actingAs($admin, 'sanctum');
        self::reject($this, $batch, $line, 1)->assertStatus(400);
    }
}
