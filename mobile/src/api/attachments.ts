import { apiClient } from './client';

// Mirrors backend/app/Models/Attachment.php — mobile_plan/09_BACKEND_CHANGES_REQUIRED.md §1.
export type AttachmentType = 'qc_photo' | 'stage_photo' | 'delivery_proof' | 'signature';

export type Attachment = {
  id: number;
  type: AttachmentType;
  file_path: string;
  url: string;
  uploaded_by: number | null;
  created_at: string;
};

type ApiEnvelope<T> = { success: boolean; message: string; data: T };

// `uri` is whatever ImagePicker/the camera returned — a local file:// path on
// both platforms. The server doesn't trust the client's own compression, but
// we still shrink before upload (ImagePicker's own `quality` option) to keep
// mobile-data usage sane on a factory floor's patchy connection.
async function uploadPhoto(url: string, type: AttachmentType, uri: string) {
  const form = new FormData();
  form.append('type', type);
  form.append('photo', { uri, name: `${type}-${Date.now()}.jpg`, type: 'image/jpeg' } as any);

  const res = await apiClient.post<ApiEnvelope<Attachment>>(url, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return res.data.data;
}

export async function listStageAttachments(batchId: number, stageId: number) {
  const res = await apiClient.get<ApiEnvelope<Attachment[]>>(`/batches/${batchId}/stages/${stageId}/attachments`);
  return res.data.data;
}

export async function uploadStagePhoto(batchId: number, stageId: number, type: 'qc_photo' | 'stage_photo', uri: string) {
  return uploadPhoto(`/batches/${batchId}/stages/${stageId}/attachments`, type, uri);
}

export async function listDispatchAttachments(dispatchId: number) {
  const res = await apiClient.get<ApiEnvelope<Attachment[]>>(`/dispatches/${dispatchId}/attachments`);
  return res.data.data;
}

export async function uploadDispatchPhoto(dispatchId: number, type: 'delivery_proof' | 'signature', uri: string) {
  return uploadPhoto(`/dispatches/${dispatchId}/attachments`, type, uri);
}
