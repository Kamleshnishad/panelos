import { useState } from 'react';
import { ActivityIndicator, Alert, ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useNavigation, useRoute, RouteProp } from '@react-navigation/native';
import { Button } from '../../design-system/Button';
import { StatusPill } from '../../design-system/StatusPill';
import { colors, font, radius, spacing } from '../../design-system/tokens';
import { IconBack } from '../../design-system/icons';
import { useApproveQc, useBatchQc, useCreateQc } from './hooks';
import { ProductionStackParamList } from './navigation';
import { useSessionStore } from '../../auth/session';
import { hasPermission } from '../../auth/permissions';
import { QcStatus } from '../../api/qc';

type Nav = NativeStackNavigationProp<ProductionStackParamList, 'Qc'>;
type Rt = RouteProp<ProductionStackParamList, 'Qc'>;

export function QcScreen() {
  const navigation = useNavigation<Nav>();
  const { params } = useRoute<Rt>();
  const { batchId } = params;

  const qcQuery = useBatchQc(batchId);
  const createQc = useCreateQc(batchId);
  const approveQc = useApproveQc(batchId);
  const user = useSessionStore((s) => s.user);
  const canApprove = hasPermission(user, 'production.manage');

  const [status, setStatus] = useState<QcStatus>('pass');
  const [notes, setNotes] = useState('');

  async function handleSubmit() {
    try {
      await createQc.mutateAsync({ status, notes: notes || undefined });
    } catch (e: any) {
      Alert.alert('Could not submit QC', e?.response?.data?.message ?? 'Please try again.');
    }
  }

  async function handleApprove() {
    if (!qcQuery.data) return;
    try {
      await approveQc.mutateAsync({ qcId: qcQuery.data.id });
      Alert.alert('Approved', 'QC entry approved — batch is now cleared for dispatch.');
      navigation.goBack();
    } catch (e: any) {
      Alert.alert('Could not approve', e?.response?.data?.message ?? 'Please try again.');
    }
  }

  return (
    <SafeAreaView style={styles.screen} edges={['top']}>
      <View style={styles.topbar}>
        <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
          <IconBack />
        </TouchableOpacity>
        <Text style={styles.topbarTitle}>QC Inspection</Text>
      </View>

      {qcQuery.isLoading ? (
        <ActivityIndicator style={styles.center} color={colors.primary} size="large" />
      ) : (
        <ScrollView contentContainerStyle={styles.content}>
          {qcQuery.data ? (
            <View style={styles.card}>
              <View style={styles.row}>
                <Text style={styles.label}>Verdict</Text>
                <StatusPill label={qcQuery.data.status === 'pass' ? 'PASS' : 'FAIL'} variant={qcQuery.data.status === 'pass' ? 'success' : 'danger'} />
              </View>
              {qcQuery.data.notes ? <Text style={styles.notesText}>{qcQuery.data.notes}</Text> : null}
              {qcQuery.data.inspected_by_user && (
                <Text style={styles.meta}>Inspected by {qcQuery.data.inspected_by_user.name}</Text>
              )}

              {qcQuery.data.approved_at ? (
                <View style={styles.approvedBanner}>
                  <Text style={styles.approvedText}>
                    Approved{qcQuery.data.approved_by_user ? ` by ${qcQuery.data.approved_by_user.name}` : ''}
                  </Text>
                </View>
              ) : qcQuery.data.status === 'fail' ? (
                <Text style={styles.reworkText}>Failed QC requires rework — cannot be approved.</Text>
              ) : canApprove ? (
                <Button title="Approve" variant="primary" block onPress={handleApprove} loading={approveQc.isPending} />
              ) : (
                <Text style={styles.reworkText}>Waiting for a production manager to approve.</Text>
              )}
            </View>
          ) : (
            <View style={styles.card}>
              <Text style={styles.label}>Overall Verdict</Text>
              <View style={styles.verdictRow}>
                <TouchableOpacity
                  style={[styles.verdictBtn, status === 'pass' && styles.verdictBtnPassActive]}
                  onPress={() => setStatus('pass')}
                >
                  <Text style={[styles.verdictBtnText, status === 'pass' && styles.verdictBtnTextActive]}>PASS</Text>
                </TouchableOpacity>
                <TouchableOpacity
                  style={[styles.verdictBtn, status === 'fail' && styles.verdictBtnFailActive]}
                  onPress={() => setStatus('fail')}
                >
                  <Text style={[styles.verdictBtnText, status === 'fail' && styles.verdictBtnTextActive]}>FAIL</Text>
                </TouchableOpacity>
              </View>
              <Text style={styles.label}>Notes</Text>
              <TextInput
                style={styles.textarea}
                value={notes}
                onChangeText={setNotes}
                placeholder="Add any observations..."
                placeholderTextColor={colors.textSecondary}
                multiline
                numberOfLines={3}
              />
              <Button title="Submit QC" variant="primary" block onPress={handleSubmit} loading={createQc.isPending} />
            </View>
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
  card: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 14, padding: spacing.md + 2, gap: spacing.md },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  label: { fontSize: 12, fontWeight: font.weight.bold, color: colors.textSecondary },
  notesText: { fontSize: font.size.md, color: colors.text },
  meta: { fontSize: 12, color: colors.textSecondary, fontWeight: font.weight.semibold },
  approvedBanner: { backgroundColor: colors.successBg, borderRadius: radius.sm, padding: spacing.sm + 2 },
  approvedText: { color: colors.success, fontWeight: font.weight.bold, fontSize: font.size.sm },
  reworkText: { color: colors.textSecondary, fontWeight: font.weight.semibold, fontSize: font.size.sm },
  verdictRow: { flexDirection: 'row', gap: spacing.md },
  verdictBtn: { flex: 1, borderWidth: 1.5, borderColor: colors.border, borderRadius: radius.sm, paddingVertical: 18, alignItems: 'center' },
  verdictBtnPassActive: { backgroundColor: colors.successBg, borderColor: colors.success },
  verdictBtnFailActive: { backgroundColor: colors.dangerBg, borderColor: colors.danger },
  verdictBtnText: { fontWeight: font.weight.heavy, color: colors.textSecondary, fontSize: font.size.md },
  verdictBtnTextActive: { color: colors.text },
  textarea: { borderWidth: 1, borderColor: colors.border, borderRadius: radius.sm, padding: spacing.md - 2, fontSize: font.size.md, minHeight: 80, textAlignVertical: 'top', backgroundColor: '#fbfbfd' },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
});
