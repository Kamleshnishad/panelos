import { useState } from 'react';
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useNavigation } from '@react-navigation/native';
import { Card } from '../../design-system/Card';
import { StatusPill } from '../../design-system/StatusPill';
import { colors, font, radius, spacing } from '../../design-system/tokens';
import { useOrders, useQuotations } from './hooks';
import { orderStatusFilters, orderStatusMeta, quotationStatusFilters, quotationStatusMeta } from './statusMeta';
import { Quotation, QuotationStatus } from '../../api/quotations';
import { Order, OrderStatus } from '../../api/orders';
import { SalesStackParamList } from './navigation';

type Nav = NativeStackNavigationProp<SalesStackParamList, 'SalesHome'>;
type Segment = 'quotations' | 'orders';

export function SalesHomeScreen() {
  const navigation = useNavigation<Nav>();
  const [segment, setSegment] = useState<Segment>('quotations');

  return (
    <SafeAreaView style={styles.screen} edges={['top']}>
      <View style={styles.topbar}>
        <Text style={styles.topbarTitle}>Sales &amp; Accounts</Text>
        <TouchableOpacity onPress={() => navigation.navigate('Receivables')} style={styles.receivablesBtn}>
          <Text style={styles.receivablesBtnText}>Receivables</Text>
        </TouchableOpacity>
      </View>

      <View style={styles.segmentRow}>
        <TouchableOpacity style={[styles.segment, segment === 'quotations' && styles.segmentActive]} onPress={() => setSegment('quotations')}>
          <Text style={[styles.segmentText, segment === 'quotations' && styles.segmentTextActive]}>Quotations</Text>
        </TouchableOpacity>
        <TouchableOpacity style={[styles.segment, segment === 'orders' && styles.segmentActive]} onPress={() => setSegment('orders')}>
          <Text style={[styles.segmentText, segment === 'orders' && styles.segmentTextActive]}>Orders</Text>
        </TouchableOpacity>
      </View>

      {segment === 'quotations' ? (
        <QuotationsTab onOpen={(id) => navigation.navigate('QuotationDetail', { quotationId: id })} />
      ) : (
        <OrdersTab onOpen={(id) => navigation.navigate('OrderDetail', { orderId: id })} />
      )}
    </SafeAreaView>
  );
}

function QuotationsTab({ onOpen }: { onOpen: (id: number) => void }) {
  const [filter, setFilter] = useState<QuotationStatus | 'all'>('all');
  const { data, isLoading, isError, refetch, isRefetching } = useQuotations(filter === 'all' ? undefined : filter);

  return (
    <>
      <FilterChips filters={quotationStatusFilters} value={filter} onChange={setFilter} />
      {isLoading ? (
        <ActivityIndicator style={styles.center} color={colors.primary} size="large" />
      ) : isError ? (
        <View style={styles.center}>
          <Text style={styles.emptyText}>Couldn't load quotations. Pull down to retry.</Text>
        </View>
      ) : (
        <FlatList
          data={data?.data ?? []}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={refetch} />}
          ListEmptyComponent={
            <View style={styles.center}>
              <Text style={styles.emptyText}>No quotations match this filter.</Text>
            </View>
          }
          renderItem={({ item }) => <QuotationCard quotation={item} onPress={() => onOpen(item.id)} />}
        />
      )}
    </>
  );
}

function OrdersTab({ onOpen }: { onOpen: (id: number) => void }) {
  const [filter, setFilter] = useState<OrderStatus | 'all'>('all');
  const { data, isLoading, isError, refetch, isRefetching } = useOrders(filter === 'all' ? undefined : filter);

  return (
    <>
      <FilterChips filters={orderStatusFilters} value={filter} onChange={setFilter} />
      {isLoading ? (
        <ActivityIndicator style={styles.center} color={colors.primary} size="large" />
      ) : isError ? (
        <View style={styles.center}>
          <Text style={styles.emptyText}>Couldn't load orders. Pull down to retry.</Text>
        </View>
      ) : (
        <FlatList
          data={data?.data ?? []}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={refetch} />}
          ListEmptyComponent={
            <View style={styles.center}>
              <Text style={styles.emptyText}>No orders match this filter.</Text>
            </View>
          }
          renderItem={({ item }) => <OrderCard order={item} onPress={() => onOpen(item.id)} />}
        />
      )}
    </>
  );
}

function FilterChips<T extends string>({ filters, value, onChange }: { filters: { key: T; label: string }[]; value: T; onChange: (v: T) => void }) {
  return (
    <View style={styles.chipRow}>
      {filters.map((f) => (
        <TouchableOpacity key={f.key} onPress={() => onChange(f.key)} style={[styles.chip, value === f.key && styles.chipActive]}>
          <Text style={[styles.chipLabel, value === f.key && styles.chipLabelActive]}>{f.label}</Text>
        </TouchableOpacity>
      ))}
    </View>
  );
}

function QuotationCard({ quotation, onPress }: { quotation: Quotation; onPress: () => void }) {
  const meta = quotationStatusMeta[quotation.status];
  return (
    <TouchableOpacity onPress={onPress} activeOpacity={0.75}>
      <Card style={styles.card}>
        <View style={styles.cardTop}>
          <Text style={styles.docNo}>{quotation.quotation_no}</Text>
          <StatusPill label={meta.label} variant={meta.variant} />
        </View>
        {quotation.customer?.name ? <Text style={styles.customer}>{quotation.customer.name}</Text> : null}
        <Text style={styles.amount}>₹{Number(quotation.total_amount).toLocaleString('en-IN')}</Text>
      </Card>
    </TouchableOpacity>
  );
}

function OrderCard({ order, onPress }: { order: Order; onPress: () => void }) {
  const meta = orderStatusMeta[order.status];
  return (
    <TouchableOpacity onPress={onPress} activeOpacity={0.75}>
      <Card style={styles.card}>
        <View style={styles.cardTop}>
          <Text style={styles.docNo}>{order.order_no}</Text>
          <StatusPill label={meta.label} variant={meta.variant} />
        </View>
        {order.customer?.name ? <Text style={styles.customer}>{order.customer.name}</Text> : null}
        <Text style={styles.amount}>₹{Number(order.total_amount).toLocaleString('en-IN')}</Text>
        {order.expected_delivery_date ? (
          <Text style={styles.dateText}>Delivery: {new Date(order.expected_delivery_date).toLocaleDateString()}</Text>
        ) : null}
      </Card>
    </TouchableOpacity>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  topbar: { minHeight: 56, backgroundColor: colors.primary, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 14 },
  topbarTitle: { color: colors.white, fontSize: font.size.lg, fontWeight: font.weight.bold },
  receivablesBtn: { backgroundColor: 'rgba(255,255,255,0.15)', borderRadius: radius.pill, paddingHorizontal: 12, paddingVertical: 6 },
  receivablesBtnText: { color: colors.white, fontSize: 12.5, fontWeight: font.weight.bold },
  segmentRow: { flexDirection: 'row', backgroundColor: colors.accentBg, borderRadius: 11, margin: spacing.lg, marginBottom: 0, padding: 4 },
  segment: { flex: 1, alignItems: 'center', paddingVertical: 9, borderRadius: 8 },
  segmentActive: { backgroundColor: colors.white },
  segmentText: { fontSize: 13, fontWeight: font.weight.bold, color: colors.textSecondary },
  segmentTextActive: { color: colors.primary },
  chipRow: { flexDirection: 'row', gap: spacing.sm, paddingHorizontal: spacing.lg, paddingVertical: spacing.md, flexWrap: 'wrap' },
  chip: { borderWidth: 1, borderColor: colors.border, borderRadius: radius.pill, paddingHorizontal: 14, paddingVertical: 7, backgroundColor: colors.white },
  chipActive: { backgroundColor: colors.primary, borderColor: colors.primary },
  chipLabel: { fontSize: font.size.sm, fontWeight: font.weight.bold, color: colors.textSecondary },
  chipLabelActive: { color: colors.white },
  list: { padding: spacing.lg, paddingTop: 0, gap: spacing.md },
  card: { gap: 6 },
  cardTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  docNo: { fontWeight: font.weight.heavy, fontSize: font.size.md + 1 },
  customer: { fontSize: font.size.sm, color: colors.textSecondary, fontWeight: font.weight.semibold },
  amount: { fontWeight: font.weight.bold, fontSize: font.size.md, color: colors.primary },
  dateText: { fontSize: 12, color: colors.textSecondary, fontWeight: font.weight.bold },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
  emptyText: { color: colors.textSecondary, fontWeight: font.weight.semibold, textAlign: 'center' },
});
