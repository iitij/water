import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/features/meters/data/models/meter.dart';
import 'package:water_project/features/meters/domain/meters_repository.dart';
import 'package:water_project/features/meters/presentation/pages/meter_replacement_dialog.dart';

class _Repository extends Mock implements MetersRepository {}

void main() {
  testWidgets('replacement requires valid input and posts exactly one atomic request', (tester) async {
    final repository = _Repository();
    const original = Meter(
      id: 15,
      uniqueCode: 'OLD-15',
      subscriberId: 3,
      pathNumber: 7,
    );
    const replacement = Meter(
      id: 16,
      uniqueCode: 'NEW-15',
      subscriberId: 3,
      pathNumber: 7,
    );

    when(() => repository.replaceMeter(
      meterId: 15,
      uniqueCode: 'NEW-15',
      reason: 'عداد تالف',
      removedAt: any(named: 'removedAt'),
      initialReading: 100.25,
      pathNumber: 7,
    )).thenAnswer((_) async => replacement);

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => TextButton(
            onPressed: () => showDialog<Meter>(
              context: context,
              builder: (_) => MeterReplacementDialog(
                meter: original,
                repository: repository,
              ),
            ),
            child: const Text('فتح'),
          ),
        ),
      ),
    ));

    await tester.tap(find.text('فتح'));
    await tester.pumpAndSettle();
    expect(find.textContaining('OLD-15'), findsOneWidget);

    await tester.tap(find.text('استبدال العداد'));
    await tester.pump();
    expect(find.text('أدخل رقم العداد الجديد'), findsOneWidget);
    verifyNever(() => repository.replaceMeter(
      meterId: any(named: 'meterId'),
      uniqueCode: any(named: 'uniqueCode'),
      reason: any(named: 'reason'),
      removedAt: any(named: 'removedAt'),
      initialReading: any(named: 'initialReading'),
      pathNumber: any(named: 'pathNumber'),
    ));

    await tester.enterText(
      find.widgetWithText(TextFormField, 'رقم العداد الجديد'),
      'NEW-15',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'القراءة الابتدائية للعداد الجديد'),
      '100.25',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'سبب الاستبدال'),
      'عداد تالف',
    );
    await tester.tap(find.text('استبدال العداد'));
    await tester.pumpAndSettle();

    expect(find.byType(MeterReplacementDialog), findsNothing);
    verify(() => repository.replaceMeter(
      meterId: 15,
      uniqueCode: 'NEW-15',
      reason: 'عداد تالف',
      removedAt: any(named: 'removedAt'),
      initialReading: 100.25,
      pathNumber: 7,
    )).called(1);
  });
}
