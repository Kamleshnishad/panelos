import { apiClient } from './client';

// Shape verified against backend/app/Services/ReportingService.php::getAccountsReceivable —
// a single, already-flattened endpoint (not paginated, no Laravel paginator here).
// This effectively doubles as the invoice list for the Sales & Accounts module.
export type ArDetailRow = {
  invoice_id: number;
  invoice_no: string;
  customer_name: string;
  invoice_date: string;
  due_date: string | null;
  total_amount: number;
  paid_amount: number;
  remaining_due: number;
  status: string;
  days_overdue: number;
  is_overdue: boolean;
};

export type ArSummary = {
  total_ar: number;
  current: number;
  '30_days': number;
  '60_days': number;
  '90_days': number;
  over_90_days: number;
  invoice_count: number;
  overdue_count: number;
};

export type AccountsReceivable = {
  summary: ArSummary;
  details: ArDetailRow[];
};

type ApiEnvelope<T> = { success: boolean; data: T };

export async function getAccountsReceivable(asOf?: string) {
  const res = await apiClient.get<ApiEnvelope<AccountsReceivable>>('/reports/accounts-receivable', {
    params: asOf ? { as_of: asOf } : {},
  });
  return res.data.data;
}
