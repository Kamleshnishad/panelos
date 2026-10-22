import { ActivityIndicator, Alert, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useNavigation, useRoute, RouteProp } from '@react-navigation/native';
import { Button } from '../../design-system/Button';
import { StatusPill } from '../../design-system/StatusPill';
import { IconBack } from '../../design-system/icons';
import { colors, font, spacing } from '../../design-system/tokens';
import { useAcceptQuotation, useQuotation, useRejectQuotation, useSendQuotation } from './hooks';
import { quotationStatusMeta } from './statusMeta';
import { SalesStackParamList } from './navigation';
import { useSessionStore } from '../../auth/session';
import { hasPermission } from '../../auth/permissions';

type Nav = NativeStackNavigationProp<SalesStackParamList, 'QuotationDetail'>;
type Rt = RouteProp<SalesStackParamList, 'QuotationDetail'>;

const money = (n: string | number) => `₹${Number(n).toLocaleString('en-IN')}`;

export function QuotationDetailScreen() {
  const navigation = useNavigation<Nav>();
  const { params } = useRoute<Rt>();
  const { quotationId } = params;

  const { data: q, isLoading } = useQuotation(quotationId);
  const sendQ = useSendQuotation(quotationId);
  const acceptQ = useAcceptQuotation(quotationId);
  const rejectQ = useRejectQuotation(quotationId);
  const user = useSessionStore((s) => s.user);
  const canManage = hasPermission(user, 'quotations.manage');

  async function runAction(label: string, mutateAsync: () => Promise<unknown>) {
    try {
      await mutateAsync();
    } catch (e: any) {
      Alert.alert(`Could not ${label.toLowerCase()}`, e?.response?.data?.message ?? 'Please try again.');
    }
  }

  return (
    <SafeAreaView style={styles.screen} edges={['top']}>
      <View style={styles.topbar}>
        <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
          <IconBack />
        </TouchableOpacity>
        <Text style={styles.topbarTitle}>{q?.quotation_no ?? 'Quotation'}</Text>
      </View>

      {isLoading || !q ? (
        <ActivityIndicator style={styles.center} color={colors.primary} size="large" />
      ) : (
        <ScrollView contentContainerStyle={styles.content}>
          <View style={styles.card}>
            <View style={styles.row}>
              {q.customer?.name ? <Text style={styles.customer}>{q.customer.name}</Text> : <View />}
              <StatusPill label={quotationStatusMeta[q.status].label} variant={quotationStatusMeta[q.status].variant} />
            </View>
            {q.project_name ? <Text style={styles.meta}>{q.project_name}</Text> : null}
            {q.valid_until ? <Text style={styles.meta}>Valid until {new Date(q.valid_until).toLocaleDateString()}</Text> : null}

            <View style={styles.divider} />
            <SummaryRow label="Subtotal" value={money(q.subtotal)} />
            <SummaryRow label="Discount" value={`-${money(q.discount_amount)}`} />
            <SummaryRow label="Tax" value={money(q.tax_amount)} />
            <View style={styles.divider} />
            <SummaryRow label="Total" value={money(q.total_amount)} emphasize />
            <SummaryRow label="Advance" value={money(q.advance_amount)} />
            <SummaryRow label="Balance" value={money(q.balance_amount)} />
          </View>

          {canManage && (
            <View style={styles.actionsRow}>
              {q.status === 'draft' && (
                <Button title="Send" variant="primary" block onPress={() => runAction('Send', () => sendQ.mutateAsync())} loading={sendQ.isPending} />
              )}
              {q.status === 'sent' && (
                <>
                  <Button
                    title="Accept"
                    variant="primary"
                    block
                    onPress={() => runAction('Accept', () => acceptQ.mutateAsync())}
                    loading={acceptQ.isPending}
                  />
                  <Button
                    title="Reject"
                    variant="danger"
                    block
                    onPress={() => runAction('Reject', () => rejectQ.mutateAsync())}
                    loading={rejectQ.isPending}
                  />
                </>
              )}
              {q.status === 'draft' && (
                <Button title="Reject" variant="outline" block onPress={() => runAction('Reject', () => rejectQ.mutateAsync())} loading={rejectQ.isPending} />
              )}
            </View>
          )}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

function SummaryRow({ label, value, emphasize }: { label: string; value: string; emphasize?: boolean }) {
  return (
    <View style={styles.row}>
      <Text style={[styles.summaryLabel, emphasize && styles.summaryLabelEmph]}>{label}</Text>
      <Text style={[styles.summaryValue, emphasize && styles.summaryValueEmph]}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  topbar: { minHeight: 56, backgroundColor: colors.primary, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 14, gap: 12 },
  backBtn: { width: 32, height: 32, alignItems: 'center', justifyContent: 'center' },
  topbarTitle: { color: colors.white, fontSize: font.size.lg, fontWeight: font.weight.bold },
  content: { padding: spacing.lg, gap: spacing.md },
  card: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 14, padding: spacing.md + 2, gap: 6 },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  customer: { fontWeight: font.weight.bold, fontSize: font.size.md },
  meta: { fontSize: 12, color: colors.textSecondary, fontWeight: font.weight.semibold },
  divider: { height: 1, backgroundColor: colors.border, marginVertical: 4 },
  summaryLabel: { fontSize: 13, color: colors.textSecondary, fontWeight: font.weight.semibold },
  summaryValue: { fontSize: 13, fontWeight: font.weight.bold },
  summaryLabelEmph: { fontSize: 15, color: colors.text, fontWeight: font.weight.heavy },
  summaryValueEmph: { fontSize: 15, color: colors.primary, fontWeight: font.weight.heavy },
  actionsRow: { gap: spacing.sm },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
});
