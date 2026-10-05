import { useState } from 'react';
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useNavigation } from '@react-navigation/native';
import { Card } from '../../design-system/Card';
import { StatusPill } from '../../design-system/StatusPill';
import { colors, font, radius, spacing } from '../../design-system/tokens';
import { useBatches } from './hooks';
import { batchStatusMeta, statusFilters } from './statusMeta';
import { Batch, BatchStatus } from '../../api/production';
import { ProductionStackParamList } from './navigation';

type Nav = NativeStackNavigationProp<ProductionStackParamList, 'BatchList'>;

export function BatchListScreen() {
  const navigation = useNavigation<Nav>();
  const [filter, setFilter] = useState<BatchStatus | 'all'>('all');
  const { data, isLoading, isError, refetch, isRefetching } = useBatches(filter === 'all' ? undefined : filter);

  return (
    <SafeAreaView style={styles.screen} edges={['top']}>
      <View style={styles.topbar}>
        <Text style={styles.topbarTitle}>Production Batches</Text>
      </View>

      <View style={styles.chipRow}>
        {statusFilters.map((f) => (
          <TouchableOpacity
            key={f.key}
            onPress={() => setFilter(f.key)}
            style={[styles.chip, filter === f.key && styles.chipActive]}
          >
            <Text style={[styles.chipLabel, filter === f.key && styles.chipLabelActive]}>{f.label}</Text>
          </TouchableOpacity>
        ))}
      </View>

      {isLoading ? (
        <ActivityIndicator style={styles.center} color={colors.primary} size="large" />
      ) : isError ? (
        <View style={styles.center}>
          <Text style={styles.emptyText}>Couldn't load batches. Pull down to retry.</Text>
        </View>
      ) : (
        <FlatList
          data={data?.data ?? []}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={refetch} />}
          ListEmptyComponent={
            <View style={styles.center}>
              <Text style={styles.emptyText}>No batches match this filter.</Text>
            </View>
          }
          renderItem={({ item }) => <BatchCard batch={item} onPress={() => navigation.navigate('BatchDetail', { batchId: item.id })} />}
        />
      )}
    </SafeAreaView>
  );
}

function BatchCard({ batch, onPress }: { batch: Batch; onPress: () => void }) {
  const meta = batchStatusMeta[batch.status];
  const pct = batch.planned_quantity > 0 ? Math.min(100, Math.round((batch.completed_quantity / batch.planned_quantity) * 100)) : 0;
  return (
    <TouchableOpacity onPress={onPress} activeOpacity={0.75}>
      <Card style={styles.card}>
        <View style={styles.cardTop}>
          <Text style={styles.batchNo}>{batch.batch_no}</Text>
          <StatusPill label={meta.label} variant={meta.variant} />
        </View>
        {batch.order?.order_no ? <Text style={styles.orderNo}>{batch.order.order_no}</Text> : null}
        <View style={styles.progressBar}>
          <View style={[styles.progressFill, { width: `${pct}%` }]} />
        </View>
        <View style={styles.cardBottom}>
          <Text style={styles.qtyText}>
            {batch.completed_quantity} / {batch.planned_quantity} SQM
          </Text>
          <Text style={styles.qtyText}>{pct}%</Text>
        </View>
      </Card>
    </TouchableOpacity>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  topbar: { minHeight: 56, backgroundColor: colors.primary, justifyContent: 'center', paddingHorizontal: 14 },
  topbarTitle: { color: colors.white, fontSize: font.size.lg, fontWeight: font.weight.bold },
  chipRow: { flexDirection: 'row', gap: spacing.sm, paddingHorizontal: spacing.lg, paddingVertical: spacing.md },
  chip: { borderWidth: 1, borderColor: colors.border, borderRadius: radius.pill, paddingHorizontal: 14, paddingVertical: 7, backgroundColor: colors.white },
  chipActive: { backgroundColor: colors.primary, borderColor: colors.primary },
  chipLabel: { fontSize: font.size.sm, fontWeight: font.weight.bold, color: colors.textSecondary },
  chipLabelActive: { color: colors.white },
  list: { padding: spacing.lg, gap: spacing.md },
  card: { gap: 8 },
  cardTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  batchNo: { fontWeight: font.weight.heavy, fontSize: font.size.md + 1 },
  orderNo: { fontSize: font.size.sm, color: colors.textSecondary, fontWeight: font.weight.semibold },
  progressBar: { height: 7, borderRadius: 4, backgroundColor: '#e6e8f4', overflow: 'hidden' },
  progressFill: { height: '100%', backgroundColor: colors.primary, borderRadius: 4 },
  cardBottom: { flexDirection: 'row', justifyContent: 'space-between' },
  qtyText: { fontSize: 11.5, color: colors.textSecondary, fontWeight: font.weight.bold },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
  emptyText: { color: colors.textSecondary, fontWeight: font.weight.semibold, textAlign: 'center' },
});
