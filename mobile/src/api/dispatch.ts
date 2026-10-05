import { apiClient } from './client';

// Shapes verified against backend/app/Models/Dispatch.php, DispatchItem.php,
// backend/app/Services/DispatchService.php, backend/app/Http/Controllers/Api/DispatchController.php.
//
// Status is a real 2-transition enum (pending -> delivered, or pending -> cancelled) —
// there is no "allocated" state; DispatchService::allocateStockForDispatch() is a
// hard-coded no-op, so the /allocate endpoint and any "Allocate Stock" UI step do
// nothing meaningful (mobile_plan/05_DISPATCH_MODULE.md's mockup predates this).
export type DispatchStatus = 'pending' | 'delivered' | 'cancelled';

export type PanelTypeSummary = { id: number; name: string };

export type DispatchItem = {
  id: number;
  dispatch_id: number;
  panel_type_id: number;
  quantity: string | number;
  unit_price: string | number;
  amount: string | number;
  panel_type?: PanelTypeSummary | null;
};

export type BatchSummary = {
  id: number;
  batch_no: string;
  order_id: number;
};

export type Dispatch = {
  id: number;
  company_id: number;
  batch_id: number;
  dispatch_no: string;
  status: DispatchStatus;
  dispatch_date: string;
  expected_delivery_date: string | null;
  actual_delivery_date: string | null;
  customer_address: string | null;
  tracking_number: string | null;
  notes: string | null;
  batch?: BatchSummary | null;
  items?: DispatchItem[];
};

// DispatchController uses Controller::apiResponse() — {success, data, message},
// no `meta` key (a different envelope than Phase 1's paginatedResponse()).
type ApiEnvelope<T> = { success: boolean; data: T; message: string };

// DispatchService::listDispatches() returns ->paginate() directly, so `data`
// IS the raw Laravel paginator object, not a plain array.
export type LaravelPaginator<T> = {
  current_page: number;
  data: T[];
  total: number;
  per_page: number;
  last_page: number;
};

export async function listDispatches(params: { status?: DispatchStatus; search?: string; per_page?: number; page?: number } = {}) {
  const res = await apiClient.get<ApiEnvelope<LaravelPaginator<Dispatch>>>('/dispatches', { params });
  return res.data.data;
}

export async function getDispatch(id: number) {
  const res = await apiClient.get<ApiEnvelope<Dispatch>>(`/dispatches/${id}`);
  return res.data.data;
}

// Only actual_delivery_date is accepted server-side — no notes/photo field exists
// yet (DispatchController::complete validates just {actual_delivery_date}).
export async function completeDispatch(id: number, actualDeliveryDate?: string) {
  const res = await apiClient.post<ApiEnvelope<Dispatch>>(`/dispatches/${id}/complete`, {
    actual_delivery_date: actualDeliveryDate,
  });
  return res.data.data;
}

// total_amount/total_items are PHP accessors on the Dispatch model, not
// serialized fields (not in $appends) — compute client-side from items.
export function computeDispatchTotal(items: DispatchItem[] | undefined): number {
  return (items ?? []).reduce((sum, item) => sum + Number(item.amount || 0), 0);
}

export function computeDispatchQty(items: DispatchItem[] | undefined): number {
  return (items ?? []).reduce((sum, item) => sum + Number(item.quantity || 0), 0);
}
