import { apiClient } from './client';

// Shapes verified against backend/app/Services/PaymentService.php,
// backend/app/Http/Controllers/Api/PaymentController.php.
export type PaymentMethod = 'bank_transfer' | 'cash' | 'cheque' | 'upi' | 'other';

export type PaymentStatus = {
  invoice_id: number;
  total_amount: number;
  paid_amount: number;
  remaining_due: number;
  payment_percentage: number;
  status: string;
};

type ApiEnvelope<T> = { success: boolean; data: T };
type MessageOnlyEnvelope = { success: boolean; message: string };

export async function getPaymentStatus(invoiceId: number) {
  const res = await apiClient.get<ApiEnvelope<PaymentStatus>>(`/invoices/${invoiceId}/payment-status`);
  return res.data.data;
}

// Server enforces the overpay guard (throws if amount > remaining_due) and
// auto-flips the invoice to 'paid' when remaining hits zero — no client-side
// overpay validation needed beyond a sane positive-number input.
export async function recordPayment(payload: { invoice_id: number; amount: number; payment_method?: PaymentMethod; reference_no?: string }) {
  const res = await apiClient.post<ApiEnvelope<unknown>>('/payments/record', payload);
  return res.data.data;
}

export async function sendPaymentReminder(invoiceId: number) {
  const res = await apiClient.post<MessageOnlyEnvelope>(`/invoices/${invoiceId}/send-payment-reminder`);
  return res.data;
}
