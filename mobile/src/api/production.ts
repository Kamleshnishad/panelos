import { apiClient } from './client';

// Shapes verified against the real backend, not mobile_plan docs:
// backend/app/Models/ProductionBatch.php, BatchStageLog.php, ProductionStage.php,
// backend/app/Services/ProductionBatchService.php, BatchStageLogService.php.

// qc_passed/qc_failed come from QualityControlService::create; 'completed'/'cancelled'
// are not confirmed reachable within this module's scope but are included defensively
// since ProductionBatchController hints at a 'draft' status flow that predates dispatch.
// 'dispatched' is set by Dispatch::markAsCompleted() the moment a dispatch finishes —
// triggered directly by this app's own "Mark Delivered" action (M1 fix).
export type BatchStatus = 'draft' | 'in_progress' | 'qc_pending' | 'qc_passed' | 'qc_failed' | 'completed' | 'cancelled' | 'dispatched';

export type ProductionStage = {
  id: number;
  company_id: number;
  name: string;
  sequence: number;
  description: string | null;
  is_active: boolean;
};

export type StageLogStatus = 'in_progress' | 'completed';

export type BatchStageLog = {
  id: number;
  batch_id: number;
  stage_id: number;
  status: StageLogStatus;
  started_at: string | null;
  completed_at: string | null;
  duration_minutes: number | null;
  notes: string | null;
  logged_by_user_id: number | null;
  stage?: ProductionStage;
  user?: { id: number; name: string } | null;
};

export type OrderSummary = {
  id: number;
  order_no: string;
};

export type Batch = {
  id: number;
  company_id: number;
  order_id: number;
  batch_no: string;
  status: BatchStatus;
  planned_quantity: number;
  completed_quantity: number;
  started_at: string | null;
  completed_at: string | null;
  notes: string | null;
  order?: OrderSummary | null;
  stage_logs?: BatchStageLog[];
};

export type ProgressEntry = {
  stage_id: number;
  stage_name: string;
  status: StageLogStatus | 'pending';
  started_at: string | null;
  completed_at: string | null;
  duration_minutes: number | null;
  notes: string | null;
};

type ApiEnvelope<T> = { success: boolean; message: string; data: T };
type PaginatedEnvelope<T> = { success: boolean; message: string; data: T[]; meta: { pagination: { total: number; count: number; per_page: number; current_page: number } } };

export async function listBatches(params: { status?: BatchStatus; search?: string; per_page?: number; page?: number } = {}) {
  const res = await apiClient.get<PaginatedEnvelope<Batch>>('/batches', { params });
  return res.data;
}

export async function getBatch(id: number) {
  const res = await apiClient.get<ApiEnvelope<Batch>>(`/batches/${id}`);
  return res.data.data;
}

export async function getTimeline(batchId: number) {
  const res = await apiClient.get<ApiEnvelope<BatchStageLog[]>>(`/batches/${batchId}/timeline`);
  return res.data.data;
}

export async function getProgress(batchId: number) {
  const res = await apiClient.get<ApiEnvelope<ProgressEntry[]>>(`/batches/${batchId}/progress`);
  return res.data.data;
}

export async function listStages() {
  const res = await apiClient.get<ApiEnvelope<ProductionStage[]>>('/production-stages', { params: { is_active: true } });
  return res.data.data;
}

export async function startStage(batchId: number, stageId: number, notes?: string) {
  const res = await apiClient.post<ApiEnvelope<BatchStageLog>>(`/batches/${batchId}/stages/${stageId}/start`, { notes });
  return res.data.data;
}

export async function completeStage(batchId: number, stageId: number, notes?: string) {
  const res = await apiClient.post<ApiEnvelope<BatchStageLog>>(`/batches/${batchId}/stages/${stageId}/complete`, { notes });
  return res.data.data;
}

// Batch-level start/complete — distinct from stage-level start/complete above.
// ProductionBatchService::completeBatch requires status === 'in_progress', which
// only startProduction() sets; without calling these, a batch worked entirely
// through this app could never reach qc_pending (M2 fix).
export async function startBatch(batchId: number) {
  const res = await apiClient.post<ApiEnvelope<Batch>>(`/batches/${batchId}/start`);
  return res.data.data;
}

export async function completeBatch(batchId: number, completedQuantity?: number) {
  const res = await apiClient.post<ApiEnvelope<Batch>>(`/batches/${batchId}/complete`, {
    completed_quantity: completedQuantity,
  });
  return res.data.data;
}
