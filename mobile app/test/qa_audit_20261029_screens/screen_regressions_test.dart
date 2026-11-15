import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/features/devices/domain/device_record.dart';
import 'package:water_project/features/devices/domain/repositories/devices_repository.dart';
import 'package:water_project/features/devices/presentation/cubit/devices_cubit.dart';
import 'package:water_project/features/global_search/data/global_search_repository.dart';
import 'package:water_project/features/global_search/data/models/global_search_models.dart';
import 'package:water_project/features/global_search/presentation/cubit/global_search_cubit.dart';
import 'package:water_project/features/settings/domain/entities/settings_entities.dart';
import 'package:water_project/features/settings/domain/repositories/settings_repository.dart';
import 'package:water_project/features/settings/presentation/cubit/general_settings_cubit.dart';
import 'package:water_project/features/settings/presentation/cubit/reading_settings_cubit.dart';
import 'package:water_project/features/settings/presentation/cubit/template_settings_cubit.dart';
import 'package:water_project/features/settings/presentation/cubit/whatsapp_settings_cubit.dart';
import 'package:water_project/features/settings/presentation/pages/reading_settings_screen.dart';
import 'package:water_project/features/settings/presentation/pages/settings_studio_screen.dart';
import 'package:water_project/features/squares/domain/entities/square.dart';
import 'package:water_project/features/squares/domain/repositories/squares_repository.dart';
import 'package:water_project/features/squares/presentation/cubit/squares_cubit.dart';

class _Settings extends Mock implements SettingsRepository {}
class _Search extends Mock implements GlobalSearchRepository {}
class _Devices extends Mock implements DevicesRepository {}
class _Squares extends Mock implements SquaresRepository {}

// Audit reproductions intentionally assert the intended behavior. Failures are
// evidence of unresolved bugs; no production implementation is changed here.
void main() {
  group('Known bug probes', () {
  testWidgets('new search text invalidates an old in-flight response immediately', (tester) async {
    final repository = _Search();
    final oldResponse = Completer<GlobalSearchResponse>();
    when(() => repository.search('ahmad')).thenAnswer((_) => oldResponse.future);
    final cubit = GlobalSearchCubit(repository);
    addTearDown(cubit.close);
    cubit.search('ahmad');
    await tester.pump(const Duration(milliseconds: 321));
    cubit.search('salem');
    oldResponse.complete(const GlobalSearchResponse(query: 'ahmad', groups: [
      GlobalSearchGroup(type: 'subscriber', label: 'المشتركون', items: [
        GlobalSearchItem(id: 1, title: 'أحمد'),
      ]),
    ]));
    await tester.pump();
    expect(cubit.state.query, 'salem');
    expect(cubit.state.loading, isTrue,
        reason: 'The old query must not finish the pending new query.');
    expect(cubit.state.groups, isEmpty,
        reason: 'Old-query records must not appear under new search text.');
  });

  test('leaving reading settings while loading does not throw after close', () async {
    final repository = _Settings();
    final response = Completer<ReadingSettings>();
    when(repository.getReadingSettings).thenAnswer((_) => response.future);
    final cubit = ReadingSettingsCubit(repository);
    final pending = cubit.load();
    await cubit.close();
    response.complete(ReadingSettings.defaults());
    await expectLater(pending, completes);
  });

  for (final kind in TemplateKind.values) {
    test('leaving ${kind.name} template while loading does not throw after close', () async {
      final repository = _Settings();
      final response = Completer<TemplateSettings>();
      when(() => repository.getTemplateSettings(kind)).thenAnswer((_) => response.future);
      final cubit = TemplateSettingsCubit(repository, kind);
      final pending = cubit.load();
      await cubit.close();
      response.complete(TemplateSettings.defaults(kind));
      await expectLater(pending, completes);
    });
  }

  test('leaving device management while loading does not throw after close', () async {
    final repository = _Devices();
    final response = Completer<List<DeviceRecord>>();
    when(() => repository.getDevices(currentDeviceUuid: 'local')).thenAnswer((_) => response.future);
    final cubit = DevicesCubit(repository: repository, currentDeviceUuid: 'local');
    final pending = cubit.load();
    await cubit.close();
    response.complete([]);
    await expectLater(pending, completes);
  });

  test('leaving general settings while loading does not throw after close', () async {
    final repository = _Settings();
    final response = Completer<List<GeneralSetting>>();
    when(repository.getGeneralSettings).thenAnswer((_) => response.future);
    final cubit = GeneralSettingsCubit(repository);
    final pending = cubit.load();
    await cubit.close();
    response.complete([]);
    await expectLater(pending, completes);
  });

  test('leaving WhatsApp settings while loading does not throw after close', () async {
    final repository = _Settings();
    final response = Completer<WhatsAppSettings>();
    when(repository.getWhatsAppSettings).thenAnswer((_) => response.future);
    final cubit = WhatsAppSettingsCubit(repository);
    final pending = cubit.load();
    await cubit.close();
    response.complete(WhatsAppSettings.defaults());
    await expectLater(pending, completes);
  });

  test('leaving squares while loading does not throw after close', () async {
    final repository = _Squares();
    final response = Completer<List<Square>>();
    when(() => repository.getSquares(search: '')).thenAnswer((_) => response.future);
    final cubit = SquaresCubit(repository);
    final pending = cubit.load();
    await cubit.close();
    response.complete([]);
    await expectLater(pending, completes);
  });

  testWidgets('settings section shortcut opens reading settings directly', (tester) async {
    final repository = _Settings();
    when(repository.getReadingSettings).thenAnswer((_) async => ReadingSettings.defaults());
    await tester.pumpWidget(MaterialApp(home: SettingsStudioScreen(
      repository: repository, can: (_) => true, initialSection: 'reading',
    )));
    await tester.pumpAndSettle();
    expect(find.byType(ReadingSettingsScreen), findsOneWidget,
        reason: 'The section query should select the requested settings center.');
  });
  }, skip: !const bool.fromEnvironment('RUN_KNOWN_BUG_PROBES'));
}
