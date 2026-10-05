import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as quotationsApi from '../../api/quotations';
import * as ordersApi from '../../api/orders';
import * as reportsApi from '../../api/reports';
import * as invoicesApi from '../../api/invoices';
import * as paymentsApi from '../../api/payments';

export function useQuotations(status?: quotationsApi.QuotationStatus) {
  return useQuery({
    queryKey: ['quotations', status ?? 'all'],
    queryFn: () => quotationsApi.listQuotations(status ? { status } : {}),
  });
}

export function useQuotation(id: number) {
  return useQuery({ queryKey: ['quotation', id], queryFn: () => quotationsApi.getQuotation(id) });
}

function useQuotationAction(id: number, fn: (id: number) => Promise<quotationsApi.Quotation>) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => fn(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['quotation', id] });
      queryClient.invalidateQueries({ queryKey: ['quotations'] });
    },
  });
}

export const useSendQuotation = (id: number) => useQuotationAction(id, quotationsApi.sendQuotation);
export const useAcceptQuotation = (id: number) => useQuotationAction(id, quotationsApi.acceptQuotation);
export const useRejectQuotation = (id: number) => useQuotationAction(id, quotationsApi.rejectQuotation);

export function useOrders(status?: ordersApi.OrderStatus) {
  return useQuery({
    queryKey: ['orders', status ?? 'all'],
    queryFn: () => ordersApi.listOrders(status ? { status } : {}),
  });
}

export function useOrder(id: number) {
  return useQuery({ queryKey: ['order', id], queryFn: () => ordersApi.getOrder(id) });
}

export function useAccountsReceivable() {
  return useQuery({ queryKey: ['accounts-receivable'], queryFn: () => reportsApi.getAccountsReceivable() });
}

export function useInvoice(id: number) {
  return useQuery({ queryKey: ['invoice', id], queryFn: () => invoicesApi.getInvoice(id) });
}

export function usePaymentStatus(id: number) {
  return useQuery({ queryKey: ['invoice', id, 'payment-status'], queryFn: () => paymentsApi.getPaymentStatus(id) });
}

export function useRecordPayment(invoiceId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: { amount: number; payment_method?: paymentsApi.PaymentMethod; reference_no?: string }) =>
      paymentsApi.recordPayment({ invoice_id: invoiceId, ...payload }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoice', invoiceId] });
      queryClient.invalidateQueries({ queryKey: ['invoice', invoiceId, 'payment-status'] });
      queryClient.invalidateQueries({ queryKey: ['accounts-receivable'] });
    },
  });
}

export function useSendReminder(invoiceId: number) {
  return useMutation({ mutationFn: () => paymentsApi.sendPaymentReminder(invoiceId) });
}
