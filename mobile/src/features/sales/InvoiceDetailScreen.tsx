import { useState } from 'react';
import { ActivityIndicator, Alert, ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useNavigation, useRoute, RouteProp } from '@react-navigation/native';
import { Button } from '../../design-system/Button';
import { IconBack } from '../../design-system/icons';
import { colors, font, radius, spacing } from '../../design-system/tokens';
import { useInvoice, usePaymentStatus, useRecordPayment } from './hooks';
import { sendPaymentReminder } from '../../api/payments';
import { PaymentMethod } from '../../api/payments';
import { SalesStackParamList } from './navigation';

type Nav = NativeStackNavigationProp<SalesStackParamList, 'InvoiceDetail'>;
type Rt = RouteProp<SalesStackParamList, 'InvoiceDetail'>;

const money = (n: number) => `₹${n.toLocaleString('en-IN')}`;
const methods: { key: PaymentMethod; label: string }[] = [
  { key: 'bank_transfer', label: 'Bank' },
  { key: 'cash', label: 'Cash' },
  { key: 'cheque', label: 'Cheque' },
  { key: 'upi', label: 'UPI' },
  { key: 'other', label: 'Other' },
];

export function InvoiceDetailScreen() {
  const navigation = useNavigation<Nav>();
  const { params } = useRoute<Rt>();
  const { invoiceId } = params;

  const { data: invoice, isLoading: invoiceLoading } = useInvoice(invoiceId);
  const { data: paymentStatus, isLoading: statusLoading } = usePaymentStatus(invoiceId);
  const recordPayment = useRecordPayment(invoiceId);

  const [showForm, setShowForm] = useState(false);
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState<PaymentMethod>('bank_transfer');
  const [reference, setReference] = useState('');
  const [reminderSent, setReminderSent] = useState(false);
  const [reminderSending, setReminderSending] = useState(false);

  const customerName = invoice?.dispatch?.batch?.order?.customer?.name ?? invoice?.order?.customer?.name ?? null;

  function openFullPayment() {
    if (paymentStatus) setAmount(String(paymentStatus.remaining_due));
    setShowForm(true);
  }

  async function handleSavePayment() {
    const numeric = Number(amount);
    if (!numeric || numeric <= 0) {
      Alert.alert('Enter an amount', 'Payment amount must be greater than zero.');
      return;
    }
    try {
      await recordPayment.mutateAsync({ amount: numeric, payment_method: method, reference_no: reference || undefined });
      setShowForm(false);
      setAmount('');
      setReference('');
    } catch (e: any) {
      Alert.alert('Could not record payment', e?.response?.data?.message ?? 'Please try again.');
    }
  }

  async function handleReminder() {
    setReminderSending(true);
    try {
      await sendPaymentReminder(invoiceId);
      setReminderSent(true);
    } catch (e: any) {
      Alert.alert('Could not send reminder', e?.response?.data?.message ?? 'Please try again.');
    } finally {
      setReminderSending(false);
    }
  }

  const isLoading = invoiceLoading || statusLoading;

  return (
    <SafeAreaView style={styles.screen} edges={['top']}>
      <View style={styles.topbar}>
        <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
          <IconBack />
        </TouchableOpacity>
        <Text style={styles.topbarTitle}>{invoice?.invoice_no ?? 'Invoice'}</Text>
      </View>

      {isLoading || !invoice || !paymentStatus ? (
        <ActivityIndicator style={styles.center} color={colors.primary} size="large" />
      ) : (
        <ScrollView contentContainerStyle={styles.content}>
          <View style={styles.card}>
            {customerName ? <Text style={styles.customer}>{customerName}</Text> : null}
            <SummaryRow label="Total" value={money(paymentStatus.total_amount)} />
            <SummaryRow label="Paid" value={money(paymentStatus.paid_amount)} />
            <View style={styles.divider} />
            <SummaryRow label="Balance Due" value={money(paymentStatus.remaining_due)} emphasize />
          </View>

          {(invoice.items ?? []).length > 0 && (
            <>
              <Text style={styles.sectionTitle}>Items</Text>
              <View style={styles.card}>
                {invoice.items!.map((item) => (
                  <View key={item.id} style={styles.itemRow}>
                    <Text style={styles.itemName}>{item.panel_type?.name ?? item.description ?? 'Item'}</Text>
                    <Text style={styles.itemAmount}>₹{Number(item.amount).toLocaleString('en-IN')}</Text>
                  </View>
                ))}
              </View>
            </>
          )}

          {showForm && (
            <View style={styles.card}>
              <Text style={styles.formTitle}>Record Payment</Text>
              <View style={styles.field}>
                <View style={styles.row}>
                  <Text style={styles.fieldLabel}>Amount</Text>
                  <TouchableOpacity onPress={openFullPayment}>
                    <Text style={styles.fullLink}>Full</Text>
                  </TouchableOpacity>
                </View>
                <TextInput style={styles.input} value={amount} onChangeText={setAmount} keyboardType="decimal-pad" placeholder="0.00" />
              </View>
              <View style={styles.chipRow}>
                {methods.map((m) => (
                  <TouchableOpacity key={m.key} onPress={() => setMethod(m.key)} style={[styles.chip, method === m.key && styles.chipActive]}>
                    <Text style={[styles.chipLabel, method === m.key && styles.chipLabelActive]}>{m.label}</Text>
                  </TouchableOpacity>
                ))}
              </View>
              <View style={styles.field}>
                <Text style={styles.fieldLabel}>Reference No.</Text>
                <TextInput style={styles.input} value={reference} onChangeText={setReference} placeholder="UTR / cheque no." />
              </View>
              <Button title="Save Payment" variant="primary" block onPress={handleSavePayment} loading={recordPayment.isPending} />
            </View>
          )}

          <View style={styles.actionsRow}>
            <Button
              title={reminderSent ? 'Reminder Sent ✓' : 'Send Reminder'}
              variant="outline"
              block
              onPress={handleReminder}
              disabled={reminderSent}
              loading={reminderSending}
            />
            {!showForm && paymentStatus.remaining_due > 0 && (
              <Button title="Record Payment" variant="primary" block onPress={() => setShowForm(true)} />
            )}
          </View>
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
  card: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 14, padding: spacing.md + 2, gap: 8 },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  customer: { fontWeight: font.weight.bold, fontSize: font.size.md },
  divider: { height: 1, backgroundColor: colors.border, marginVertical: 2 },
  summaryLabel: { fontSize: 13, color: colors.textSecondary, fontWeight: font.weight.semibold },
  summaryValue: { fontSize: 13, fontWeight: font.weight.bold },
  summaryLabelEmph: { fontSize: 16, color: colors.text, fontWeight: font.weight.heavy },
  summaryValueEmph: { fontSize: 16, color: colors.danger, fontWeight: font.weight.heavy },
  sectionTitle: { fontSize: 12, fontWeight: font.weight.heavy, color: colors.textSecondary, textTransform: 'uppercase', marginTop: 4 },
  itemRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 6, borderBottomWidth: 1, borderBottomColor: colors.border },
  itemName: { fontSize: font.size.sm + 1, fontWeight: font.weight.semibold, flex: 1 },
  itemAmount: { fontSize: font.size.sm + 1, fontWeight: font.weight.bold },
  formTitle: { fontWeight: font.weight.heavy, fontSize: font.size.md },
  field: { gap: 6 },
  fieldLabel: { fontSize: 12, fontWeight: font.weight.bold, color: colors.textSecondary },
  fullLink: { fontSize: 12, fontWeight: font.weight.bold, color: colors.primary },
  input: { borderWidth: 1, borderColor: colors.border, borderRadius: radius.sm, padding: 11, fontSize: font.size.md, backgroundColor: '#fbfbfd' },
  chipRow: { flexDirection: 'row', gap: 8, flexWrap: 'wrap' },
  chip: { borderWidth: 1, borderColor: colors.border, borderRadius: radius.pill, paddingHorizontal: 12, paddingVertical: 7, backgroundColor: '#fff' },
  chipActive: { backgroundColor: colors.primary, borderColor: colors.primary },
  chipLabel: { fontSize: 12.5, fontWeight: font.weight.bold, color: colors.textSecondary },
  chipLabelActive: { color: colors.white },
  actionsRow: { gap: spacing.sm },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
});
