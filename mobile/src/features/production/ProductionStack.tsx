import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { ProductionStackParamList } from './navigation';
import { BatchListScreen } from './BatchListScreen';
import { BatchDetailScreen } from './BatchDetailScreen';
import { QcScreen } from './QcScreen';

const Stack = createNativeStackNavigator<ProductionStackParamList>();

export function ProductionStack() {
  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      <Stack.Screen name="BatchList" component={BatchListScreen} />
      <Stack.Screen name="BatchDetail" component={BatchDetailScreen} />
      <Stack.Screen name="Qc" component={QcScreen} />
    </Stack.Navigator>
  );
}
