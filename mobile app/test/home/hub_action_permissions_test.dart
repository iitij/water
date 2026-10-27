import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/core/permissions/permission_service.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/features/navigation/presentation/pages/billing_hub_screen.dart';
import 'package:water_project/features/navigation/presentation/pages/crm_hub_screen.dart';
import 'package:water_project/features/navigation/presentation/pages/field_hub_screen.dart';
import 'package:water_project/features/offline_sync/services/sync_queue_manager.dart';

class _Session extends Mock implements SessionService {}

class _Queue extends Mock implements SyncQueueManager {}

void main() {
  late _Session session;
  setUp(() {
    session = _Session();
    GetIt.I.registerSingleton<PermissionService>(PermissionService(session));
    final queue = _Queue();
    when(() => queue.isOnline).thenReturn(true);
    when(() => queue.pendingCount).thenReturn(0);
    when(() => queue.failedCount).thenReturn(0);
    when(() => queue.isSyncing).thenReturn(false);
    GetIt.I.registerSingleton<SyncQueueManager>(queue);
  });
  tearDown(() => GetIt.I.reset());

  Future<void> mount(
    WidgetTester tester,
    Widget hub,
    List<String> grants,
  ) async {
    when(
      () => session.user,
    ).thenReturn({'roles': <String>[], 'permissions': grants});
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: Scaffold(body: hub),
      ),
    );
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  }

  testWidgets('subscriber reader can search but cannot add from the CRM hero', (
    tester,
  ) async {
    await mount(tester, const CrmHubScreen(), ['ViewAny:Subscriber']);
    expect(find.text('إضافة مشترك جديد'), findsNothing);
    expect(find.text('بحث في المشتركين'), findsOneWidget);
  });

  testWidgets('meter reader cannot open reading creation from the field hero', (
    tester,
  ) async {
    await mount(tester, const FieldHubScreen(), ['ViewAny:Meter']);
    expect(find.text('بدء جولة القراءة'), findsNothing);
    expect(find.text('سجل القراءات'), findsNothing);
  });

  testWidgets(
    'field creation requires meter lookup as well as create permission',
    (tester) async {
      await mount(tester, const FieldHubScreen(), ['Create:Reading']);
      expect(find.text('بدء جولة القراءة'), findsNothing);
      await mount(tester, const FieldHubScreen(), [
        'Create:Reading',
        'ViewAny:Meter',
        'ViewAny:Subscriber',
      ]);
      expect(find.text('بدء جولة القراءة'), findsOneWidget);
    },
  );

  testWidgets(
    'invoice viewer can browse but cannot collect from the finance hero',
    (tester) async {
      await mount(tester, const BillingHubScreen(), ['ViewAny:Invoice']);
      expect(find.text('بدء التحصيل السريع'), findsNothing);
      expect(find.text('قائمة الفواتير'), findsOneWidget);
    },
  );

  testWidgets(
    'collection hero requires invoice lookup as well as create permission',
    (tester) async {
      await mount(tester, const BillingHubScreen(), ['Create:Payment']);
      expect(find.text('بدء التحصيل السريع'), findsNothing);
      await mount(tester, const BillingHubScreen(), [
        'Create:Payment',
        'ViewAny:Invoice',
      ]);
      expect(find.text('بدء التحصيل السريع'), findsOneWidget);
    },
  );
}
