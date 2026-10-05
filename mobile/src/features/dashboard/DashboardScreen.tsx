import { ActivityIndicator, RefreshControl, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { BottomTabNavigationProp } from '@react-navigation/bottom-tabs';
import { useNavigation } from '@react-navigation/native';
import { StatusPill } from '../../design-system/StatusPill';
import { IconAlertTriangle } from '../../design-system/icons';
import { colors, font, spacing } from '../../design-system/tokens';
import { useSessionStore } from '../../auth/session';
import { hasAnyPermission } from '../../auth/permissions';
import { useDashboard } from './hooks';
import { DashboardActivity, DashboardAlert } from '../../api/dashboard';
import { formatRelativeTime } from '../../shared/formatRelativeTime';
import { StaffTabParamList } from '../../navigation/StaffTabs';

type Nav = BottomTabNavigationProp<StaffTabParamList, 'Dashboard'>;

const money = (n: number | string) => `₹${Number(n).toLocaleString('en-IN')}`;

const alertVariant = (severity: DashboardAlert['severity']) => (severity === 'danger' ? 'danger' : severity === 'warning' ? 'warning' : 'accent');

export function DashboardScreen() {
  const navigation = useNavigation<Nav>();
  const user = useSessionStore((s) => s.user);
  const { data, isLoading, isError, refetch, isRefetching } = useDashboard();

  const canProduction = hasAnyPermission(user, ['production.view', 'production.manage']);
  const canDispatch = hasAnyPermission(user, ['dispatch.view', 'dispatch.manage']);
  const canSales = hasAnyPermission(user, ['quotations.view', 'orders.view', 'payments.view', 'invoices.view']);

  return (
    <SafeAreaView style={styles.screen} edges={['top']}>
      <View style={styles.topbar}>
        <View>
          <Text style={styles.topbarTitle}>Dashboard</Text>
          <Text style={styles.topbarSubtitle}>
            {user?.name ?? ''} · {user?.role ?? 'No role'}
          </Text>
        </View>
      </View>

      {isLoading || !data ? (
        <ActivityIndicator style={styles.center} color={colors.primary} size="large" />
      ) : isError ? (
        <View style={styles.center}>
          <Text style={styles.emptyText}>Couldn't load the dashboard. Pull down to retry.</Text>
        </View>
      ) : (
        <ScrollView contentContainerStyle={styles.content} refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={refetch} />}>
          <View style={styles.grid}>
            {canProduction && (
              <Tile label="In Production" value={data.kpis.batches_in_production} onPress={() => navigation.navigate('Production')} />
            )}
            {canProduction && <Tile label="QC Pending" value={data.pipeline.batches.qc_pending} onPress={() => navigation.navigate('Production')} />}
            {canDispatch && <Tile label="Pending Dispatch" value={data.kpis.pending_dispatch} onPress={() => navigation.navigate('Dispatch')} />}
            {canDispatch && <Tile label="Ready to Ship" value={data.kpis.dispatch_ready} onPress={() => navigation.navigate('Dispatch')} />}
            {canSales && <Tile label="Open Quotations" value={data.kpis.open_quotations} onPress={() => navigation.navigate('Sales')} />}
            {canSales && <Tile label="Active Orders" value={data.kpis.active_orders} onPress={() => navigation.navigate('Sales')} />}
            {canSales && <Tile label="Outstanding" value={money(data.kpis.outstanding_amount)} onPress={() => navigation.navigate('Sales')} />}
            {canSales && <Tile label="Overdue Invoices" value={data.kpis.overdue_invoice_count} danger={data.kpis.overdue_invoice_count > 0} onPress={() => navigation.navigate('Sales')} />}
          </View>

          {data.alerts.length > 0 && (
            <>
              <Text style={styles.sectionTitle}>Alerts</Text>
              <View style={styles.card}>
                {data.alerts.map((alert, idx) => (
                  <View key={idx} style={[styles.alertRow, idx === data.alerts.length - 1 && styles.noBorder]}>
                    {alert.severity === 'danger' ? <IconAlertTriangle color={colors.danger} /> : <IconAlertTriangle color={colors.warning} />}
                    <Text style={styles.alertText}>{alert.message}</Text>
                    <StatusPill label={alert.severity} variant={alertVariant(alert.severity)} />
                  </View>
                ))}
              </View>
            </>
          )}

          <Text style={styles.sectionTitle}>Recent Activity</Text>
          <View style={styles.card}>
            {data.recent_activity.length === 0 ? (
              <Text style={styles.emptyText}>Nothing recent.</Text>
            ) : (
              data.recent_activity.map((activity, idx) => (
                <ActivityRow key={`${activity.type}-${activity.ref}-${idx}`} activity={activity} isLast={idx === data.recent_activity.length - 1} />
              ))
            )}
          </View>
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

function Tile({ label, value, onPress, danger }: { label: string; value: string | number; onPress?: () => void; danger?: boolean }) {
  const Wrapper = onPress ? TouchableOpacity : View;
  return (
    <Wrapper style={styles.tile} onPress={onPress} activeOpacity={0.75}>
      <Text style={[styles.tileValue, danger && styles.tileValueDanger]}>{value}</Text>
      <Text style={styles.tileLabel}>{label}</Text>
    </Wrapper>
  );
}

function ActivityRow({ activity, isLast }: { activity: DashboardActivity; isLast: boolean }) {
  return (
    <View style={[styles.activityRow, isLast && styles.noBorder]}>
      <View style={{ flex: 1 }}>
        <View style={styles.activityTopRow}>
          <Text style={styles.activityType}>{activity.type.toUpperCase()}</Text>
          <Text style={styles.activityTime}>{formatRelativeTime(activity.at)}</Text>
        </View>
        <Text style={styles.activityRef}>{activity.ref}</Text>
      </View>
      <View style={styles.activityRight}>
        {activity.amount != null && <Text style={styles.activityAmount}>{money(activity.amount)}</Text>}
        <Text style={styles.activityStatus}>{activity.status}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  topbar: { minHeight: 56, backgroundColor: colors.primary, justifyContent: 'center', paddingHorizontal: 14 },
  topbarTitle: { color: colors.white, fontSize: font.size.lg, fontWeight: font.weight.bold },
  topbarSubtitle: { color: 'rgba(255,255,255,0.75)', fontSize: 12, fontWeight: font.weight.semibold },
  content: { padding: spacing.lg, gap: spacing.md },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  tile: { width: '47%', backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 14, padding: spacing.md },
  tileValue: { fontSize: 22, fontWeight: font.weight.heavy, color: colors.primary },
  tileValueDanger: { color: colors.danger },
  tileLabel: { fontSize: 11.5, color: colors.textSecondary, fontWeight: font.weight.bold, textTransform: 'uppercase', marginTop: 2 },
  sectionTitle: { fontSize: 12, fontWeight: font.weight.heavy, color: colors.textSecondary, textTransform: 'uppercase', marginTop: 4 },
  card: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 14, padding: spacing.md + 2 },
  alertRow: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 9, borderBottomWidth: 1, borderBottomColor: colors.border },
  alertText: { flex: 1, fontSize: 13, fontWeight: font.weight.semibold },
  activityRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 9, borderBottomWidth: 1, borderBottomColor: colors.border, gap: 8 },
  activityTopRow: { flexDirection: 'row', justifyContent: 'space-between' },
  activityType: { fontSize: 10.5, fontWeight: font.weight.heavy, color: colors.accent, letterSpacing: 0.4 },
  activityTime: { fontSize: 11, color: colors.textSecondary, fontWeight: font.weight.semibold },
  activityRef: { fontSize: 13.5, fontWeight: font.weight.bold, marginTop: 2 },
  activityRight: { alignItems: 'flex-end' },
  activityAmount: { fontSize: 13, fontWeight: font.weight.bold },
  activityStatus: { fontSize: 11, color: colors.textSecondary, fontWeight: font.weight.semibold, marginTop: 2 },
  noBorder: { borderBottomWidth: 0 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
  emptyText: { color: colors.textSecondary, fontWeight: font.weight.semibold, textAlign: 'center' },
});
