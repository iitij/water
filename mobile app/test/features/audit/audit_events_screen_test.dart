import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/error/failures.dart';
import 'package:water_project/core/l10n/app_strings.dart';
import 'package:water_project/core/permissions/permission_service.dart';
import 'package:water_project/features/admin_shared/data/admin_api_client.dart';
import 'package:water_project/features/audit/data/audit_events_repository.dart';
import 'package:water_project/features/audit/presentation/audit_events_screen.dart';

import '../../support/fake_admin_api_client.dart';

class _Permissions extends Mock implements PermissionService {}

class _Repository extends AuditEventsRepository {
  _Repository() : super(FakeAdminApiClient());
  final requestedPages = <int>[];
  final filters = <AuditEventFilters>[];
  AdminApiException? error;
  Completer<AuditEventsPage>? pending;

  @override
  Future<AuditEventsPage> fetch(AuditEventFilters filter, {int page = 1}) async {
    requestedPages.add(page);
    filters.add(filter);
    if (error != null) throw error!;
    if (pending != null) return pending!.future;
    return AuditEventsPage.fromJson({
      'data': [{
        'id': page, 'event': page == 1 ? 'payment.voided' : 'cash_session.closed',
        'actor': 'المشرف أحمد', 'actor_user_id': 9, 'source': 'web',
        'entity_type': 'Payment', 'entity_id': '41', 'ip_address': '192.0.2.1',
        'occurred_at': '2026-10-26T09:00:00Z', 'before': {'amount': 20}, 'after': {'amount': 0},
      }],
      'current_page': page, 'last_page': 2, 'total': 2,
      'filter_options': {'actors': [{'id': 9, 'name': 'المشرف أحمد'}], 'events': ['payment.voided', 'cash_session.closed'], 'entity_types': ['Payment']},
    });
  }
}

void main() {
  late _Permissions permissions;
  late _Repository repository;
  final strings = AppStrings.ar;
  var allowed = true;

  setUp(() {
    allowed = true;
    permissions = _Permissions();
    repository = _Repository();
    when(() => permissions.isAdmin).thenReturn(false);
    when(() => permissions.can(any())).thenAnswer((call) => allowed && call.positionalArguments.first == 'audit.view');
  });

  Future<void> mount(WidgetTester tester, {double width = 900, double scale = 1}) async {
    tester.view.physicalSize = Size(width, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: MediaQuery(
      data: MediaQueryData(size: Size(width, 1000), textScaler: TextScaler.linear(scale)),
      child: Directionality(textDirection: TextDirection.rtl,
        child: AuditEventsScreen(repository: repository, permissions: permissions)),
    )));
    await tester.pumpAndSettle();
  }

  testWidgets('direct entry without audit permission never fetches data', (tester) async {
    allowed = false;
    await mount(tester);
    expect(repository.requestedPages, isEmpty);
    expect(find.text(strings.forbiddenError), findsOneWidget);
  });

  testWidgets('actor name and central event details survive the API string shape', (tester) async {
    await mount(tester);
    expect(find.textContaining('المشرف أحمد'), findsOneWidget);
    await tester.tap(find.text('payment.voided'));
    await tester.pumpAndSettle();
    expect(find.text(strings.auditDetails), findsOneWidget);
    expect(find.textContaining('192.0.2.1'), findsOneWidget);
    expect(find.textContaining('المشرف أحمد'), findsNWidgets(2));
    expect(find.textContaining('"amount": 20'), findsOneWidget);
    expect(find.textContaining('"amount": 0'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('later pages are reachable and retain the applied search', (tester) async {
    await mount(tester);
    await tester.tap(find.byType(ExpansionTile));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, 'أحمد');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    await tester.tap(find.text(strings.cashReviewNext));
    await tester.pumpAndSettle();
    expect(repository.requestedPages.last, 2);
    expect(repository.filters.last.query, 'أحمد');
    expect(find.text('cash_session.closed'), findsOneWidget);
  });

  testWidgets('errors allow retry instead of leaving the screen stuck', (tester) async {
    repository.error = const AdminApiException('تعذر الوصول إلى سجل الخادم', statusCode: 503);
    await mount(tester);
    expect(find.text('تعذر الوصول إلى سجل الخادم'), findsOneWidget);
    repository.error = null;
    await tester.tap(find.text(strings.retry));
    await tester.pumpAndSettle();
    expect(find.text('payment.voided'), findsOneWidget);
    expect(repository.requestedPages, [1, 1]);
  });

  testWidgets('an in-flight response cannot expose audit data after permission loss', (tester) async {
    repository.pending = Completer<AuditEventsPage>();
    tester.view.physicalSize = const Size(900, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: AuditEventsScreen(repository: repository, permissions: permissions)));
    await tester.pump();
    allowed = false;
    repository.pending!.complete(const AuditEventsPage(events: [{'id': 1, 'event': 'private.event'}]));
    await tester.pumpAndSettle();
    expect(find.text('private.event'), findsNothing);
    expect(find.text(strings.forbiddenError), findsOneWidget);
  });

  testWidgets('expanded filters fit narrow RTL with large text', (tester) async {
    await mount(tester, width: 360, scale: 1.5);
    await tester.tap(find.byType(ExpansionTile));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.text('payment.voided'), findsOneWidget);
  });

  test('repository preserves audit filters, metadata and detailed values', () async {
    final client = FakeAdminApiClient();
    client.handlers['GET:audit-events'] = {
      'data': [{'id': 8, 'actor': 'مدقق', 'before': {'value': 1}}],
      'current_page': 2, 'last_page': 3, 'total': 51,
      'filter_options': {'events': ['payment.voided'], 'actors': [{'id': 9, 'name': 'مدقق'}]},
    };
    final filters = AuditEventFilters(query: ' مدقق ', source: 'web', event: 'payment.voided',
      actorId: 9, entityType: 'Payment', entityId: '8', fromDate: DateTime(2026, 10, 1), toDate: DateTime(2026, 10, 26));
    final result = await AuditEventsRepository(client).fetch(filters, page: 2);
    expect(client.requests.single.query, {
      'page': 2, 'per_page': 25, 'q': 'مدقق', 'source': 'web', 'event': 'payment.voided',
      'actor_user_id': 9, 'entity_type': 'Payment', 'entity_id': '8',
      'from_date': '2026-10-01', 'to_date': '2026-10-26',
    });
    expect(result.currentPage, 2);
    expect(result.total, 51);
    expect(result.events.single['before'], {'value': 1});
    expect(result.actors.single['id'], 9);
    expect(AuditEventFilters(fromDate: DateTime(2026, 10, 26), toDate: DateTime(2026, 10, 1)).validDates, isFalse);
  });

  test('malformed central-audit responses fail instead of displaying an empty successful list', () {
    for (final response in <Object?>[null, {'msg': 'blocked'},
      {'data': <Object?>[], 'current_page': 'invalid', 'last_page': 1, 'total': 0},
      {'data': [null], 'current_page': 1, 'last_page': 1, 'total': 1}]) {
      expect(() => AuditEventsPage.fromJson(response), throwsA(isA<ServerFailure>()));
    }
  });
}
