import { PillVariant } from '../../design-system/tokens';
import { QuotationStatus } from '../../api/quotations';
import { OrderStatus } from '../../api/orders';

export const quotationStatusMeta: Record<QuotationStatus, { label: string; variant: PillVariant }> = {
  boq: { label: 'BOQ', variant: 'neutral' },
  draft: { label: 'Draft', variant: 'neutral' },
  sent: { label: 'Sent', variant: 'accent' },
  accepted: { label: 'Accepted', variant: 'success' },
  rejected: { label: 'Rejected', variant: 'danger' },
  revised: { label: 'Revised', variant: 'warning' },
  expired: { label: 'Expired', variant: 'danger' },
};

export const quotationStatusFilters: { key: QuotationStatus | 'all'; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'draft', label: 'Draft' },
  { key: 'sent', label: 'Sent' },
  { key: 'accepted', label: 'Accepted' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'expired', label: 'Expired' },
];

export const orderStatusMeta: Record<OrderStatus, { label: string; variant: PillVariant }> = {
  pending: { label: 'Pending', variant: 'warning' },
  in_production: { label: 'In Production', variant: 'accent' },
  completed: { label: 'Completed', variant: 'success' },
  cancelled: { label: 'Cancelled', variant: 'neutral' },
};

export const orderStatusFilters: { key: OrderStatus | 'all'; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'pending', label: 'Pending' },
  { key: 'in_production', label: 'In Production' },
  { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' },
];

// Generic mapper for arbitrary batch/production-adjacent status strings shown
// inside Order Detail's linked-batches list (statuses come from ProductionBatch,
// see mobile_plan/04_PRODUCTION_MODULE.md's statusMeta for the authoritative set).
export function genericStatusVariant(status: string): PillVariant {
  const s = status.toLowerCase();
  if (s.includes('fail') || s.includes('cancel')) return 'danger';
  if (s.includes('pending') || s.includes('progress')) return 'warning';
  if (s.includes('pass') || s.includes('complet') || s.includes('deliver')) return 'success';
  return 'neutral';
}
