import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:water_project/features/settings/data/datasources/settings_local_data_source.dart';
import 'package:water_project/features/settings/domain/entities/settings_entities.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('cache survives rehydration, publishes keys, and never persists Meta secrets', () async {
    SharedPreferences.setMockInitialValues({});
    final cache = SharedPreferencesSettingsCache();
    await cache.rehydrate();
    SettingsCacheEvent? event;
    final subscription = cache.changes.listen((value) => event = value);

    await cache.merge(SettingsSection.whatsapp, {
      'whatsapp_method': 'local_api',
      'reading_invoice_message_template': 'hello',
      'whatsapp_access_token': 'secret',
      'access_token': 'also-secret',
    });

    expect(event?.changedKeys, contains('reading_invoice_message_template'));
    expect(event?.changedKeys, isNot(contains('whatsapp_access_token')));

    final restored = SharedPreferencesSettingsCache();
    await restored.rehydrate();
    expect(await restored.read('whatsapp_method'), 'local_api');
    expect(await restored.read('reading_invoice_message_template'), 'hello');
    expect(await restored.read('whatsapp_access_token'), isNull);
    expect(await restored.read('access_token'), isNull);

    await restored.remove(SettingsSection.whatsapp, 'whatsapp_method');
    expect(await restored.read('whatsapp_method'), isNull);
    await subscription.cancel();
    await cache.dispose();
    await restored.dispose();
  });
}
