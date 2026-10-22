import { useState } from 'react';
import { ActivityIndicator, Alert, Image, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useNavigation, useRoute, RouteProp } from '@react-navigation/native';
import { Button } from '../../design-system/Button';
import { StatusPill } from '../../design-system/StatusPill';
import { IconBack, IconCamera } from '../../design-system/icons';
import { colors, font, radius, spacing } from '../../design-system/tokens';
import { useCompleteDispatch, useDispatch, useDispatchAttachments, useUploadDispatchPhoto } from './hooks';
import { dispatchStatusMeta } from './statusMeta';
import { computeDispatchQty, computeDispatchTotal } from '../../api/dispatch';
import { DispatchStackParamList } from './navigation';
import { openChallan } from './challan';
import { useSessionStore } from '../../auth/session';
import { hasPermission } from '../../auth/permissions';
import { pickPhoto } from '../../shared/pickPhoto';

type Nav = NativeStackNavigationProp<DispatchStackParamList, 'DispatchDetail'>;
type Rt = RouteProp<DispatchStackParamList, 'DispatchDetail'>;

export function DispatchDetailScreen() {
  const navigation = useNavigation<Nav>();
  const { params } = useRoute<Rt>();
  const { dispatchId } = params;

  const { data: dispatch, isLoading } = useDispatch(dispatchId);
  const completeDispatch = useCompleteDispatch(dispatchId);
  const attachmentsQuery = useDispatchAttachments(dispatchId);
  const uploadPhoto = useUploadDispatchPhoto(dispatchId);
  const [challanLoading, setChallanLoading] = useState(false);
  const user = useSessionStore((s) => s.user);
  const canManage = hasPermission(user, 'dispatch.manage');

  async function handleMarkDelivered() {
    Alert.alert('Confirm delivery', 'Mark this dispatch as delivered now?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Confirm',
        onPress: async () => {
          try {
            await completeDispatch.mutateAsync(undefined);
          } catch (e: any) {
            Alert.alert('Could not mark delivered', e?.response?.data?.message ?? 'Please try again.');
          }
        },
      },
    ]);
  }

  async function handleViewChallan() {
    setChallanLoading(true);
    try {
      await openChallan(dispatchId);
    } catch (e: any) {
      Alert.alert('Could not open challan', e?.message ?? 'Please try again.');
    } finally {
      setChallanLoading(false);
    }
  }

  return (
    <SafeAreaView style={styles.screen} edges={['top']}>
      <View style={styles.topbar}>
        <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
          <IconBack />
        </TouchableOpacity>
        <Text style={styles.topbarTitle}>{dispatch?.dispatch_no ?? 'Dispatch'}</Text>
      </View>

      {isLoading || !dispatch ? (
        <ActivityIndicator style={styles.center} color={colors.primary} size="large" />
      ) : (
        <ScrollView contentContainerStyle={styles.content}>
          <View style={styles.card}>
            {dispatch.batch?.batch_no ? <Text style={styles.batchNo}>Batch {dispatch.batch.batch_no}</Text> : null}
            {dispatch.customer_address ? <Text style={styles.address}>{dispatch.customer_address}</Text> : null}
            <View style={styles.row}>
              {dispatch.expected_delivery_date && (
                <Text style={styles.meta}>Expected: {new Date(dispatch.expected_delivery_date).toLocaleDateString()}</Text>
              )}
              <StatusPill label={dispatchStatusMeta[dispatch.status].label} variant={dispatchStatusMeta[dispatch.status].variant} />
            </View>
            {dispatch.tracking_number ? <Text style={styles.meta}>Tracking: {dispatch.tracking_number}</Text> : null}
            {dispatch.actual_delivery_date ? (
              <Text style={styles.meta}>Delivered: {new Date(dispatch.actual_delivery_date).toLocaleDateString()}</Text>
            ) : null}
          </View>

          <Text style={styles.sectionTitle}>Items</Text>
          <View style={styles.card}>
            {(dispatch.items ?? []).map((item) => (
              <View key={item.id} style={styles.itemRow}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.itemName}>{item.panel_type?.name ?? `Panel type #${item.panel_type_id}`}</Text>
                  <Text style={styles.itemQty}>{Number(item.quantity).toLocaleString('en-IN')} SQM</Text>
                </View>
                <Text style={styles.itemAmount}>₹{Number(item.amount).toLocaleString('en-IN')}</Text>
              </View>
            ))}
            <View style={styles.totalRow}>
              <Text style={styles.totalLabel}>Total ({computeDispatchQty(dispatch.items).toLocaleString('en-IN')} SQM)</Text>
              <Text style={styles.totalAmount}>₹{computeDispatchTotal(dispatch.items).toLocaleString('en-IN')}</Text>
            </View>
          </View>

          <View style={styles.actionsRow}>
            <TouchableOpacity style={styles.challanBtn} onPress={handleViewChallan} disabled={challanLoading}>
              <IconCamera color={colors.primary} />
              <Text style={styles.challanBtnText}>{challanLoading ? 'Opening…' : 'View Challan'}</Text>
            </TouchableOpacity>
          </View>

          {canManage && (
            <>
              <Text style={styles.sectionTitle}>Delivery Proof</Text>
              <View style={styles.card}>
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.photoRow}>
                  {(attachmentsQuery.data ?? []).map((a) => (
                    <Image key={a.id} source={{ uri: a.url }} style={styles.photoThumb} />
                  ))}
                  <TouchableOpacity
                    style={styles.addPhotoBtn}
                    onPress={() => pickPhoto((uri) => uploadPhoto.mutate(uri))}
                    disabled={uploadPhoto.isPending}
                  >
                    <IconCamera color={colors.primary} />
                    <Text style={styles.addPhotoText}>{uploadPhoto.isPending ? 'Uploading…' : 'Add'}</Text>
                  </TouchableOpacity>
                </ScrollView>
              </View>
            </>
          )}

          {dispatch.status === 'pending' && canManage && (
            <Button title="Mark Delivered" variant="primary" block onPress={handleMarkDelivered} loading={completeDispatch.isPending} />
          )}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  topbar: { minHeight: 56, backgroundColor: colors.primary, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 14, gap: 12 },
  backBtn: { width: 32, height: 32, alignItems: 'center', justifyContent: 'center' },
  topbarTitle: { color: colors.white, fontSize: font.size.lg, fontWeight: font.weight.bold },
  content: { padding: spacing.lg, gap: spacing.md },
  card: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 14, padding: spacing.md + 2, gap: 6 },
  batchNo: { fontWeight: font.weight.bold, fontSize: font.size.md },
  address: { fontSize: font.size.sm, color: colors.textSecondary, fontWeight: font.weight.semibold },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  meta: { fontSize: 12, color: colors.textSecondary, fontWeight: font.weight.bold },
  sectionTitle: { fontSize: 12, fontWeight: font.weight.heavy, color: colors.textSecondary, textTransform: 'uppercase', marginTop: 4 },
  itemRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: colors.border },
  itemName: { fontWeight: font.weight.bold, fontSize: font.size.sm + 1 },
  itemQty: { fontSize: 12, color: colors.textSecondary, fontWeight: font.weight.semibold },
  itemAmount: { fontWeight: font.weight.bold, fontSize: font.size.sm + 1 },
  totalRow: { flexDirection: 'row', justifyContent: 'space-between', paddingTop: 8 },
  totalLabel: { fontWeight: font.weight.heavy, fontSize: font.size.sm },
  totalAmount: { fontWeight: font.weight.heavy, fontSize: font.size.md, color: colors.primary },
  actionsRow: { flexDirection: 'row' },
  challanBtn: { flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1.5, borderColor: colors.primary, borderRadius: radius.sm, paddingHorizontal: 16, paddingVertical: 11 },
  challanBtnText: { color: colors.primary, fontWeight: font.weight.bold, fontSize: font.size.sm },
  photoRow: { gap: 10 },
  photoThumb: { width: 72, height: 72, borderRadius: radius.sm, backgroundColor: colors.border },
  addPhotoBtn: { width: 72, height: 72, borderRadius: radius.sm, borderWidth: 1.5, borderColor: colors.primary, borderStyle: 'dashed', alignItems: 'center', justifyContent: 'center', gap: 4 },
  addPhotoText: { color: colors.primary, fontWeight: font.weight.bold, fontSize: 11 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
});
