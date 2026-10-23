import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/settings/data/datasources/settings_remote_data_source.dart';
import 'package:water_project/features/settings/data/repositories/settings_repository_impl.dart';
import 'package:water_project/features/settings/presentation/cubit/whatsapp_settings_cubit.dart';
import 'package:water_project/features/settings/presentation/widgets/local_server_control_panel.dart';

import '../support/fake_admin_api_client.dart';
import '../support/memory_settings_cache.dart';

void main() {
  testWidgets('renders Base64 QR and polls the local server every three seconds', (tester) async {
    final api = FakeAdminApiClient();
    api.handlers['GET:/whatsapp/local-status'] = {
      'connected': false,
      'qr': 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL9WQAAAABJRU5ErkJggg==',
      'whatsappLogs': ['ready'],
      'queueLogs': ['queue ready'],
    };
    final cache = MemorySettingsCache();
    final repository = SettingsRepositoryImpl(SettingsRemoteDataSource(api), cache);
    final cubit = WhatsAppSettingsCubit(repository);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: BlocProvider.value(
            value: cubit,
            child: const SingleChildScrollView(child: LocalServerControlPanel()),
          ),
        ),
      ),
    );
    await tester.pump();

    expect(find.byKey(const ValueKey('whatsapp-qr-image')), findsOneWidget);
    expect(api.requests.where((request) => request.path == '/whatsapp/local-status'), isNotEmpty);

    final before = api.requests.length;
    await tester.pump(const Duration(seconds: 3));
    await tester.pump();
    expect(api.requests.length, greaterThan(before));

    await tester.pumpWidget(const SizedBox.shrink());
    await cubit.close();
    await cache.dispose();
  });

  testWidgets('invalid Base64 does not throw during image construction', (tester) async {
    final api = FakeAdminApiClient();
    api.handlers['GET:/whatsapp/local-status'] = {
      'connected': false,
      'qr': 'not-valid-base64',
    };
    final cache = MemorySettingsCache();
    final repository = SettingsRepositoryImpl(SettingsRemoteDataSource(api), cache);
    final cubit = WhatsAppSettingsCubit(repository);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: BlocProvider.value(
            value: cubit,
            child: const SingleChildScrollView(child: LocalServerControlPanel()),
          ),
        ),
      ),
    );
    await tester.pump();

    expect(tester.takeException(), isNull);
    expect(find.text('صورة QR غير صالحة. سيُعاد تحميلها تلقائياً.'), findsOneWidget);

    await tester.pumpWidget(const SizedBox.shrink());
    await cubit.close();
    await cache.dispose();
  });
}
