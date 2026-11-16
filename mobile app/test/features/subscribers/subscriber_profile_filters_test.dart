import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/ui/list_presentation_controls.dart';
import 'package:water_project/features/subscribers/domain/entities/subscriber.dart';
import 'package:water_project/features/subscribers/domain/repositories/subscribers_repository.dart';
import 'package:water_project/features/subscribers/presentation/cubit/subscriber360_cubit.dart';
import 'package:water_project/features/subscribers/presentation/pages/subscriber_360_screen.dart';

class _Subscribers extends Mock implements SubscribersRepository {}


const _subscriber = Subscriber(
  id: 1,
  name: 'مشترك المسارين',
  squareId: 4,
  squareName: 'حي النور',
  pathNumbers: [1, 10],
);

class _Detail extends Subscriber360Cubit {
  _Detail() : super(_Subscribers()) {
    emit(
      const Subscriber360State(
        detail: Subscriber360(
          subscriber: _subscriber,
          summary: FinancialSummary(
            meterBalances: [
              MeterBalance(meterId: 1, uniqueCode: 'M-1', pathNumber: 1),
              MeterBalance(meterId: 2, uniqueCode: 'M-10', pathNumber: 10),
            ],
          ),
          invoices: [
            {
              'id': 1,
              'bill_number': 'INV-FIRST',
              'meter_id': 1,
              'created_at': '2026-11-02T10:00:00',
              'total_amount': 10,
            },
            {
              'id': 2,
              'bill_number': 'INV-SECOND',
              'meter_id': 2,
              'created_at': '2026-11-03T23:59:59',
              'total_amount': 20,
            },
          ],
        ),
      ),
    );
  }
}

void main() {
  for (final width in [390.0, 1280.0]) {
    testWidgets(
      'profile invoice tab combines exact route and addition period at $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 1200);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final detail = _Detail();
        addTearDown(detail.close);
        await tester.pumpWidget(
          MaterialApp(
            locale: const Locale('ar'),
            supportedLocales: const [Locale('ar')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
            home: MultiBlocProvider(
              providers: [
                BlocProvider<Subscriber360Cubit>.value(value: detail),
              ],
              child: Subscriber360Screen(
                subscriber: _subscriber,
                onAddMeter: (_, _) {},
                onRecordReading: (_, _) {},
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.text('الفواتير').first);
        await tester.tap(find.text('الفواتير').first);
        await tester.pumpAndSettle();
        final finder = find.byType(ListPresentationControls).hitTestable();
        final controls = tester.widget<ListPresentationControls>(finder);
        controls.onChanged(
          controls.settings.copyWith(
            pathNumber: 10,
            createdFrom: DateTime(2026, 11, 3),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('INV-SECOND'), findsOneWidget);
        expect(find.text('INV-FIRST'), findsNothing);
        final next = tester.widget<ListPresentationControls>(finder);
        next.onChanged(next.settings.copyWith(pathNumber: 1));
        await tester.pumpAndSettle();
        expect(find.text('INV-SECOND'), findsNothing);
        expect(find.text('INV-FIRST'), findsNothing);
        final empty = tester.widget<ListPresentationControls>(finder);
        empty.onChanged(empty.settings.copyWith(clearFilters: true));
        await tester.pumpAndSettle();
        expect(find.text('INV-FIRST'), findsOneWidget);
        expect(find.text('INV-SECOND'), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
