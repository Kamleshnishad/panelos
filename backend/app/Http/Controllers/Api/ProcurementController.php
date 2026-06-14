<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\ProcurementService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProcurementController extends Controller
{
    use ApiResponse;

    public function __construct(private ProcurementService $proc) {}

    private function cid(Request $r) { return $r->user()->company_id; }

    // Suppliers
    public function suppliers(Request $r)
    {
        return $this->successResponse($this->proc->listSuppliers($this->cid($r)), 'Suppliers retrieved');
    }

    public function storeSupplier(Request $r)
    {
        try {
            $data = $r->validate([
                'name'    => 'required|string|max:150',
                'phone'   => 'nullable|string|max:30',
                'gstin'   => 'nullable|string|max:20',
                'email'   => 'nullable|email|max:120',
                'address' => 'nullable|string|max:255',
            ]);
            return $this->createdResponse($this->proc->createSupplier($this->cid($r), $data), 'Supplier created', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        }
    }

    public function updateSupplier(Request $r, int $id)
    {
        try {
            $sup = \App\Models\Supplier::where('company_id', $this->cid($r))->findOrFail($id);
            $data = $r->validate([
                'name'    => 'sometimes|required|string|max:150',
                'phone'   => 'nullable|string|max:30',
                'gstin'   => 'nullable|string|max:20',
                'email'   => 'nullable|email|max:120',
                'address' => 'nullable|string|max:255',
            ]);
            return $this->successResponse($this->proc->updateSupplier($sup, $data), 'Supplier updated');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Supplier not found', 'NOT_FOUND', 404);
        }
    }

    public function purchasable(Request $r)
    {
        return $this->successResponse($this->proc->purchasableItems($this->cid($r)), 'Purchasable items retrieved');
    }

    // Purchase Orders
    public function index(Request $r)
    {
        return $this->successResponse($this->proc->listPurchaseOrders($this->cid($r), $r->only('status')), 'POs retrieved');
    }

    public function show(Request $r, int $id)
    {
        try {
            $po = PurchaseOrder::where('company_id', $this->cid($r))->findOrFail($id);
            return $this->successResponse($this->proc->getPurchaseOrder($po), 'PO retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse([], 'PO not found', 'NOT_FOUND', 404);
        }
    }

    public function store(Request $r)
    {
        try {
            $data = $r->validate([
                'supplier_id'          => 'nullable|integer|exists:suppliers,id',
                'order_date'           => 'nullable|date',
                'expected_date'        => 'nullable|date',
                'tax_pct'              => 'nullable|numeric|min:0|max:100',
                'notes'                => 'nullable|string|max:1000',
                'items'                => 'required|array|min:1',
                'items.*.material_kind'=> 'required|in:coil,chemical,consumable',
                'items.*.stock_id'     => 'required|integer',
                'items.*.item_name'    => 'nullable|string|max:150',
                'items.*.unit'         => 'nullable|string|max:20',
                'items.*.quantity'     => 'required|numeric|min:0.01',
                'items.*.rate'         => 'nullable|numeric|min:0',
            ]);
            return $this->createdResponse($this->proc->createPurchaseOrder($this->cid($r), $data), 'PO created', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'CREATE_ERROR', 400);
        }
    }

    public function receive(Request $r, int $id)
    {
        try {
            $po = PurchaseOrder::where('company_id', $this->cid($r))->findOrFail($id);
            $data = $r->validate([
                'receipts'                  => 'required|array|min:1',
                'receipts.*.po_item_id'     => 'required|integer',
                'receipts.*.received_qty'   => 'required|numeric|min:0',
                'receipts.*.cost'           => 'nullable|numeric|min:0',
                'receipts.*.batch_no'       => 'nullable|string|max:100',
                'receipts.*.expiry_date'    => 'nullable|date',
            ]);
            return $this->successResponse($this->proc->receive($po, $data['receipts']), 'Goods received');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'RECEIVE_ERROR', 400);
        }
    }

    public function cancel(Request $r, int $id)
    {
        try {
            $po = PurchaseOrder::where('company_id', $this->cid($r))->findOrFail($id);
            $this->proc->cancel($po);
            return $this->successResponse($po->fresh(), 'PO cancelled');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'CANCEL_ERROR', 400);
        }
    }

    public function valuation(Request $r)
    {
        if (!$r->user()->canViewCost()) {
            return $this->successResponse(['masked' => true, 'total' => null, 'coil' => null, 'chemical' => null, 'consumable' => null], 'Stock valuation (restricted)');
        }
        return $this->successResponse($this->proc->stockValuation($this->cid($r)), 'Stock valuation computed');
    }
}
