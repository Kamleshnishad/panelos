import { apiClient } from './client';

// Shapes verified against the real backend, not mobile_plan docs:
// backend/app/Models/ProductionBatch.php, BatchStageLog.php, ProductionStage.php,
// backend/app/Services/ProductionBatchService.php, BatchStageLogService.php.

// qc_passed/qc_failed come from QualityControlService::create; 'completed'/'cancelled'
// are not confirmed reachable within this module's scope but are included defensively
// since ProductionBatchController hints at a 'draft' status flow that predates dispatch.
export type BatchStatus = 'draft' | 'in_progress' | 'qc_pending' | 'qc_passed' | 'qc_failed' | 'completed' | 'cancelled';

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
