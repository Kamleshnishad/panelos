import { apiClient } from './client';

// Shapes verified against backend/app/Models/QualityControl.php and
// backend/app/Services/QualityControlService.php. There is NO checklist_data
// column — QC is a single overall pass/fail verdict, not an item checklist
// (mobile_plan/04_PRODUCTION_MODULE.md's checklist description predates this check).
export type QcStatus = 'pass' | 'fail';

export type QualityControl = {
  id: number;
  batch_id: number;
  company_id: number;
  status: QcStatus;
  inspected_by_user_id: number;
  inspected_at: string;
  approved_by_user_id: number | null;
  approved_at: string | null;
  notes: string | null;
  inspected_by_user?: { id: number; name: string } | null;
  approved_by_user?: { id: number; name: string } | null;
};

type ApiEnvelope<T> = { success: boolean; message: string; data: T };

export async function getBatchQc(batchId: number): Promise<QualityControl | null> {
  try {
    const res = await apiClient.get<ApiEnvelope<QualityControl>>(`/batches/${batchId}/qc`);
    return res.data.data;
  } catch (e: any) {
    if (e?.response?.status === 404) return null;
    throw e;
  }
}

export async function createBatchQc(batchId: number, payload: { status: QcStatus; notes?: string }) {
  const res = await apiClient.post<ApiEnvelope<QualityControl>>(`/batches/${batchId}/qc`, payload);
  return res.data.data;
}

// Server rejects approving a 'fail' QC (rework required, not approval) —
// backend/app/Services/QualityControlService.php:76-78 — surface that error, don't
// hide the Approve action only to have it silently no-op.
export async function approveQc(qcId: number, notes?: string) {
  const res = await apiClient.post<ApiEnvelope<QualityControl>>(`/quality-control/${qcId}/approve`, { notes });
  return res.data.data;
}
