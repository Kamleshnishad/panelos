import { apiClient } from './client';

// Shapes verified against backend/app/Models/Quotation.php, backend/app/Services/QuotationService.php,
// backend/app/Http/Controllers/Api/QuotationController.php.
//
// 'boq' = un-priced BOQ before sales enters rates; 'revised' = superseded by a
// newer revision. send() requires 'draft'; accept() requires 'sent';
// reject() requires 'draft' or 'sent' — enforced server-side.
export type QuotationStatus = 'boq' | 'draft' | 'sent' | 'accepted' | 'rejected' | 'revised' | 'expired';

export type CustomerSummary = { id: number; name: string };

export type Quotation = {
  id: number;
  company_id: number;
  quotation_no: string;
  status: QuotationStatus;
  customer_id: number;
  project_name: string | null;
  project_location: string | null;
  quality_grade: string | null;
  valid_until: string | null;
  sent_at: string | null;
  accepted_at: string | null;
  subtotal: string | number;
  discount_amount: string | number;
  taxable_amount: string | number;
  tax_amount: string | number;
  total_amount: string | number;
  advance_amount: string | number;
  balance_amount: string | number;
  notes: string | null;
  customer?: CustomerSummary | null;
};

type ApiEnvelope<T> = { success: boolean; message: string; data: T };
type PaginatedEnvelope<T> = { success: boolean; message: string; data: T[]; meta: { pagination: { total: number; count: number; per_page: number; current_page: number } } };

export async function listQuotations(params: { status?: QuotationStatus; search?: string; per_page?: number; page?: number } = {}) {
  const res = await apiClient.get<PaginatedEnvelope<Quotation>>('/quotations', { params });
  return res.data;
}

export async function getQuotation(id: number) {
  const res = await apiClient.get<ApiEnvelope<Quotation>>(`/quotations/${id}`);
  return res.data.data;
}

export async function sendQuotation(id: number) {
  const res = await apiClient.post<ApiEnvelope<Quotation>>(`/quotations/${id}/send`);
  return res.data.data;
}

export async function acceptQuotation(id: number) {
  const res = await apiClient.post<ApiEnvelope<Quotation>>(`/quotations/${id}/accept`);
  return res.data.data;
}

export async function rejectQuotation(id: number) {
  const res = await apiClient.post<ApiEnvelope<Quotation>>(`/quotations/${id}/reject`);
  return res.data.data;
}
