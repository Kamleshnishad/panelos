import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as productionApi from '../../api/production';
import * as qcApi from '../../api/qc';
import * as attachmentsApi from '../../api/attachments';

export function useBatches(status?: productionApi.BatchStatus) {
  return useInfiniteQuery({
    queryKey: ['batches', status ?? 'all'],
    queryFn: ({ pageParam }) => productionApi.listBatches(status ? { status, page: pageParam } : { page: pageParam }),
    initialPageParam: 1,
    getNextPageParam: (lastPage) => {
      const { current_page, per_page, total } = lastPage.meta.pagination;
      return current_page * per_page < total ? current_page + 1 : undefined;
    },
  });
}

export function useBatch(id: number) {
  return useQuery({ queryKey: ['batch', id], queryFn: () => productionApi.getBatch(id) });
}

export function useBatchTimeline(batchId: number) {
  return useQuery({ queryKey: ['batch', batchId, 'timeline'], queryFn: () => productionApi.getTimeline(batchId) });
}

export function useProductionStages() {
  return useQuery({ queryKey: ['production-stages'], queryFn: productionApi.listStages, staleTime: 5 * 60 * 1000 });
}

export function useStartStage(batchId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (stageId: number) => productionApi.startStage(batchId, stageId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['batch', batchId, 'timeline'] });
      queryClient.invalidateQueries({ queryKey: ['batch', batchId] });
    },
  });
}

export function useStartBatch(batchId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => productionApi.startBatch(batchId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['batch', batchId] });
      queryClient.invalidateQueries({ queryKey: ['batches'] });
    },
  });
}

export function useCompleteBatch(batchId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => productionApi.completeBatch(batchId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['batch', batchId] });
      queryClient.invalidateQueries({ queryKey: ['batches'] });
    },
  });
}

export function useCompleteStage(batchId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ stageId, notes }: { stageId: number; notes?: string }) => productionApi.completeStage(batchId, stageId, notes),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['batch', batchId, 'timeline'] });
      queryClient.invalidateQueries({ queryKey: ['batch', batchId] });
      queryClient.invalidateQueries({ queryKey: ['batches'] });
    },
  });
}

export function useBatchQc(batchId: number) {
  return useQuery({ queryKey: ['batch', batchId, 'qc'], queryFn: () => qcApi.getBatchQc(batchId) });
}

export function useCreateQc(batchId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: { status: qcApi.QcStatus; notes?: string }) => qcApi.createBatchQc(batchId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['batch', batchId, 'qc'] });
      queryClient.invalidateQueries({ queryKey: ['batch', batchId] });
      queryClient.invalidateQueries({ queryKey: ['batches'] });
    },
  });
}

export function useStageAttachments(batchId: number, stageId: number | null) {
  return useQuery({
    queryKey: ['batch', batchId, 'stage', stageId, 'attachments'],
    queryFn: () => attachmentsApi.listStageAttachments(batchId, stageId!),
    enabled: stageId != null,
  });
}

export function useUploadStagePhoto(batchId: number, stageId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (uri: string) => attachmentsApi.uploadStagePhoto(batchId, stageId, 'stage_photo', uri),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['batch', batchId, 'stage', stageId, 'attachments'] });
    },
  });
}

export function useApproveQc(batchId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ qcId, notes }: { qcId: number; notes?: string }) => qcApi.approveQc(qcId, notes),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['batch', batchId, 'qc'] });
      queryClient.invalidateQueries({ queryKey: ['batches'] });
    },
  });
}
