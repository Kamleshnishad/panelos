import { apiClient } from './client';
import { ArSummary } from './reports';

// Shape verified against backend/app/Services/DashboardService.php::getDashboard
// (cached 60s per company server-side).
export type DashboardKpis = {
  open_quotations: number;
  active_orders: number;
  batches_in_production: number;
  pending_dispatch: number;
  outstanding_amount: number;
  collected_fy: number;
  quotations_this_week: number;
  orders_overdue: number;
  batches_on_schedule: number;
  dispatch_ready: number;
  overdue_invoice_count: number;
  collected_yoy_pct: number | null;
};

export type DashboardPipeline = {
  quotations: { draft: number; sent: number; accepted: number };
  orders: { pending: number; in_production: number; completed: number };
  batches: { in_progress: number; qc_pending: number; dispatched: number };
  invoices: { sent: number; accepted: number; paid: number };
};

export type DashboardAlert = {
  type: string;
  severity: 'warning' | 'danger' | 'info';
  message: string;
};

export type DashboardActivity = {
  type: 'quotation' | 'order' | 'dispatch' | 'invoice';
  ref: string;
  status: string;
  amount: number | string | null;
  at: string;
};

export type Dashboard = {
  kpis: DashboardKpis;
  pipeline: DashboardPipeline;
  receivables: ArSummary;
  alerts: DashboardAlert[];
  recent_activity: DashboardActivity[];
};

type ApiEnvelope<T> = { success: boolean; data: T; message: string };

export async function getDashboard() {
  const res = await apiClient.get<ApiEnvelope<Dashboard>>('/dashboard');
  return res.data.data;
}
