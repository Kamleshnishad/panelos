import { apiClient } from './client';
import { CustomerSummary } from './quotations';

// Shape verified against backend/app/Services/InvoiceService.php::getInvoiceDetails
// (returns the bare Eloquent model — the reliable payment totals come from
// api/payments.ts's getPaymentStatus, not these subtotal/tax_amount/total_amount
// columns, per Phase 3 plan Correction #5).
export type InvoiceItem = {
  id: number;
  invoice_id: number;
  panel_type_id: number | null;
  description: string | null;
  quantity: string | number;
  unit_price: string | number;
  amount: string | number;
  panel_type?: { id: number; name: string } | null;
};

export type Invoice = {
  id: number;
  company_id: number;
  invoice_no: string;
  status: string;
  invoice_date: string;
  due_date: string | null;
  notes: string | null;
  order?: { id: number; order_no: string; customer?: CustomerSummary | null } | null;
  dispatch?: { id: number; batch?: { id: number; batch_no: string; order?: { customer?: CustomerSummary | null } | null } | null } | null;
  items?: InvoiceItem[];
};

type ApiEnvelope<T> = { success: boolean; data: T };

export async function getInvoice(id: number) {
  const res = await apiClient.get<ApiEnvelope<Invoice>>(`/invoices/${id}`);
  return res.data.data;
}
