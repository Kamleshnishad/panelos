import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { SalesStackParamList } from './navigation';
import { SalesHomeScreen } from './SalesHomeScreen';
import { QuotationDetailScreen } from './QuotationDetailScreen';
import { OrderDetailScreen } from './OrderDetailScreen';
import { ReceivablesScreen } from './ReceivablesScreen';
import { InvoiceDetailScreen } from './InvoiceDetailScreen';

const Stack = createNativeStackNavigator<SalesStackParamList>();

export function SalesStack() {
  return (
    <Stack.Navigator screenOptions={{ headerShown: false }}>
      <Stack.Screen name="SalesHome" component={SalesHomeScreen} />
      <Stack.Screen name="QuotationDetail" component={QuotationDetailScreen} />
      <Stack.Screen name="OrderDetail" component={OrderDetailScreen} />
      <Stack.Screen name="Receivables" component={ReceivablesScreen} />
      <Stack.Screen name="InvoiceDetail" component={InvoiceDetailScreen} />
    </Stack.Navigator>
  );
}
