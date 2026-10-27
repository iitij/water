import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/l10n/resource_ux_strings.dart';
import 'package:water_project/features/squares/domain/entities/square.dart';
import 'package:water_project/features/squares/domain/repositories/squares_repository.dart';
import 'package:water_project/features/squares/presentation/cubit/squares_cubit.dart';
import 'package:water_project/features/squares/presentation/widgets/square_form_dialog.dart';
import 'package:water_project/features/service_requests/domain/entities/service_request.dart';
import 'package:water_project/features/service_requests/presentation/widgets/service_request_create_dialog.dart';
import 'package:water_project/features/subscribers/domain/entities/subscriber.dart';
import 'package:water_project/features/subscribers/domain/repositories/subscribers_repository.dart';

class _Squares extends Mock implements SquaresRepository {}

class _Subscribers extends Mock implements SubscribersRepository {}

Future<void> _open(WidgetTester tester, Widget dialog) async {
  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => TextButton(
            onPressed: () => showDialog<bool>(
              context: context,
              barrierDismissible: false,
              builder: (_) => dialog,
            ),
            child: const Text('open'),
          ),
        ),
      ),
    ),
  );
  await tester.tap(find.text('open'));
  await tester.pumpAndSettle();
}

void main() {
  test('square write remains successful when list refresh fails', () async {
    final repo = _Squares();
    when(
      () => repo.createSquare('الحي', null),
    ).thenAnswer((_) async => const Square(id: 1, name: 'الحي'));
    when(
      () => repo.getSquares(search: any(named: 'search')),
    ).thenThrow(Exception('offline'));
    final cubit = SquaresCubit(repo);
    expect(await cubit.save(name: 'الحي'), isTrue);
    expect(cubit.state.error, isNotNull);
    verify(() => repo.createSquare('الحي', null)).called(1);
    await cubit.close();
  });
  testWidgets(
    'square validates, retains failed draft and prevents duplicate save',
    (tester) async {
      var calls = 0;
      final pending = Completer<bool>();
      await _open(
        tester,
        SquareFormDialog(
          onSave: ({id, required name, description}) {
            calls++;
            expect(name, 'الحي الجديد');
            expect(description, 'قرب المسجد');
            return pending.future;
          },
        ),
      );
      await tester.tap(find.text(ResourceUxStrings.save));
      await tester.pumpAndSettle();
      expect(calls, 0);
      expect(find.text(ResourceUxStrings.squareNameRequired), findsOneWidget);
      final fields = find.byType(TextFormField);
      await tester.enterText(fields.at(0), 'الحي الجديد');
      await tester.enterText(fields.at(1), 'قرب المسجد');
      await tester.tap(find.text(ResourceUxStrings.save));
      await tester.pump();
      expect(calls, 1);
      expect(
        tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNull,
      );
      pending.complete(false);
      await tester.pumpAndSettle();
      expect(find.text(ResourceUxStrings.saveFailed), findsOneWidget);
      expect(find.text('الحي الجديد'), findsOneWidget);
      expect(find.text('قرب المسجد'), findsOneWidget);
    },
  );
  testWidgets(
    'service request retains identity and Arabic amount after failed save',
    (tester) async {
      final repo = _Subscribers();
      when(
        () => repo.getSubscribers(query: any(named: 'query')),
      ).thenAnswer((_) async => [const Subscriber(id: 12, name: 'أحمد')]);
      when(() => repo.lastTankerAmount(12)).thenAnswer((_) async => null);
      final pending = Completer<bool>();
      var calls = 0;
      ServiceRequest? draft;
      await _open(
        tester,
        ServiceRequestCreateDialog(
          repository: repo,
          onSave: (value) {
            calls++;
            draft = value;
            return pending.future;
          },
        ),
      );
      await tester.tap(find.text(ResourceUxStrings.addRequest));
      await tester.pumpAndSettle();
      expect(calls, 0);
      expect(find.text(ResourceUxStrings.subscriberRequired), findsOneWidget);
      await tester.tap(find.text('أحمد').first);
      await tester.pumpAndSettle();
      final fields = find.byType(TextFormField);
      await tester.enterText(fields.at(0), '٧٥٠٠');
      await tester.enterText(fields.at(1), 'خدمة المساء');
      await tester.ensureVisible(find.text(ResourceUxStrings.addRequest));
      await tester.tap(find.text(ResourceUxStrings.addRequest));
      await tester.pump();
      expect(calls, 1);
      expect(draft?.subscriberId, 12);
      expect(draft?.amount, 7500);
      expect(draft?.notes, 'خدمة المساء');
      expect(
        tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNull,
      );
      pending.complete(false);
      await tester.pumpAndSettle();
      expect(find.text(ResourceUxStrings.saveFailed), findsOneWidget);
      expect(find.text('خدمة المساء'), findsOneWidget);
      expect(find.text('٧٥٠٠'), findsOneWidget);
    },
  );
}
