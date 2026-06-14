<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\Quotation;
use App\Services\AccessoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccessoryController extends Controller
{
    use ApiResponse;

    private AccessoryService $accessoryService;

    public function __construct(AccessoryService $accessoryService)
    {
        $this->accessoryService = $accessoryService;
    }

    /**
     * GET /accessories - List all accessories
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->only(['status', 'search', 'sort_by', 'sort_order']);

            $accessories = $this->accessoryService->list(
                $request->user()->company_id,
                $filters
            );

            $perPage = $request->query('per_page', 20);
            $page = $request->query('page', 1);

            $paginated = $accessories->paginate($perPage, ['*'], 'page', $page);

            return $this->paginatedResponse(
                $paginated->items(),
                $paginated,
                'Accessories retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to retrieve accessories',
                'ACCESSORY_LIST_ERROR',
                500
            );
        }
    }

    /**
     * POST /accessories - Create new accessory
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'        => 'required|string|max:255',
                'code'        => ['required', 'string', 'max:50', Rule::unique('accessories', 'code')->where('company_id', $request->user()->company_id)],
                'description' => 'nullable|string|max:1000',
                'unit'        => 'nullable|string|max:20',
                'hsn_code'    => 'nullable|string|max:20',
                'rate'        => 'nullable|numeric|min:0',
                'unit_price'  => 'nullable|numeric|min:0',
            ]);
            $validated['unit']       = $validated['unit'] ?? 'NOS';
            $validated['hsn_code']   = $validated['hsn_code'] ?? '73089090';
            $validated['rate']       = $validated['rate'] ?? $validated['unit_price'] ?? 0;
            $validated['unit_price'] = $validated['unit_price'] ?? $validated['rate'] ?? 0;

            $validated['company_id'] = $request->user()->company_id;

            $accessory = $this->accessoryService->create($validated);

            return $this->createdResponse(
                $accessory,
                'Accessory created successfully',
                201
            );
        } catch (ValidationException $e) {
            return $this->errorResponse(
                $e->errors(),
                'Validation failed',
                'VALIDATION_ERROR',
                422
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to create accessory',
                'ACCESSORY_CREATE_ERROR',
                500
            );
        }
    }

    /**
     * GET /accessories/{id} - Get accessory details
     */
    public function show(Request $request, int $id)
    {
        try {
            $accessory = Accessory::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            return $this->successResponse(
                $accessory,
                'Accessory retrieved successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Accessory not found']],
                'Accessory not found',
                'NOT_FOUND',
                404
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to retrieve accessory',
                'ACCESSORY_SHOW_ERROR',
                500
            );
        }
    }

    /**
     * PUT /accessories/{id} - Update accessory
     */
    public function update(Request $request, int $id)
    {
        try {
            $accessory = Accessory::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            $validated = $request->validate([
                'name'        => 'sometimes|required|string|max:255',
                'code'        => ['sometimes', 'required', 'string', 'max:50', Rule::unique('accessories', 'code')->where('company_id', $request->user()->company_id)->ignore($id)],
                'description' => 'nullable|string|max:1000',
                'unit'        => 'nullable|string|max:20',
                'hsn_code'    => 'nullable|string|max:20',
                'rate'        => 'nullable|numeric|min:0',
                'unit_price'  => 'nullable|numeric|min:0',
                'is_active'   => 'sometimes|boolean',
            ]);

            $accessory = $this->accessoryService->update($accessory, $validated);

            return $this->successResponse(
                $accessory,
                'Accessory updated successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Accessory not found']],
                'Accessory not found',
                'NOT_FOUND',
                404
            );
        } catch (ValidationException $e) {
            return $this->errorResponse(
                $e->errors(),
                'Validation failed',
                'VALIDATION_ERROR',
                422
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to update accessory',
                'ACCESSORY_UPDATE_ERROR',
                500
            );
        }
    }

    /**
     * DELETE /accessories/{id} - Delete accessory
     */
    public function destroy(Request $request, int $id)
    {
        try {
            $accessory = Accessory::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            $this->accessoryService->delete($accessory);

            return $this->noContentResponse(
                'Accessory deleted successfully'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Accessory not found']],
                'Accessory not found',
                'NOT_FOUND',
                404
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to delete accessory',
                'ACCESSORY_DELETE_ERROR',
                400
            );
        }
    }

    /**
     * POST /accessories/{id}/image - Upload accessory image
     */
    public function uploadImage(Request $request, int $id)
    {
        try {
            $companyId = $request->user()->company_id;
            $accessory = Accessory::where('company_id', $companyId)->findOrFail($id);

            $request->validate(['image' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:3072']);

            if ($accessory->image && \Illuminate\Support\Facades\Storage::disk('public')->exists($accessory->image)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($accessory->image);
            }
            $path = $request->file('image')->store("accessories/{$companyId}", 'public');
            $accessory->update(['image' => $path]);

            return $this->successResponse($accessory->fresh(), 'Image uploaded');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'IMAGE_UPLOAD_ERROR', 400);
        }
    }

    /**
     * POST /quotations/{id}/accessories - Add accessory to quotation
     */
    public function addToQuotation(Request $request, int $quotationId)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)
                ->findOrFail($quotationId);

            if ($quotation->status !== 'draft') {
                return $this->errorResponse(
                    ['status' => ['Can only add accessories to draft quotations']],
                    'Invalid quotation status',
                    'INVALID_STATUS',
                    400
                );
            }

            $validated = $request->validate([
                'accessory_id' => 'required|exists:accessories,id',
                'quantity' => 'required|numeric|min:0.1',
                'unit_price' => 'nullable|numeric|min:0',
            ]);

            $this->accessoryService->addToQuotation($quotation, $validated);

            return $this->successResponse(
                $quotation->fresh(['items', 'accessories']),
                'Accessory added to quotation'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Quotation not found']],
                'Quotation not found',
                'NOT_FOUND',
                404
            );
        } catch (ValidationException $e) {
            return $this->errorResponse(
                $e->errors(),
                'Validation failed',
                'VALIDATION_ERROR',
                422
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to add accessory to quotation',
                'ACCESSORY_ADD_ERROR',
                500
            );
        }
    }

    /**
     * DELETE /quotations/{id}/accessories/{accessoryId} - Remove accessory from quotation
     */
    public function removeFromQuotation(Request $request, int $quotationId, int $accessoryId)
    {
        try {
            $quotation = Quotation::where('company_id', $request->user()->company_id)
                ->findOrFail($quotationId);

            if ($quotation->status !== 'draft') {
                return $this->errorResponse(
                    ['status' => ['Can only remove accessories from draft quotations']],
                    'Invalid quotation status',
                    'INVALID_STATUS',
                    400
                );
            }

            $this->accessoryService->removeFromQuotation($quotation, $accessoryId);

            return $this->successResponse(
                $quotation->fresh(['items', 'accessories']),
                'Accessory removed from quotation'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                ['id' => ['Quotation not found']],
                'Quotation not found',
                'NOT_FOUND',
                404
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                ['error' => $e->getMessage()],
                'Failed to remove accessory from quotation',
                'ACCESSORY_REMOVE_ERROR',
                500
            );
        }
    }
}
