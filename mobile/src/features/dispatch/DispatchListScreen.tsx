import { useState } from 'react';
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useNavigation } from '@react-navigation/native';
import { Card } from '../../design-system/Card';
import { StatusPill } from '../../design-system/StatusPill';
import { colors, font, radius, spacing } from '../../design-system/tokens';
import { useDispatches } from './hooks';
import { dispatchStatusFilters, dispatchStatusMeta } from './statusMeta';
import { Dispatch, DispatchStatus } from '../../api/dispatch';
import { DispatchStackParamList } from './navigation';

type Nav = NativeStackNavigationProp<DispatchStackParamList, 'DispatchList'>;

export function DispatchListScreen() {
  const navigation = useNavigation<Nav>();
  const [filter, setFilter] = useState<DispatchStatus | 'all'>('all');
  const { data, isLoading, isError, refetch, isRefetching } = useDispatches(filter === 'all' ? undefined : filter);

  return (
    <SafeAreaView style={styles.screen} edges={['top']}>
      <View style={styles.topbar}>
        <Text style={styles.topbarTitle}>Dispatches</Text>
      </View>

      <View style={styles.chipRow}>
        {dispatchStatusFilters.map((f) => (
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
          <Text style={styles.emptyText}>Couldn't load dispatches. Pull down to retry.</Text>
        </View>
      ) : (
        <FlatList
          data={data?.data ?? []}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={refetch} />}
          ListEmptyComponent={
            <View style={styles.center}>
              <Text style={styles.emptyText}>No dispatches match this filter.</Text>
            </View>
          }
          renderItem={({ item }) => (
            <DispatchCard dispatch={item} onPress={() => navigation.navigate('DispatchDetail', { dispatchId: item.id })} />
          )}
        />
      )}
    </SafeAreaView>
  );
}

function DispatchCard({ dispatch, onPress }: { dispatch: Dispatch; onPress: () => void }) {
  const meta = dispatchStatusMeta[dispatch.status];
  const isOverdue =
    dispatch.status === 'pending' && !!dispatch.expected_delivery_date && new Date(dispatch.expected_delivery_date) < new Date(new Date().toDateString());

  return (
    <TouchableOpacity onPress={onPress} activeOpacity={0.75}>
      <Card style={styles.card}>
        <View style={styles.cardTop}>
          <Text style={styles.dispatchNo}>{dispatch.dispatch_no}</Text>
          <StatusPill label={isOverdue ? 'Overdue' : meta.label} variant={isOverdue ? 'danger' : meta.variant} />
        </View>
        {dispatch.batch?.batch_no ? <Text style={styles.batchNo}>Batch {dispatch.batch.batch_no}</Text> : null}
        {dispatch.expected_delivery_date ? (
          <Text style={[styles.dateText, isOverdue && styles.dateOverdue]}>
            Expected: {new Date(dispatch.expected_delivery_date).toLocaleDateString()}
          </Text>
        ) : null}
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
  card: { gap: 6 },
  cardTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  dispatchNo: { fontWeight: font.weight.heavy, fontSize: font.size.md + 1 },
  batchNo: { fontSize: font.size.sm, color: colors.textSecondary, fontWeight: font.weight.semibold },
  dateText: { fontSize: 12, color: colors.primary, fontWeight: font.weight.bold },
  dateOverdue: { color: colors.danger },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
  emptyText: { color: colors.textSecondary, fontWeight: font.weight.semibold, textAlign: 'center' },
});
