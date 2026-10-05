import { File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import { apiClient } from '../../api/client';

// The challan PDF endpoint requires the Sanctum bearer token, so it can't just
// be opened via Linking.openURL (the system browser won't carry our auth
// header). Fetch it through the authenticated client, write it to disk, then
// hand it to the OS share/view sheet.
export async function openChallan(dispatchId: number): Promise<void> {
  const res = await apiClient.get(`/dispatches/${dispatchId}/challan/pdf`, {
    params: { download: 0 },
    responseType: 'arraybuffer',
  });

  const bytes = new Uint8Array(res.data as ArrayBuffer);
  const file = new File(Paths.cache, `challan-${dispatchId}.pdf`);
  if (file.exists) file.delete();
  file.create();
  file.write(bytes);

  const canShare = await Sharing.isAvailableAsync();
  if (!canShare) {
    throw new Error('Viewing files is not supported on this device.');
  }
  await Sharing.shareAsync(file.uri, { mimeType: 'application/pdf', UTI: 'com.adobe.pdf' });
}
