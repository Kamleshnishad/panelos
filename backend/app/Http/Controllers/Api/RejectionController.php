<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionBatch;
use App\Models\ProductionRejection;
use App\Services\RejectionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** WR-2: rejected panels on a batch — list, raise (with an optional photo), void. */
class RejectionController extends Controller
{
    use ApiResponse;

    public function __construct(private RejectionService $rejections) {}

    public function index(Request $request, int $batchId)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)->findOrFail($batchId);
            return $this->successResponse($this->rejections->listForBatch($batch), 'Rejections retrieved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(['id' => ['Batch not found']], 'Batch not found', 'NOT_FOUND', 404);
        }
    }

    public function store(Request $request, int $batchId)
    {
        try {
            $batch = ProductionBatch::where('company_id', $request->user()->company_id)->findOrFail($batchId);
            $data = $request->validate([
                'cut_line_id'    => 'required|integer',
                'nos'            => 'required|integer|min:1|max:100000',
                'reason_code_id' => 'required|integer',
                'stage_found'    => ['required', Rule::in(ProductionRejection::STAGES)],
                'notes'          => 'nullable|string|max:1000',
                'photo'          => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            ]);

            $rejection = $this->rejections->raise($batch, $data, $request->user()->id);

            if ($request->hasFile('photo')) {
                $rejection->attachments()->create([
                    'company_id'  => $batch->company_id,
                    'type'        => 'rejection_photo',
                    'file_path'   => $request->file('photo')->store("attachments/rejections/{$batch->company_id}", 'public'),
                    'uploaded_by' => $request->user()->id,
                ]);
            }

            return $this->createdResponse($rejection->load('reason:id,code,label,category', 'raisedBy:id,name', 'attachments'), 'Rejection recorded');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(['id' => ['Batch or cut line not found']], 'Not found', 'NOT_FOUND', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'REJECTION_ERROR', 400);
        }
    }

    public function void(Request $request, int $batchId, int $id)
    {
        try {
            ProductionBatch::where('company_id', $request->user()->company_id)->findOrFail($batchId);
            $rejection = ProductionRejection::where('company_id', $request->user()->company_id)
                ->where('batch_id', $batchId)->findOrFail($id);
            $data = $request->validate(['reason' => 'required|string|max:255']);

            return $this->successResponse(
                $this->rejections->void($rejection, $request->user()->id, $data['reason']),
                'Rejection voided'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(['id' => ['Rejection not found']], 'Not found', 'NOT_FOUND', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'REJECTION_VOID_ERROR', 400);
        }
    }
}
