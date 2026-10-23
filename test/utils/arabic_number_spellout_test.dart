import 'package:flutter_test/flutter_test.dart';
import 'package:water_mobile/core/utils/arabic_number_spellout.dart';

/// اختبارات التفقيط العربي المستعمل في سند القبض (PDF) والطابعة الحرارية.
/// المرجع: `resources/views/pdf/receipt.blade.php` (`المبلغ المستلم كتابتا`).
void main() {
  group('spelloutYemeniRial', () {
    test('العملة واللاحقة لا تتكرران', () {
      final text = ArabicNumberSpellout.spelloutYemeniRial(500);
      expect(text, 'خمسمئة ريال يمني لا غير');
      expect('ريال يمني'.allMatches(text).length, 1);
    });

    test('الصفر له صيغة خاصة', () {
      expect(
        ArabicNumberSpellout.spelloutYemeniRial(0),
        'صفر ريال يمني لا غير',
      );
    });

    test('التقريب إلى أقرب ريال (لا هللات في السند)', () {
      expect(
        ArabicNumberSpellout.spelloutYemeniRial(1234.4),
        ArabicNumberSpellout.spelloutYemeniRial(1234),
      );
      expect(
        ArabicNumberSpellout.spelloutYemeniRial(1234.6),
        ArabicNumberSpellout.spelloutYemeniRial(1235),
      );
    });

    test('اللاحقة البديلة للطابعة الحرارية', () {
      expect(
        ArabicNumberSpellout.spelloutYemeniRial(
          250,
          suffix: ArabicNumberSpellout.exclusiveSuffix,
        ),
        'مئتان وخمسون ريال يمني فقط لا غير',
      );
    });

    test('الآلاف تُجمع كما في الاستمارة العربية', () {
      expect(ArabicNumberSpellout.wordsOnly(1000), 'ألف');
      expect(ArabicNumberSpellout.wordsOnly(2000), 'ألفان');
      expect(ArabicNumberSpellout.wordsOnly(5000), 'خمسة آلاف');
      expect(ArabicNumberSpellout.wordsOnly(1200000), 'مليون ومئتان ألف');
    });

    test('أرقام تحت الألف بترتيب الوحدات قبل العقود', () {
      expect(ArabicNumberSpellout.wordsOnly(21), 'واحد وعشرون');
      expect(ArabicNumberSpellout.wordsOnly(300), 'ثلاثمئة');
      expect(ArabicNumberSpellout.wordsOnly(115), 'مئة وخمسة عشر');
    });

    test('السالبي تُسبق بـ«سالب» (حالات الرصيد المدين)', () {
      final text = ArabicNumberSpellout.spelloutYemeniRial(-40);
      expect(text, startsWith('سالب'));
      expect(text, endsWith('ريال يمني لا غير'));
    });
  });

  group('withFraction', () {
    test('يضيف الهللة عند وجود كسر', () {
      expect(
        ArabicNumberSpellout.withFraction(100.25),
        contains('هللة'),
      );
    });

    test('لا هللة في المبالغ الصحيحة', () {
      expect(
        ArabicNumberSpellout.withFraction(100),
        ArabicNumberSpellout.spelloutYemeniRial(100),
      );
    });
  });
}
