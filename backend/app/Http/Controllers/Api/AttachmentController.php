<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\BatchStageLog;
use App\Models\Dispatch;
use App\Models\ProductionBatch;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Photo/signature attachments for batch stage logs and dispatches.
 * See mobile_plan/09_BACKEND_CHANGES_REQUIRED.md §1.
 */
class AttachmentController extends Controller
{
    use ApiResponse;

    private const IMAGE_RULE = 'required|image|mimes:jpeg,jpg,png,webp|max:5120';

    public function indexForStage(Request $request, int $batchId, int $stageId)
    {
        try {
            ProductionBatch::where('company_id', $request->user()->company_id)->findOrFail($batchId);
            $log = BatchStageLog::where('batch_id', $batchId)->where('stage_id', $stageId)->firstOrFail();

            return $this->successResponse($log->attachments()->with('uploadedBy')->get(), 'Attachments retrieved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(['id' => ['Batch or stage log not found']], 'Batch or stage log not found', 'NOT_FOUND', 404);
        }
    }

    public function storeForStage(Request $request, int $batchId, int $stageId)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)->findOrFail($batchId);
            $log = BatchStageLog::where('batch_id', $batchId)->where('stage_id', $stageId)->firstOrFail();

            $validated = $request->validate([
                'photo' => self::IMAGE_RULE,
                'type' => ['required', Rule::in(['qc_photo', 'stage_photo'])],
            ]);

            $attachment = $this->store($log, $batch->company_id, $validated['type'], $request->file('photo'), $request->user()->id, 'stages');

            return $this->createdResponse($attachment, 'Attachment uploaded', 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(['id' => ['Batch or stage log not found']], 'Batch or stage log not found', 'NOT_FOUND', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'ATTACHMENT_UPLOAD_ERROR', 400);
        }
    }

    public function indexForDispatch(Request $request, int $dispatchId)
    {
        try {
            $dispatch = Dispatch::where('company_id', $request->user()->company_id)->findOrFail($dispatchId);

            return $this->successResponse($dispatch->attachments()->with('uploadedBy')->get(), 'Attachments retrieved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(['id' => ['Dispatch not found']], 'Dispatch not found', 'NOT_FOUND', 404);
        }
    }

    public function storeForDispatch(Request $request, int $dispatchId)
    {
        try {
            $dispatch = Dispatch::where('company_id', $request->user()->company_id)->findOrFail($dispatchId);

            $validated = $request->validate([
                'photo' => self::IMAGE_RULE,
                'type' => ['required', Rule::in(['delivery_proof', 'signature'])],
            ]);

            $attachment = $this->store($dispatch, $dispatch->company_id, $validated['type'], $request->file('photo'), $request->user()->id, 'dispatches');

            return $this->createdResponse($attachment, 'Attachment uploaded', 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(['id' => ['Dispatch not found']], 'Dispatch not found', 'NOT_FOUND', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'ATTACHMENT_UPLOAD_ERROR', 400);
        }
    }

    private function store($attachable, int $companyId, string $type, $file, int $userId, string $folder): Attachment
    {
        $path = $file->store("attachments/{$folder}/{$companyId}", 'public');

        return $attachable->attachments()->create([
            'company_id' => $companyId,
            'type' => $type,
            'file_path' => $path,
            'uploaded_by' => $userId,
        ]);
    }
}
