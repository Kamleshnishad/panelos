import { Alert } from 'react-native';
import * as ImagePicker from 'expo-image-picker';

// quality: 0.5 keeps a typical phone photo in the few-hundred-KB range
// client-side — the server enforces its own 5MB cap regardless (it never
// trusts client-side compression), this is purely to save mobile data on a
// factory floor's patchy connection.
const OPTIONS: ImagePicker.ImagePickerOptions = { quality: 0.5, mediaTypes: ['images'] };

async function launch(fromCamera: boolean): Promise<string | null> {
  const perm = fromCamera
    ? await ImagePicker.requestCameraPermissionsAsync()
    : await ImagePicker.requestMediaLibraryPermissionsAsync();
  if (!perm.granted) {
    Alert.alert('Permission needed', fromCamera ? 'Camera access is required to take a photo.' : 'Photo library access is required.');
    return null;
  }
  const result = fromCamera ? await ImagePicker.launchCameraAsync(OPTIONS) : await ImagePicker.launchImageLibraryAsync(OPTIONS);
  if (result.canceled || !result.assets?.[0]) return null;
  return result.assets[0].uri;
}

/** Prompts Camera vs Library, returns the picked photo's local URI, or null if cancelled. */
export function pickPhoto(onPicked: (uri: string) => void) {
  Alert.alert('Add Photo', undefined, [
    { text: 'Cancel', style: 'cancel' },
    { text: 'Choose from Library', onPress: async () => { const uri = await launch(false); if (uri) onPicked(uri); } },
    { text: 'Take Photo', onPress: async () => { const uri = await launch(true); if (uri) onPicked(uri); } },
  ]);
}
