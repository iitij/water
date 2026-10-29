import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/features/admin_shared/presentation/widgets/admin_widgets.dart';
import 'package:water_project/features/devices/domain/device_record.dart';
import 'package:water_project/features/devices/domain/repositories/devices_repository.dart';
import 'package:water_project/features/devices/presentation/pages/devices_screen.dart';
import 'package:water_project/features/global_search/data/global_search_repository.dart';
import 'package:water_project/features/global_search/data/models/global_search_models.dart';
import 'package:water_project/features/global_search/presentation/cubit/global_search_cubit.dart';
import 'package:water_project/features/global_search/presentation/pages/global_search_screen.dart';
import 'package:water_project/features/settings/domain/entities/settings_entities.dart';
import 'package:water_project/features/settings/domain/repositories/settings_repository.dart';
import 'package:water_project/features/settings/presentation/pages/general_settings_resource_screen.dart';
import 'package:water_project/features/settings/presentation/pages/reading_settings_screen.dart';
import 'package:water_project/features/settings/presentation/pages/settings_studio_screen.dart';

class _Settings extends Mock implements SettingsRepository {}
class _Search extends Mock implements GlobalSearchRepository {}
class _Devices extends Mock implements DevicesRepository {}

Widget _app(Widget child) => MaterialApp(
  locale: const Locale('ar'),
  supportedLocales: const [Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  theme: AppTheme.light,
  home: child,
);

void main() {
  for (final width in [320.0, 1440.0]) {
    Future<void> size(WidgetTester tester) async {
      tester.view.physicalSize = Size(width, 1000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
    }

    testWidgets('settings hub permissions and navigation width $width', (tester) async {
      await size(tester);
      final repository = _Settings();
      when(repository.getReadingSettings).thenAnswer((_) async => ReadingSettings.defaults());
      await tester.pumpWidget(_app(SettingsStudioScreen(repository: repository, can: (_) => true)));
      await tester.pumpAndSettle();
      expect(find.text('القراءات'), findsOneWidget);
      await tester.tap(find.text('القراءات'));
      await tester.pumpAndSettle();
      expect(find.byType(ReadingSettingsScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('settings hub denied width $width', (tester) async {
      await size(tester);
      await tester.pumpWidget(_app(SettingsStudioScreen(repository: _Settings(), can: (_) => false)));
      await tester.pumpAndSettle();
      expect(find.byType(AdminErrorView), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    for (final failure in [false, true]) {
      testWidgets('general settings empty/error=$failure width $width', (tester) async {
        await size(tester);
        final repository = _Settings();
        if (failure) {
          when(repository.getGeneralSettings).thenThrow(Exception('تعذر الاتصال للاختبار'));
        } else {
          when(repository.getGeneralSettings).thenAnswer((_) async => <GeneralSetting>[]);
        }
        await tester.pumpWidget(_app(GeneralSettingsResourceScreen(repository: repository, can: (_) => true)));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        if (failure) expect(find.byType(AdminErrorView), findsOneWidget);
      });

      testWidgets('devices empty/error=$failure width $width', (tester) async {
        await size(tester);
        final repository = _Devices();
        if (failure) {
          when(() => repository.getDevices(currentDeviceUuid: 'audit')).thenThrow(Exception('تعذر الاتصال للاختبار'));
        } else {
          when(() => repository.getDevices(currentDeviceUuid: 'audit')).thenAnswer((_) async => <DeviceRecord>[]);
        }
        await tester.pumpWidget(_app(DevicesScreen(repository: repository, currentDeviceUuid: 'audit')));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        if (failure) expect(find.byType(AdminErrorView), findsOneWidget);
      });
    }

    for (final mode in ['idle', 'empty', 'failure', 'results']) {
      testWidgets('global search $mode width $width', (tester) async {
        await size(tester);
        final repository = _Search();
        when(() => repository.search('احمد')).thenAnswer((_) async {
          if (mode == 'failure') throw Exception('تعذر الاتصال للاختبار');
          return GlobalSearchResponse(query: 'احمد', groups: mode == 'results' ? [
            const GlobalSearchGroup(type: 'subscriber', label: 'المشتركون', items: [
              GlobalSearchItem(id: 1, title: 'أحمد للاختبار'),
            ]),
          ] : []);
        });
        final cubit = GlobalSearchCubit(repository);
        addTearDown(cubit.close);
        await tester.pumpWidget(_app(BlocProvider.value(value: cubit, child: const GlobalSearchScreen())));
        if (mode != 'idle') {
          await tester.enterText(find.byType(TextField), 'احمد');
          await tester.pump(const Duration(milliseconds: 321));
        }
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        expect(find.text(switch (mode) {
          'idle' => 'ابحث في كل النظام',
          'empty' => 'لا توجد نتائج',
          'failure' => 'تعذّر تنفيذ البحث',
          _ => 'أحمد للاختبار',
        }), findsOneWidget);
      });
    }
  }
}
