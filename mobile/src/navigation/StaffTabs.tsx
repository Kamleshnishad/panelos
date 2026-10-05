import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { PlaceholderScreen } from '../screens/PlaceholderScreen';
import { ComponentGalleryScreen } from '../screens/ComponentGalleryScreen';
import { ProductionStack } from '../features/production/ProductionStack';
import { DispatchStack } from '../features/dispatch/DispatchStack';
import { SalesStack } from '../features/sales/SalesStack';
import { DashboardScreen } from '../features/dashboard/DashboardScreen';
import { SessionUser, useSessionStore } from '../auth/session';
import { hasAnyPermission } from '../auth/permissions';
import { colors } from '../design-system/tokens';
import { IconBell, IconHome, IconPackage, IconRupeeCircle, IconTruck, IconUser } from '../design-system/icons';

export type StaffTabParamList = {
  Dashboard: undefined;
  Production: undefined;
  Dispatch: undefined;
  Sales: undefined;
  Profile: undefined;
  Dev: undefined;
};

const Tab = createBottomTabNavigator<StaffTabParamList>();

// Which tabs a role sees, gated on the real permission keys from
// backend/config/permissions.php (not a role-name guess — every company can
// rename roles freely, but permission keys are fixed).
function visibleTabs(user: SessionUser | null) {
  return {
    production: hasAnyPermission(user, ['production.view', 'production.manage']),
    dispatch: hasAnyPermission(user, ['dispatch.view', 'dispatch.manage']),
    sales: hasAnyPermission(user, ['quotations.view', 'orders.view', 'payments.view', 'invoices.view']),
  };
}

export function StaffTabs() {
  const user = useSessionStore((s) => s.user);
  const tabs = visibleTabs(user);

  return (
    <Tab.Navigator
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.tabInactive,
        tabBarStyle: { borderTopColor: colors.border },
      }}
    >
      <Tab.Screen
        name="Dashboard"
        component={DashboardScreen}
        options={{ tabBarIcon: ({ color, size }) => <IconHome color={color} size={size} /> }}
      />

      {tabs.production && (
        <Tab.Screen
          name="Production"
          component={ProductionStack}
          options={{ tabBarIcon: ({ color, size }) => <IconPackage color={color} size={size} /> }}
        />
      )}

      {tabs.dispatch && (
        <Tab.Screen
          name="Dispatch"
          component={DispatchStack}
          options={{ tabBarIcon: ({ color, size }) => <IconTruck color={color} size={size} /> }}
        />
      )}

      {tabs.sales && (
        <Tab.Screen
          name="Sales"
          component={SalesStack}
          options={{ tabBarIcon: ({ color, size }) => <IconRupeeCircle color={color} size={size} /> }}
        />
      )}

      <Tab.Screen
        name="Profile"
        options={{ tabBarIcon: ({ color, size }) => <IconUser color={color} size={size} /> }}
      >
        {() => <PlaceholderScreen title="Profile" subtitle={user?.company?.name ?? ''} />}
      </Tab.Screen>

      {__DEV__ && (
        <Tab.Screen
          name="Dev"
          component={ComponentGalleryScreen}
          options={{ tabBarIcon: ({ color, size }) => <IconBell color={color} size={size} /> }}
        />
      )}
    </Tab.Navigator>
  );
}
