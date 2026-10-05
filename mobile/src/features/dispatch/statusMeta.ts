import { PillVariant } from '../../design-system/tokens';
import { DispatchStatus } from '../../api/dispatch';

export const dispatchStatusMeta: Record<DispatchStatus, { label: string; variant: PillVariant }> = {
  pending: { label: 'Pending', variant: 'warning' },
  delivered: { label: 'Delivered', variant: 'success' },
  cancelled: { label: 'Cancelled', variant: 'neutral' },
};

export const dispatchStatusFilters: { key: DispatchStatus | 'all'; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'pending', label: 'Pending' },
  { key: 'delivered', label: 'Delivered' },
  { key: 'cancelled', label: 'Cancelled' },
];
