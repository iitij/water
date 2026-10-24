import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:water_project/app.dart';
import 'package:water_project/bootstrap.dart';
import 'package:water_project/core/config/app_config.dart';

/// أول اختبار تكامل (خطة 2.0 — S1، بطاقة S1-07).
///
/// يُشغَّل على جهاز/محاكي حقيقي:
/// ```
/// flutter test integration_test/app_test.dart \
///   --dart-define=API_BASE_URL=https://water.example.com/api
/// ```
/// اختبار الدخول الفعلي يحتاج خادماً حياً، ويُفعَّل فقط عند تمرير:
/// `--dart-define=TEST_EMAIL=... --dart-define=TEST_PASSWORD=...`
void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  const apiUrl = String.fromEnvironment('API_BASE_URL');
  const email = String.fromEnvironment('TEST_EMAIL');
  const password = String.fromEnvironment('TEST_PASSWORD');

  group('إقلاع التطبيق', () {
    testWidgets('يفتح شاشة الدخول بلا استثناءات', (tester) async {
      expect(apiUrl, isNotEmpty,
          reason: 'مرّر --dart-define=API_BASE_URL=https://… لتشغيل الاختبار');
      AppConfig.configureServer(apiUrl);

      await bootstrap();
      await tester.pumpWidget(const WaterApp());

      // الشاشة الأولى بعد bootstrap هي إما الإقلاع أو الدخول؛ ننتظر انتهاء
      // الانتقال ثم نتحقق من وجود الحقول.
      await tester.pump(const Duration(seconds: 1));
      await tester.pump(const Duration(seconds: 1));

      expect(find.byType(TextField), findsWidgets,
          reason: 'شاشة الدخول تعرض حقول البريد وكلمة المرور');
      expect(find.text('سجّل الدخول لمتابعة يومك'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets(
      'تسجيل الدخول يصل إلى الرئيسية',
      (tester) async {
        AppConfig.configureServer(apiUrl);
        await bootstrap();
        await tester.pumpWidget(const WaterApp());
        await tester.pump(const Duration(seconds: 1));

        await tester.enterText(find.byType(TextField).first, email);
        await tester.enterText(find.byType(TextField).last, password);
        await tester.tap(find.text('تسجيل الدخول'));
        await tester.pump(const Duration(seconds: 3));
        await tester.pump(const Duration(seconds: 3));

        expect(find.byType(TextField), findsNothing,
            reason: 'بعد الدخول تُغادر شاشة الدخول');
      },
      skip: email.isEmpty || password.isEmpty,
    );
  });
}
