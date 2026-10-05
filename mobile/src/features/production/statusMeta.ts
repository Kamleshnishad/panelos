import { PillVariant } from '../../design-system/tokens';
import { BatchStatus } from '../../api/production';

export const batchStatusMeta: Record<BatchStatus, { label: string; variant: PillVariant }> = {
  draft: { label: 'Draft', variant: 'neutral' },
  in_progress: { label: 'In Progress', variant: 'accent' },
  qc_pending: { label: 'QC Pending', variant: 'warning' },
  qc_passed: { label: 'QC Passed', variant: 'success' },
  qc_failed: { label: 'QC Failed', variant: 'danger' },
  completed: { label: 'Completed', variant: 'success' },
  cancelled: { label: 'Cancelled', variant: 'neutral' },
};

export const statusFilters: { key: BatchStatus | 'all'; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'in_progress', label: 'In Progress' },
  { key: 'qc_pending', label: 'QC Pending' },
  { key: 'qc_passed', label: 'Passed' },
  { key: 'qc_failed', label: 'Failed' },
];
