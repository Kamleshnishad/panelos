import { useMemo, useState } from 'react';
import { ActivityIndicator, Alert, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useNavigation, useRoute, RouteProp } from '@react-navigation/native';
import { Button } from '../../design-system/Button';
import { colors, font, spacing } from '../../design-system/tokens';
import { IconBack } from '../../design-system/icons';
import { useBatch, useBatchTimeline, useCompleteBatch, useCompleteStage, useProductionStages, useStartBatch, useStartStage } from './hooks';
import { useElapsed } from './useElapsed';
import { batchStatusMeta } from './statusMeta';
import { ProductionStackParamList } from './navigation';
import { BatchStageLog, ProductionStage } from '../../api/production';

type Nav = NativeStackNavigationProp<ProductionStackParamList, 'BatchDetail'>;
type Rt = RouteProp<ProductionStackParamList, 'BatchDetail'>;

type MergedStage = {
  stage: ProductionStage;
  log?: BatchStageLog;
  state: 'done' | 'active' | 'locked' | 'available';
};

export function BatchDetailScreen() {
  const navigation = useNavigation<Nav>();
  const { params } = useRoute<Rt>();
  const { batchId } = params;

  const batchQuery = useBatch(batchId);
  const stagesQuery = useProductionStages();
  const timelineQuery = useBatchTimeline(batchId);
  const startStage = useStartStage(batchId);
  const completeStage = useCompleteStage(batchId);
  const startBatch = useStartBatch(batchId);
  const completeBatch = useCompleteBatch(batchId);

  const merged: MergedStage[] = useMemo(() => {
    const stages = stagesQuery.data ?? [];
    const logs = timelineQuery.data ?? [];
    const sorted = [...stages].sort((a, b) => a.sequence - b.sequence);
    let blocked = false;
    return sorted.map((stage) => {
      const log = logs.find((l) => l.stage_id === stage.id);
      let state: MergedStage['state'];
      if (log?.status === 'completed') state = 'done';
      else if (log?.status === 'in_progress') state = 'active';
      else if (blocked) state = 'locked';
      else state = 'available';
      if (log?.status !== 'completed') blocked = true; // next stages are locked until this one is done
      return { stage, log, state };
    });
  }, [stagesQuery.data, timelineQuery.data]);

  const batch = batchQuery.data;
  const isLoading = batchQuery.isLoading || stagesQuery.isLoading || timelineQuery.isLoading;

  // A batch worked entirely through this app must also transition at the
  // batch level (draft -> in_progress -> qc_pending), not just at the stage
  // level — ProductionBatchService::completeBatch requires 'in_progress',
  // which only startProduction() sets (M2 fix).
  async function handleStart(stageId: number, isFirstStage: boolean) {
    try {
      if (isFirstStage && batch?.status === 'draft') {
        await startBatch.mutateAsync();
      }
      await startStage.mutateAsync(stageId);
    } catch (e: any) {
      Alert.alert('Could not start stage', e?.response?.data?.message ?? 'Please try again.');
    }
  }

  async function handleComplete(stageId: number, isLastStage: boolean) {
    try {
      await completeStage.mutateAsync({ stageId });
      if (isLastStage) {
        await completeBatch.mutateAsync();
      }
    } catch (e: any) {
      Alert.alert('Could not complete stage', e?.response?.data?.message ?? 'Please try again.');
    }
  }

  return (
    <SafeAreaView style={styles.screen} edges={['top']}>
      <View style={styles.topbar}>
        <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
          <IconBack />
        </TouchableOpacity>
        <Text style={styles.topbarTitle}>{batch?.batch_no ?? 'Batch'}</Text>
      </View>

      {isLoading ? (
        <ActivityIndicator style={styles.center} color={colors.primary} size="large" />
      ) : !batch ? (
        <View style={styles.center}>
          <Text style={styles.emptyText}>Batch not found.</Text>
        </View>
      ) : (
        <ScrollView contentContainerStyle={styles.content}>
          <View style={styles.card}>
            {batch.order?.order_no ? <Text style={styles.orderNo}>{batch.order.order_no}</Text> : null}
            <View style={styles.row}>
              <Text style={styles.qty}>
                {batch.completed_quantity} / {batch.planned_quantity} SQM
              </Text>
              <Text style={styles.statusLabel}>{batchStatusMeta[batch.status].label}</Text>
            </View>
          </View>

          {batch.status === 'qc_pending' && (
            <Button title="Go to QC Inspection" variant="secondary" block onPress={() => navigation.navigate('Qc', { batchId })} />
          )}

          <Text style={styles.sectionTitle}>Production Stages</Text>
          <View style={styles.card}>
            {merged.map((m, idx) => (
              <StageRow
                key={m.stage.id}
                merged={m}
                isLast={idx === merged.length - 1}
                onStart={() => handleStart(m.stage.id, idx === 0)}
                onComplete={() => handleComplete(m.stage.id, idx === merged.length - 1)}
                starting={startStage.isPending || startBatch.isPending}
                completing={completeStage.isPending || completeBatch.isPending}
              />
            ))}
          </View>
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

function StageRow({
  merged,
  isLast,
  onStart,
  onComplete,
  starting,
  completing,
}: {
  merged: MergedStage;
  isLast: boolean;
  onStart: () => void;
  onComplete: () => void;
  starting: boolean;
  completing: boolean;
}) {
  const { stage, log, state } = merged;
  const elapsed = useElapsed(state === 'active' ? log?.started_at ?? null : null);

  const dotStyle =
    state === 'done' ? styles.dotDone : state === 'active' ? styles.dotActive : styles.dotLocked;

  return (
    <View style={styles.stageRow}>
      <View style={styles.stageCol}>
        <View style={[styles.dot, dotStyle]}>
          {state === 'done' && <Text style={styles.dotCheck}>✓</Text>}
        </View>
        {!isLast && <View style={styles.stageLine} />}
      </View>
      <View style={styles.stageBody}>
        <View style={styles.row}>
          <Text style={[styles.stageName, state === 'locked' && styles.stageNameLocked]}>{stage.name}</Text>
          {state === 'done' && log?.duration_minutes != null && <Text style={styles.stageMeta}>{log.duration_minutes}m</Text>}
          {state === 'active' && <Text style={styles.stageMetaActive}>{elapsed}</Text>}
        </View>
        {state === 'locked' && <Text style={styles.stageHint}>Complete previous stage first</Text>}
        {state === 'available' && (
          <Button title="Start Stage" variant="outline" onPress={onStart} loading={starting} style={styles.stageBtn} />
        )}
        {state === 'active' && (
          <Button title="Complete Stage" variant="primary" onPress={onComplete} loading={completing} style={styles.stageBtn} />
        )}
      </View>
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
  orderNo: { fontSize: font.size.sm, color: colors.textSecondary, fontWeight: font.weight.semibold },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  qty: { fontWeight: font.weight.bold, fontSize: font.size.md },
  statusLabel: { fontWeight: font.weight.bold, color: colors.primary, fontSize: font.size.sm },
  sectionTitle: { fontSize: 12, fontWeight: font.weight.heavy, color: colors.textSecondary, textTransform: 'uppercase', marginTop: 4 },
  stageRow: { flexDirection: 'row', gap: 12 },
  stageCol: { alignItems: 'center' },
  dot: { width: 26, height: 26, borderRadius: 13, alignItems: 'center', justifyContent: 'center' },
  dotDone: { backgroundColor: colors.success },
  dotActive: { backgroundColor: colors.primary },
  dotLocked: { backgroundColor: '#e2e4e9' },
  dotCheck: { color: colors.white, fontWeight: font.weight.heavy, fontSize: 13 },
  stageLine: { width: 2, flex: 1, backgroundColor: colors.border, marginTop: 2, minHeight: 20 },
  stageBody: { flex: 1, paddingBottom: 16, gap: 6 },
  stageName: { fontWeight: font.weight.bold, fontSize: font.size.md },
  stageNameLocked: { color: '#9aa0ac' },
  stageMeta: { fontSize: 12, color: colors.textSecondary, fontWeight: font.weight.bold },
  stageMetaActive: { fontSize: 12, color: colors.primary, fontWeight: font.weight.heavy },
  stageHint: { fontSize: 11.5, color: '#9aa0ac', fontWeight: font.weight.semibold },
  stageBtn: { alignSelf: 'flex-start', paddingHorizontal: spacing.lg },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
  emptyText: { color: colors.textSecondary, fontWeight: font.weight.semibold, textAlign: 'center' },
});
