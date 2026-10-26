import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/core/ui/app_surfaces.dart';

void main() {
  testWidgets('amount fits an invoice summary in a horizontal row', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.dark,
        home: const Scaffold(
          body: Directionality(
            textDirection: TextDirection.rtl,
            child: SizedBox(
              width: 320,
              child: Row(
                children: [
                  Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text('الإجمالي'),
                      AppAmount(value: '16,000 ريال'),
                    ],
                  ),
                  SizedBox(width: 16),
                  Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text('المسدد'),
                      AppAmount(value: '0 ريال'),
                    ],
                  ),
                  Spacer(),
                  Icon(Icons.payments_outlined),
                ],
              ),
            ),
          ),
        ),
      ),
    );
    expect(tester.takeException(), isNull);
  });
}
