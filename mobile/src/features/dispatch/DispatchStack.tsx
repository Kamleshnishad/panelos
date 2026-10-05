import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { DispatchStackParamList } from './navigation';
import { DispatchListScreen } from './DispatchListScreen';
import { DispatchDetailScreen } from './DispatchDetailScreen';

const Stack = createNativeStackNavigator<DispatchStackParamList>();

export function DispatchStack() {
  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      <Stack.Screen name="DispatchList" component={DispatchListScreen} />
      <Stack.Screen name="DispatchDetail" component={DispatchDetailScreen} />
    </Stack.Navigator>
  );
}
