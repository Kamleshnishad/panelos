import { ActivityIndicator, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useNavigation, useRoute, RouteProp } from '@react-navigation/native';
import { StatusPill } from '../../design-system/StatusPill';
import { IconBack } from '../../design-system/icons';
import { colors, font, spacing } from '../../design-system/tokens';
import { useOrder } from './hooks';
import { orderStatusMeta, genericStatusVariant } from './statusMeta';
import { SalesStackParamList } from './navigation';

type Nav = NativeStackNavigationProp<SalesStackParamList, 'OrderDetail'>;
type Rt = RouteProp<SalesStackParamList, 'OrderDetail'>;

export function OrderDetailScreen() {
  const navigation = useNavigation<Nav>();
  const { params } = useRoute<Rt>();
  const { orderId } = params;
  const { data: order, isLoading } = useOrder(orderId);

  return (
    <SafeAreaView style={styles.screen} edges={['top']}>
      <View style={styles.topbar}>
        <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
          <IconBack />
        </TouchableOpacity>
        <Text style={styles.topbarTitle}>{order?.order_no ?? 'Order'}</Text>
      </View>

      {isLoading || !order ? (
        <ActivityIndicator style={styles.center} color={colors.primary} size="large" />
      ) : (
        <ScrollView contentContainerStyle={styles.content}>
          <View style={styles.card}>
            <View style={styles.row}>
              {order.customer?.name ? <Text style={styles.customer}>{order.customer.name}</Text> : <View />}
              <StatusPill label={orderStatusMeta[order.status].label} variant={orderStatusMeta[order.status].variant} />
            </View>
            {order.project_name ? <Text style={styles.meta}>{order.project_name}</Text> : null}
            <Text style={styles.amount}>₹{Number(order.total_amount).toLocaleString('en-IN')}</Text>
            {order.expected_delivery_date ? (
              <Text style={styles.meta}>Delivery: {new Date(order.expected_delivery_date).toLocaleDateString()}</Text>
            ) : null}
          </View>

          <Text style={styles.sectionTitle}>Production Batches</Text>
          <View style={styles.card}>
            {order.batches && order.batches.length > 0 ? (
              order.batches.map((b) => (
                <View key={b.id} style={styles.batchRow}>
                  <Text style={styles.batchNo}>{b.batch_no}</Text>
                  <StatusPill label={b.status} variant={genericStatusVariant(b.status)} />
                </View>
              ))
            ) : (
              <Text style={styles.emptyText}>No production batches created yet.</Text>
            )}
          </View>
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
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  customer: { fontWeight: font.weight.bold, fontSize: font.size.md },
  meta: { fontSize: 12, color: colors.textSecondary, fontWeight: font.weight.semibold },
  amount: { fontWeight: font.weight.heavy, fontSize: font.size.md, color: colors.primary },
  sectionTitle: { fontSize: 12, fontWeight: font.weight.heavy, color: colors.textSecondary, textTransform: 'uppercase', marginTop: 4 },
  batchRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: colors.border },
  batchNo: { fontWeight: font.weight.bold, fontSize: font.size.sm + 1 },
  emptyText: { color: colors.textSecondary, fontWeight: font.weight.semibold },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
});
