import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/error/failures.dart';
import 'package:water_project/core/l10n/app_strings.dart';
import 'package:water_project/core/permissions/permission_service.dart';
import 'package:water_project/features/cash_sessions/data/cash_session_api.dart';
import 'package:water_project/features/cash_sessions/domain/cash_session.dart';
import 'package:water_project/features/cash_sessions/domain/cash_session_page.dart';
import 'package:water_project/features/cash_sessions/domain/cash_session_review_permissions.dart';
import 'package:water_project/features/cash_sessions/presentation/cash_session_review_screen.dart';
import 'package:water_project/features/cash_sessions/services/cash_session_service.dart';

class _Permissions extends Mock implements PermissionService {}

Future<void> _frames(WidgetTester tester) async {
  // The row remains busy while its decision dialog is open.
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 450));
}

CashSession _session(int id, CashSessionState state) => CashSession(
  id: id, state: state, sessionDate: DateTime(2026, 10, 26),
  openingAmount: 20, expectedAmount: 100, countedAmount: 95,
  varianceAmount: -5, receipts: 2, collector: 'المحصّل أحمد',
);

class _ReviewService extends CashSessionService {
  _ReviewService() : super(api: CashSessionApi(Dio()));

  List<CashSession> rows = [_session(42, CashSessionState.closed)];
  int listCalls = 0;
  int decisions = 0;
  CashSessionFilters? lastFilters;
  String? decisionNotes;
  bool? decision;
  AppFailure? decisionError;
  Completer<void>? decisionWait;

  @override
  Future<CashSessionPage> list({CashSessionFilters filters = const CashSessionFilters(), int page = 1}) async {
    listCalls++;
    lastFilters = filters;
    return CashSessionPage(sessions: rows, currentPage: page, lastPage: 1, total: rows.length);
  }

  @override
  Future<CashSession> byId(int id) async => rows.firstWhere((row) => row.id == id);

  @override
  Future<CashSession> approve(int id, {required bool approve, String? notes}) async {
    decisions++;
    decisionNotes = notes;
    decision = approve;
    if (decisionWait != null) await decisionWait!.future;
    if (decisionError != null) throw decisionError!;
    rows = [];
    return _session(id, approve ? CashSessionState.approved : CashSessionState.rejected);
  }
}

void main() {
  late _Permissions permissions;
  late _ReviewService service;
  final strings = AppStrings.ar;

  void grants(Set<String> names, {bool admin = false}) {
    when(() => permissions.isAdmin).thenReturn(admin);
    when(() => permissions.currentPermissions).thenReturn(names);
    when(() => permissions.can(any())).thenAnswer((call) => admin || names.contains(call.positionalArguments.first));
  }

  setUp(() {
    permissions = _Permissions();
    service = _ReviewService();
    grants({});
  });

  Future<void> mount(WidgetTester tester) async {
    tester.view.physicalSize = const Size(900, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: Directionality(
      textDirection: TextDirection.rtl,
      child: CashSessionReviewScreen(service: service, permissions: permissions),
    )));
    await _frames(tester);
  }

  test('review and decision grants stay separate', () {
    grants({'ViewAny:CashSession'});
    expect(CashSessionReviewPermissions.canView(permissions), isTrue);
    expect(CashSessionReviewPermissions.canDecide(permissions), isFalse);
    grants({'cash_sessions.approve'});
    expect(CashSessionReviewPermissions.canView(permissions), isTrue);
    expect(CashSessionReviewPermissions.canDecide(permissions), isTrue);
    grants({});
    expect(CashSessionReviewPermissions.canView(permissions), isFalse);
  });

  testWidgets('unauthorized direct entry never loads financial summaries', (tester) async {
    await mount(tester);
    expect(service.listCalls, 0);
    expect(find.textContaining('المحصّل أحمد'), findsNothing);
  });

  testWidgets('view-only access shows summaries without approval controls', (tester) async {
    grants({'ViewAny:CashSession'});
    await mount(tester);
    expect(service.listCalls, 1);
    expect(find.textContaining('المحصّل أحمد'), findsOneWidget);
    expect(find.text(strings.cashReviewApprove), findsNothing);
    expect(find.text(strings.cashReviewReject), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('only pending closed sessions expose decisions', (tester) async {
    grants({'Approve:CashSession'});
    service.rows = [_session(1, CashSessionState.open), _session(2, CashSessionState.approved), _session(3, CashSessionState.rejected)];
    await mount(tester);
    expect(find.text(strings.cashReviewApprove), findsNothing);
    expect(find.text(strings.cashReviewReject), findsNothing);
  });

  testWidgets('reject validates a reason and retains it after a server failure', (tester) async {
    grants({'cash_sessions.approve'});
    service.decisionError = const ServerFailure('تعذر حفظ القرار على الخادم');
    await mount(tester);
    await tester.ensureVisible(find.text(strings.cashReviewReject));
    await tester.tap(find.text(strings.cashReviewReject));
    await _frames(tester);
    final dialog = find.byType(AlertDialog);
    final reject = find.descendant(of: dialog, matching: find.widgetWithText(FilledButton, strings.cashReviewReject));
    await tester.tap(reject);
    await _frames(tester);
    expect(service.decisions, 0);
    expect(find.text(strings.cashReviewReasonRequired), findsOneWidget);
    const reason = 'هناك فرق نقدي يحتاج مراجعة المستندات';
    await tester.enterText(find.descendant(of: dialog, matching: find.byType(TextFormField)), reason);
    await tester.tap(reject);
    await _frames(tester);
    expect(service.decisions, 1);
    expect(service.decision, isFalse);
    expect(service.decisionNotes, reason);
    expect(dialog, findsOneWidget);
    expect(find.text(reason), findsOneWidget);
    expect(find.text('تعذر حفظ القرار على الخادم'), findsOneWidget);
  });

  testWidgets('successful decision refreshes the server list and blocks duplicate taps', (tester) async {
    grants({'Approve:CashSession'});
    service.decisionWait = Completer<void>();
    await mount(tester);
    await tester.ensureVisible(find.text(strings.cashReviewApprove));
    await tester.tap(find.text(strings.cashReviewApprove));
    await _frames(tester);
    final approve = find.descendant(of: find.byType(AlertDialog), matching: find.widgetWithText(FilledButton, strings.cashReviewApprove));
    await tester.tap(approve);
    await tester.pump();
    await tester.tap(approve);
    await tester.pump();
    expect(service.decisions, 1);
    service.decisionWait!.complete();
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsNothing);
    expect(service.listCalls, 2);
    expect(find.text(strings.cashReviewEmpty), findsOneWidget);
  });

  testWidgets('collector filter accepts Arabic digits', (tester) async {
    grants({'ViewAny:CashSession'});
    await mount(tester);
    await tester.enterText(find.byType(TextField).first, '١٢');
    await tester.tap(find.text(strings.cashReviewApplyFilters));
    await tester.pumpAndSettle();
    expect(service.lastFilters?.collectorId, 12);
  });

  testWidgets('pending cash review fits narrow RTL screens and large text', (tester) async {
    grants({'Approve:CashSession'});
    tester.view.physicalSize = const Size(360, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: MediaQuery(
      data: const MediaQueryData(size: Size(360, 900), textScaler: TextScaler.linear(1.5)),
      child: Directionality(textDirection: TextDirection.rtl, child: CashSessionReviewScreen(service: service, permissions: permissions)),
    )));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text(strings.cashReviewReject));
    expect(tester.takeException(), isNull);
  });
}
