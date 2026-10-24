<?php

namespace Tests\Feature;

use App\Models\BatchStageLog;
use App\Models\Customer;
use App\Models\Dispatch;
use App\Models\Order;
use App\Models\ProductionBatch;
use App\Models\ProductionStage;
use App\Models\Quotation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * mobile_plan/09_BACKEND_CHANGES_REQUIRED.md §1 — unblocks mobile Phase 1.5
 * (QC/stage photos) and Phase 2.3 (delivery proof).
 */
class AttachmentUploadTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function makeBatch($companyId): ProductionBatch
    {
        $customer = Customer::factory()->create(['company_id' => $companyId]);
        $quotation = Quotation::create([
            'company_id' => $companyId, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-ATT-' . uniqid(), 'status' => 'accepted',
        ]);
        $order = Order::create([
            'company_id' => $companyId, 'quotation_id' => $quotation->id,
            'customer_id' => $customer->id, 'order_no' => 'ORD-ATT-' . uniqid(),
            'order_date' => now(), 'status' => 'pending',
        ]);
        return ProductionBatch::create([
            'company_id' => $companyId, 'order_id' => $order->id,
            'batch_no' => 'PB-ATT-' . uniqid(), 'status' => 'in_progress',
            'planned_quantity' => 10, 'completed_quantity' => 0,
        ]);
    }

    public function test_a_stage_photo_can_be_uploaded_and_listed(): void
    {
        Storage::fake('public');
        $admin = $this->createAdminUser();
        $batch = $this->makeBatch($admin->company_id);
        $stage = ProductionStage::firstOrCreate(['company_id' => $admin->company_id, 'sequence' => 1], ['name' => 'Lamination', 'is_active' => true]);
        $log = BatchStageLog::create(['batch_id' => $batch->id, 'stage_id' => $stage->id, 'status' => 'in_progress', 'started_at' => now()]);

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/batches/{$batch->id}/stages/{$stage->id}/attachments",
            ['type' => 'stage_photo', 'photo' => UploadedFile::fake()->image('stage.jpg')]
        );

        $response->assertStatus(201);
        $path = $response->json('data.file_path');
        Storage::disk('public')->assertExists($path);

        $list = $this->actingAs($admin, 'sanctum')->getJson("/api/batches/{$batch->id}/stages/{$stage->id}/attachments");
        $list->assertStatus(200);
        $this->assertCount(1, $list->json('data'));
        $this->assertSame($log->id, $log->attachments()->first()->attachable_id);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        Storage::fake('public');
        $admin = $this->createAdminUser();
        $batch = $this->makeBatch($admin->company_id);
        $stage = ProductionStage::firstOrCreate(['company_id' => $admin->company_id, 'sequence' => 1], ['name' => 'Lamination', 'is_active' => true]);
        BatchStageLog::create(['batch_id' => $batch->id, 'stage_id' => $stage->id, 'status' => 'in_progress', 'started_at' => now()]);

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/batches/{$batch->id}/stages/{$stage->id}/attachments",
            ['type' => 'stage_photo', 'photo' => UploadedFile::fake()->create('doc.pdf', 100)]
        );

        $response->assertStatus(422);
    }

    public function test_a_delivery_proof_photo_can_be_uploaded_against_a_dispatch(): void
    {
        Storage::fake('public');
        $admin = $this->createAdminUser();
        $batch = $this->makeBatch($admin->company_id);
        $dispatch = Dispatch::create([
            'company_id' => $admin->company_id, 'batch_id' => $batch->id,
            'dispatch_no' => 'DSP-ATT-' . uniqid(), 'status' => 'pending', 'dispatch_date' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/dispatches/{$dispatch->id}/attachments",
            ['type' => 'delivery_proof', 'photo' => UploadedFile::fake()->image('proof.jpg')]
        );

        $response->assertStatus(201);
        Storage::disk('public')->assertExists($response->json('data.file_path'));

        $list = $this->actingAs($admin, 'sanctum')->getJson("/api/dispatches/{$dispatch->id}/attachments");
        $list->assertStatus(200);
        $this->assertCount(1, $list->json('data'));
    }

    public function test_production_role_can_upload_a_stage_photo_but_viewer_cannot(): void
    {
        Storage::fake('public');
        $result = app(\App\Services\TenantProvisioningService::class)->provision([
            'company_name' => 'Attachment Test Co ' . uniqid(),
            'name' => 'Admin ' . uniqid(), 'email' => 'admin' . uniqid() . '@example.com', 'password' => 'Password123',
        ]);
        $companyId = $result['company']->id;
        $batch = $this->makeBatch($companyId);
        // A provisioned tenant already has its default production stages; use the first one.
        $stage = \App\Models\ProductionStage::where('company_id', $companyId)->orderBy('sequence')->firstOrFail();
        BatchStageLog::create(['batch_id' => $batch->id, 'stage_id' => $stage->id, 'status' => 'in_progress', 'started_at' => now()]);

        $productionRole = \App\Models\Role::where('company_id', $companyId)->where('name', 'Production')->firstOrFail();
        $viewerRole = \App\Models\Role::where('company_id', $companyId)->where('name', 'Viewer')->firstOrFail();
        $productionUser = \App\Models\User::factory()->create(['company_id' => $companyId, 'role_id' => $productionRole->id, 'is_active' => true]);
        $viewerUser = \App\Models\User::factory()->create(['company_id' => $companyId, 'role_id' => $viewerRole->id, 'is_active' => true]);

        $this->actingAs($productionUser, 'sanctum')->postJson(
            "/api/batches/{$batch->id}/stages/{$stage->id}/attachments",
            ['type' => 'stage_photo', 'photo' => UploadedFile::fake()->image('stage.jpg')]
        )->assertStatus(201);

        $this->actingAs($viewerUser, 'sanctum')->postJson(
            "/api/batches/{$batch->id}/stages/{$stage->id}/attachments",
            ['type' => 'stage_photo', 'photo' => UploadedFile::fake()->image('stage2.jpg')]
        )->assertStatus(403);
    }

    public function test_cannot_upload_against_another_companys_dispatch(): void
    {
        Storage::fake('public');
        $admin = $this->createAdminUser();
        $otherAdmin = $this->createAdminUser();
        $batch = $this->makeBatch($otherAdmin->company_id);
        $dispatch = Dispatch::create([
            'company_id' => $otherAdmin->company_id, 'batch_id' => $batch->id,
            'dispatch_no' => 'DSP-ATT-' . uniqid(), 'status' => 'pending', 'dispatch_date' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/dispatches/{$dispatch->id}/attachments",
            ['type' => 'delivery_proof', 'photo' => UploadedFile::fake()->image('proof.jpg')]
        );

        $response->assertStatus(404);
    }
}
