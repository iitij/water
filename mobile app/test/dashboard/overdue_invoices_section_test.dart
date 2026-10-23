import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/dashboard/domain/entities/dashboard_entities.dart';
import 'package:water_project/features/dashboard/presentation/widgets/invoice_sections.dart';

void main() {
  testWidgets('shows the green celebration when no invoices are overdue', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: OverdueInvoicesSection(
            invoices: const [],
            onCollectPayment: (context, invoice) async => false,
          ),
        ),
      ),
    );

    expect(find.text('لا توجد فواتير متأخرة 🎉'), findsOneWidget);
    expect(find.byIcon(Icons.celebration_rounded), findsOneWidget);
  });

  testWidgets('delegates immediate collection and WhatsApp reminder', (tester) async {
    const invoice = DashboardInvoice(
      id: 8,
      billNumber: 'INV-8',
      subscriberName: 'أحمد',
      subscriberPhone: '777123456',
      billDate: null,
      totalAmount: 1000,
      previousBalance: 200,
      creditApplied: 0,
      amountPaid: 200,
      status: 'overdue',
    );
    var collected = 0;
    var reminded = 0;

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: OverdueInvoicesSection(
              invoices: const [invoice],
              onCollectPayment: (context, value) async {
                collected = value.id;
                return true;
              },
              onSendReminder: (context, value) async => reminded = value.id,
            ),
          ),
        ),
      ),
    );

    await tester.tap(find.text('تحصيل فوري'));
    await tester.pump();
    await tester.tap(find.text('تذكير واتساب'));
    await tester.pump();

    expect(collected, 8);
    expect(reminded, 8);
  });
}
