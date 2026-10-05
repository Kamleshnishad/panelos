import { apiClient } from './client';
import { CustomerSummary } from './quotations';

// Shapes verified against backend/app/Http/Controllers/Api/OrderController.php,
// backend/app/Services/OrderService.php. No /orders/{id}/production-status
// endpoint exists — getDetails() already eager-loads `batches`, so production
// status is just order.batches[].status, no extra call needed.
export type OrderStatus = 'pending' | 'in_production' | 'completed' | 'cancelled';

export type OrderBatchSummary = {
  id: number;
  batch_no: string;
  status: string;
};

export type Order = {
  id: number;
  company_id: number;
  order_no: string;
  status: OrderStatus;
  customer_id: number;
  project_name: string | null;
  project_location: string | null;
  total_amount: string | number;
  expected_delivery_date: string | null;
  notes: string | null;
  customer?: CustomerSummary | null;
  batches?: OrderBatchSummary[];
};

type ApiEnvelope<T> = { success: boolean; message: string; data: T };
type PaginatedEnvelope<T> = { success: boolean; message: string; data: T[]; meta: { pagination: { total: number; count: number; per_page: number; current_page: number } } };

export async function listOrders(params: { status?: OrderStatus; search?: string; per_page?: number; page?: number } = {}) {
  const res = await apiClient.get<PaginatedEnvelope<Order>>('/orders', { params });
  return res.data;
}

export async function getOrder(id: number) {
  const res = await apiClient.get<ApiEnvelope<Order>>(`/orders/${id}`);
  return res.data.data;
}
