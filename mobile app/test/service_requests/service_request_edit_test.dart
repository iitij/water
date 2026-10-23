import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/features/service_requests/domain/entities/service_request.dart';
import 'package:water_project/features/service_requests/domain/repositories/service_requests_repository.dart';
import 'package:water_project/features/service_requests/presentation/cubit/service_requests_cubit.dart';
import 'package:water_project/features/service_requests/presentation/widgets/service_request_edit_sheet.dart';

class _Repository extends Mock implements ServiceRequestsRepository {}

const _request = ServiceRequest(
  id: 7, subscriberId: 12, subscriberName: 'أحمد', amount: 5000,
  serviceDate: '2026-10-23', status: TankerStatus.pending, notes: 'old',
);

Future<void> _open(WidgetTester tester, Future<void> Function(ServiceRequest) save) async {
  await tester.pumpWidget(MaterialApp(home: Scaffold(body: Builder(
    builder: (context) => TextButton(
      onPressed: () => ServiceRequestEditSheet.show(context, request: _request, onSave: save),
      child: const Text('open'),
    ),
  ))));
  await tester.tap(find.text('open'));
  await tester.pumpAndSettle();
}

void main() {
  test('billed or paid requests are locked; notes and dates participate in equality', () {
    const locked = ServiceRequest(id: 7, subscriberId: 12, amount: 5000,
      serviceDate: '2026-10-23', status: TankerStatus.completed, invoiceId: 3);
    expect(locked.canEdit, isFalse);
    expect(locked.canInvoice, isFalse);
    const changed = ServiceRequest(id: 7, subscriberId: 12, subscriberName: 'أحمد',
      amount: 5000, serviceDate: '2026-10-23', notes: 'new');
    expect(changed, isNot(_request));
  });

  test('editing uses the repository update and refreshes the active filters', () async {
    final repo = _Repository();
    when(() => repo.updateRequest(_request)).thenAnswer((_) async => _request);
    when(() => repo.getRequests(status: null, subscriberId: 12, from: null, until: null))
        .thenAnswer((_) async => const TankerPage(requests: [_request], totalSum: 5000));
    final cubit = ServiceRequestsCubit(repo)..subscriberId = 12;
    addTearDown(cubit.close);
    await cubit.updateRequest(_request);
    verify(() => repo.updateRequest(_request)).called(1);
    expect(cubit.state.requests, [_request]);
  });

  test('server failures propagate to the form, not false success', () async {
    final repo = _Repository();
    when(() => repo.updateRequest(_request)).thenThrow(StateError('offline'));
    final cubit = ServiceRequestsCubit(repo);
    addTearDown(cubit.close);
    await expectLater(cubit.updateRequest(_request), throwsStateError);
  });

  testWidgets('form validates amount and sends edited notes with stable identity', (tester) async {
    ServiceRequest? saved;
    await _open(tester, (request) async { saved = request; });
    final fields = find.byType(TextFormField);
    await tester.enterText(fields.first, '0');
    await tester.ensureVisible(find.text('حفظ التعديلات'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('حفظ التعديلات'));
    await tester.pumpAndSettle();
    expect(find.text('أدخل مبلغاً أكبر من صفر'), findsOneWidget);
    expect(saved, isNull);
    await tester.enterText(fields.first, '٦٠٠٠٫٥');
    await tester.enterText(fields.last, 'new note');
    await tester.ensureVisible(find.text('حفظ التعديلات'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('حفظ التعديلات'));
    await tester.pumpAndSettle();
    expect(saved?.id, 7);
    expect(saved?.subscriberId, 12);
    expect(saved?.amount, 6000.5);
    expect(saved?.notes, 'new note');
    expect(saved?.serviceDate, '2026-10-23');
    expect(find.text('تعديل طلب الخدمة'), findsNothing);
  });

  testWidgets('saving is single-flight and a server conflict stays visible', (tester) async {
    var calls = 0;
    final pending = Completer<void>();
    await _open(tester, (_) { calls++; return pending.future; });
    await tester.ensureVisible(find.text('حفظ التعديلات'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('حفظ التعديلات'));
    await tester.pump();
    expect(calls, 1);
    expect(find.text('جارٍ الحفظ…'), findsOneWidget);
    pending.completeError(DioException(
      requestOptions: RequestOptions(path: '/service-requests/7'),
      response: Response(requestOptions: RequestOptions(path: '/service-requests/7'),
        statusCode: 409, data: {'message': 'لا يمكن تعديل طلب مرتبط بفاتورة.'}),
    ));
    await tester.pumpAndSettle();
    expect(find.text('لا يمكن تعديل طلب مرتبط بفاتورة.'), findsOneWidget);
    expect(find.text('تعديل طلب الخدمة'), findsOneWidget);
    expect(find.text('حفظ التعديلات'), findsOneWidget);
  });
}
