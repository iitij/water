import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/l10n/app_strings.dart';

/// S5-04 — طبقة النصوص المركزية.
///
/// ملاحظة صدق: المشروع لا يستخدم `flutter gen-l10n` ولا `.arb`؛ هذه الطبقة
/// تُغلّف أسطح التنقل والأخطاء العامة فقط، وبقية نصوص الشاشات ما زالت عربية
/// مكتوبة داخل الشاشة (مذكور في تقرير البطاقة).
void main() {
  group('اختيار اللغة', () {
    test('العربية هي الافتراضي', () {
      expect(AppStrings.forLanguageCode(null).language, AppLanguage.ar);
      expect(AppStrings.forLanguageCode('ar').language, AppLanguage.ar);
      expect(AppStrings.forLanguageCode('fr').language, AppLanguage.ar);
    });

    test('الإنجليزية تُختار بأي منطقة (en / en-US)', () {
      expect(AppStrings.forLanguageCode('en').language, AppLanguage.en);
      expect(AppStrings.forLanguageCode('en-US').language, AppLanguage.en);
      expect(AppStrings.forLanguageCode('EN').language, AppLanguage.en);
    });
  });

  group('نصوص التنقل', () {
    test('كل وجهات التنقل الخمس لها تسمية وعنوان ووصف في اللغتين', () {
      for (final strings in <AppStrings>[AppStrings.ar, AppStrings.en]) {
        final labels = <String>[
          strings.navHome,
          strings.navField,
          strings.navFinance,
          strings.navSubscribers,
          strings.navMore,
        ];
        expect(labels.every((value) => value.trim().isNotEmpty), isTrue);
        expect(labels.toSet().length, 5, reason: 'لا تتكرر تسمية وجهتين');
        for (final text in <String>[
          strings.homeTitle,
          strings.homeSubtitle,
          strings.fieldTitle,
          strings.fieldSubtitle,
          strings.financeTitle,
          strings.financeSubtitle,
          strings.subscribersTitle,
          strings.subscribersSubtitle,
          strings.moreTitle,
          strings.moreSubtitle,
        ]) {
          expect(text.trim(), isNotEmpty);
        }
      }
    });

    test('النسخة العربية تحمل التسميات المعتمدة في v1', () {
      expect(AppStrings.ar.navHome, 'الرئيسية');
      expect(AppStrings.ar.navField, 'الميدان');
      expect(AppStrings.ar.navFinance, 'المالية');
      expect(AppStrings.ar.navSubscribers, 'المشتركون');
      expect(AppStrings.ar.navMore, 'المزيد');
    });
  });

  group('رسائل الأخطاء العامة', () {
    test('رمز الحالة يُترجم إلى رسالة مناسبة', () {
      expect(AppStrings.ar.forStatusCode(0), AppStringsDefaults.networkError);
      expect(AppStrings.ar.forStatusCode(null), AppStringsDefaults.networkError);
      expect(AppStrings.ar.forStatusCode(401), AppStringsDefaults.unauthorizedError);
      expect(AppStrings.ar.forStatusCode(403), AppStringsDefaults.forbiddenError);
      expect(AppStrings.ar.forStatusCode(404), AppStringsDefaults.notFoundError);
      expect(AppStrings.ar.forStatusCode(408), AppStringsDefaults.timeoutError);
      expect(AppStrings.ar.forStatusCode(409), AppStringsDefaults.conflictError);
      expect(AppStrings.ar.forStatusCode(500), AppStringsDefaults.serverError);
      expect(AppStrings.ar.forStatusCode(503), AppStringsDefaults.serverError);
      expect(AppStrings.ar.forStatusCode(418), AppStringsDefaults.unknownError);
    });

    test('القيم الافتراضية نفسها هي نصوص العربية', () {
      expect(AppStrings.ar.networkError, AppStringsDefaults.networkError);
      expect(AppStrings.ar.unknownError, AppStringsDefaults.unknownError);
    });

    test('الإنجليزية لا تعيد نصاً فارغاً لأي رمز', () {
      for (final status in <int?>[null, 0, 400, 401, 403, 404, 408, 409, 422, 500]) {
        expect(AppStrings.en.forStatusCode(status).trim(), isNotEmpty);
      }
    });
  });

  group('نصوص إضافات 2.0 (S8-01)', () {
    test('كل النصوص الجديدة معرّفة في اللغتين وغير فارغة', () {
      for (final strings in <AppStrings>[AppStrings.ar, AppStrings.en]) {
        final values = <String>[
          strings.devicesTitle,
          strings.devicesSubtitle,
          strings.backupTitle,
          strings.backupSubtitle,
          strings.remindersTitle,
          strings.remindersSubtitle,
          strings.dataQualityTitle,
          strings.dataQualitySubtitle,
          strings.saveAndAddAnother,
          strings.savedFiltersTitle,
          strings.savedFiltersHint,
          strings.actionRestore,
          strings.actionShare,
          strings.actionDelete,
        ];
        expect(values.every((value) => value.trim().isNotEmpty), isTrue);
      }
    });

    test('العربية تحمل المصطلحات المعتمدة في الشاشات', () {
      expect(AppStrings.ar.devicesTitle, 'الأجهزة المسجّلة');
      expect(AppStrings.ar.saveAndAddAnother, 'حفظ وإضافة آخر');
      expect(AppStrings.ar.dataQualityTitle, 'جودة البيانات');
      expect(AppStrings.ar.actionRestore, 'استعادة');
    });

    test('الإنجليزية لا تسرّب نصاً عربياً', () {
      final arabic = RegExp(r'[\u0600-\u06FF]');
      for (final text in <String>[
        AppStrings.en.devicesTitle,
        AppStrings.en.backupTitle,
        AppStrings.en.remindersTitle,
        AppStrings.en.dataQualityTitle,
        AppStrings.en.saveAndAddAnother,
        AppStrings.en.actionShare,
      ]) {
        expect(arabic.hasMatch(text), isFalse, reason: text);
      }
    });
  });

  testWidgets('of() يقرأ لغة الـ Localizations', (tester) async {
    late AppStrings arabic;
    late AppStrings english;

    Future<void> pumpWith(Locale locale) async {
      await tester.pumpWidget(
        Localizations(
          locale: locale,
          delegates: const <LocalizationsDelegate<dynamic>>[
            DefaultWidgetsLocalizations.delegate,
          ],
          child: Builder(
            builder: (context) {
              if (locale.languageCode == 'en') {
                english = AppStrings.of(context);
              } else {
                arabic = AppStrings.of(context);
              }
              return const SizedBox.shrink();
            },
          ),
        ),
      );
    }

    await pumpWith(const Locale('ar'));
    await pumpWith(const Locale('en'));

    expect(arabic.language, AppLanguage.ar);
    expect(english.language, AppLanguage.en);
  });
}
