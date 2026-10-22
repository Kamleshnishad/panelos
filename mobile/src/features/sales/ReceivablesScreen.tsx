import { useState } from 'react';
import { ActivityIndicator, Alert, FlatList, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useNavigation } from '@react-navigation/native';
import { StatusPill } from '../../design-system/StatusPill';
import { IconBack } from '../../design-system/icons';
import { colors, font, radius, spacing } from '../../design-system/tokens';
import { useAccountsReceivable } from './hooks';
import { ArDetailRow } from '../../api/reports';
import { sendPaymentReminder } from '../../api/payments';
import { SalesStackParamList } from './navigation';

type Nav = NativeStackNavigationProp<SalesStackParamList, 'Receivables'>;

const money = (n: number) => `₹${n.toLocaleString('en-IN')}`;

export function ReceivablesScreen() {
  const navigation = useNavigation<Nav>();
  const { data, isLoading, isError } = useAccountsReceivable();
  const [reminded, setReminded] = useState<Record<number, boolean>>({});
  const [sending, setSending] = useState<Record<number, boolean>>({});

  async function handleRemind(invoiceId: number) {
    setSending((s) => ({ ...s, [invoiceId]: true }));
    try {
      await sendPaymentReminder(invoiceId);
      setReminded((r) => ({ ...r, [invoiceId]: true }));
    } catch (e: any) {
      Alert.alert('Could not send reminder', e?.response?.data?.message ?? 'Please try again.');
    } finally {
      setSending((s) => ({ ...s, [invoiceId]: false }));
    }
  }

  return (
    <SafeAreaView style={styles.screen} edges={['top']}>
      <View style={styles.topbar}>
        <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
          <IconBack />
        </TouchableOpacity>
        <Text style={styles.topbarTitle}>Receivables Aging</Text>
      </View>

      {isLoading || !data ? (
        <ActivityIndicator style={styles.center} color={colors.primary} size="large" />
      ) : isError ? (
        <View style={styles.center}>
          <Text style={styles.emptyText}>Couldn't load receivables.</Text>
        </View>
      ) : (
        <FlatList
          data={data.details}
          keyExtractor={(item) => String(item.invoice_id)}
          contentContainerStyle={styles.list}
          ListHeaderComponent={
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.tilesRow}>
              <Tile label="Total" value={data.summary.total_ar} primary />
              <Tile label="Current" value={data.summary.current} color={colors.success} />
              <Tile label="1-30d" value={data.summary['30_days']} color={colors.warning} />
              <Tile label="31-60d" value={data.summary['60_days']} color={colors.orange} />
              <Tile label="61-90d" value={data.summary['90_days']} color={colors.orange} />
              <Tile label="90+d" value={data.summary.over_90_days} color={colors.danger} />
            </ScrollView>
          }
          ListEmptyComponent={
            <View style={styles.center}>
              <Text style={styles.emptyText}>No outstanding invoices.</Text>
            </View>
          }
          renderItem={({ item }) => (
            <InvoiceRow
              row={item}
              sent={!!reminded[item.invoice_id]}
              sending={!!sending[item.invoice_id]}
              onRemind={() => handleRemind(item.invoice_id)}
              onOpen={() => navigation.navigate('InvoiceDetail', { invoiceId: item.invoice_id })}
            />
          )}
        />
      )}
    </SafeAreaView>
  );
}

function Tile({ label, value, color, primary }: { label: string; value: number; color?: string; primary?: boolean }) {
  return (
    <View style={[styles.tile, primary && styles.tilePrimary]}>
      <Text style={[styles.tileValue, primary && styles.tileValuePrimary, !primary && color ? { color } : null]}>{money(value)}</Text>
      <Text style={[styles.tileLabel, primary && styles.tileLabelPrimary]}>{label}</Text>
    </View>
  );
}

function InvoiceRow({
  row,
  sent,
  sending,
  onRemind,
  onOpen,
}: {
  row: ArDetailRow;
  sent: boolean;
  sending: boolean;
  onRemind: () => void;
  onOpen: () => void;
}) {
  return (
    <View style={styles.invoiceCard}>
      <TouchableOpacity style={styles.invoiceMain} onPress={onOpen} activeOpacity={0.75}>
        <View style={styles.row}>
          <Text style={styles.invoiceNo}>{row.invoice_no}</Text>
          {row.is_overdue ? <StatusPill label={`${row.days_overdue}d`} variant="danger" /> : <StatusPill label="Current" variant="success" />}
        </View>
        <Text style={styles.customer}>{row.customer_name}</Text>
        <Text style={styles.remaining}>{money(row.remaining_due)}</Text>
      </TouchableOpacity>
      <TouchableOpacity style={[styles.remindBtn, sent && styles.remindBtnSent]} onPress={onRemind} disabled={sent || sending}>
        <Text style={[styles.remindBtnText, sent && styles.remindBtnTextSent]}>{sending ? '…' : sent ? 'Sent ✓' : 'Remind'}</Text>
      </TouchableOpacity>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  topbar: { minHeight: 56, backgroundColor: colors.primary, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 14, gap: 12 },
  backBtn: { width: 32, height: 32, alignItems: 'center', justifyContent: 'center' },
  topbarTitle: { color: colors.white, fontSize: font.size.lg, fontWeight: font.weight.bold },
  list: { padding: spacing.lg, gap: spacing.md },
  tilesRow: { gap: spacing.sm, paddingBottom: spacing.md },
  tile: { minWidth: 84, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 12, padding: 10, alignItems: 'center' },
  tilePrimary: { backgroundColor: colors.primary, borderColor: colors.primary },
  tileValue: { fontSize: 14, fontWeight: font.weight.heavy, color: colors.text },
  tileValuePrimary: { color: colors.white, fontSize: 16 },
  tileLabel: { fontSize: 10, fontWeight: font.weight.bold, color: colors.textSecondary, marginTop: 2 },
  tileLabelPrimary: { color: 'rgba(255,255,255,0.8)' },
  invoiceCard: { flexDirection: 'row', alignItems: 'stretch', gap: 10, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 14, padding: spacing.md },
  invoiceMain: { flex: 1, gap: 4 },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  invoiceNo: { fontWeight: font.weight.bold, fontSize: font.size.sm + 1 },
  customer: { fontSize: 12, color: colors.textSecondary, fontWeight: font.weight.semibold },
  remaining: { fontWeight: font.weight.heavy, fontSize: font.size.md, color: colors.danger },
  remindBtn: { alignSelf: 'center', backgroundColor: colors.accentBg, borderRadius: radius.sm, paddingHorizontal: 12, paddingVertical: 8 },
  remindBtnSent: { backgroundColor: '#fff', borderWidth: 1, borderColor: colors.border },
  remindBtnText: { color: colors.accent, fontWeight: font.weight.bold, fontSize: 12 },
  remindBtnTextSent: { color: colors.textSecondary },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
  emptyText: { color: colors.textSecondary, fontWeight: font.weight.semibold, textAlign: 'center' },
});
