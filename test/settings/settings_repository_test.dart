import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/admin_shared/data/admin_api_client.dart';
import 'package:water_project/features/settings/data/datasources/settings_remote_data_source.dart';
import 'package:water_project/features/settings/data/models/settings_models.dart';
import 'package:water_project/features/settings/data/repositories/settings_repository_impl.dart';
import 'package:water_project/features/settings/domain/entities/settings_entities.dart';

import '../support/fake_admin_api_client.dart';
import '../support/memory_settings_cache.dart';

void main() {
  late FakeAdminApiClient api;
  late MemorySettingsCache cache;
  late SettingsRepositoryImpl repository;

  setUp(() {
    api = FakeAdminApiClient();
    cache = MemorySettingsCache();
    repository = SettingsRepositoryImpl(SettingsRemoteDataSource(api), cache);
  });

  tearDown(() => cache.dispose());

  test('saving WhatsApp publishes operational templates but not the token', () async {
    final settings = WhatsAppSettingsModel.fromJson({
      'whatsapp_enabled': true,
      'whatsapp_method': 'local_api',
      'whatsapp_invoice_message': 'new invoice',
      'whatsapp_access_token': 'do-not-cache',
    });
    SettingsCacheEvent? event;
    final subscription = cache.changes.listen((value) => event = value);

    await repository.saveWhatsAppSettings(settings);

    expect(cache.values['reading_invoice_message_template'], 'new invoice');
    expect(cache.values, isNot(contains('whatsapp_access_token')));
    expect(event?.section, SettingsSection.whatsapp);
    expect(event?.changedKeys, contains('reading_invoice_message_template'));
    final request = api.requests.single;
    expect(request.path, '/settings/whatsapp');
    expect((request.data as Map)['reading_invoice_message_template'], 'new invoice');
    await subscription.cancel();
  });

  test('missing section endpoint falls back to the legacy settings API', () async {
    api.handlers['GET:/settings/reading'] = const AdminApiException(
      'Not found',
      statusCode: 404,
    );
    api.handlers['GET:/settings'] = {
      'reading_period': 'weekly',
      'reading_day': 'last_day',
      'grace_period': '14',
    };

    final settings = await repository.getReadingSettings();

    expect(settings.readingPeriod, 'weekly');
    expect(api.requests.map((request) => request.path), ['/settings/reading', '/settings']);
  });
}
