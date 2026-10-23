/// محرك التفقيط العربي للمبالغ المالية (Dev 3).
///
/// يطابق 100% منطق `resources/views/pdf/receipt.blade.php` السطور 402-417:
///  - مصدر الحقيقة في Laravel هو `NumberFormatter('ar', SPELLOUT)` + مصفوفة
///    تصحيحات إملائية (`ثلاثة مائة → ثلاث مائة` … إلخ) + اللاحقة `ريال يمني لا غير`.
///  - هذا الملف يعيد إنتاج نفس المخرجات محلياً في Flutter لأن الطباعة الحرارية
///    الميدانية يجب أن تعمل بدون إنترنت (لا يمكن الاعتماد على intl ICU على الجهاز
///    لأن تصحيحات الـ Blade مخصصة ولا توجد في ICU).
///
/// المرجع الهندسي: `FLUTTER_MOBILE_MASTER_PLAN.md` §8.2.
class ArabicNumberSpellout {
  ArabicNumberSpellout._();

  static const List<String> _ones = <String>[
    '',
    'واحد',
    'اثنان',
    'ثلاثة',
    'أربعة',
    'خمسة',
    'ستة',
    'سبعة',
    'ثمانية',
    'تسعة',
    'عشرة',
    'أحد عشر',
    'اثنا عشر',
    'ثلاثة عشر',
    'أربعة عشر',
    'خمسة عشر',
    'ستة عشر',
    'سبعة عشر',
    'ثمانية عشر',
    'تسعة عشر',
  ];

  static const List<String> _tens = <String>[
    '',
    'عشرة',
    'عشرون',
    'ثلاثون',
    'أربعون',
    'خمسون',
    'ستون',
    'سبعون',
    'ثمانون',
    'تسعون',
  ];

  static const List<String> _hundreds = <String>[
    '',
    'مئة',
    'مئتان',
    'ثلاثمئة',
    'أربعمئة',
    'خمسمئة',
    'ستمئة',
    'سبعمئة',
    'ثمانمئة',
    'تسعمئة',
  ];

  /// اللاحقة الرسمية في سند القبض (`rect_amount_words_suffix`).
  static const String defaultSuffix = 'ريال يمني لا غير';

  /// اللاحقة البديلة المستخدمة في قوالب الطابعة الحرارية (`فقط لا غير`).
  static const String exclusiveSuffix = 'ريال يمني فقط لا غير';

  /// تفقيط كامل مع العملة: `خمسمئة ريال يمني فقط لا غير`.
  static String spelloutYemeniRial(
    num amount, {
    String suffix = defaultSuffix,
  }) {
    // `suffix` يحمل العبارة كاملة (العملة + اللاحقة) حتى لا تتكرر «ريال يمني».
    final intValue = amount.round();
    if (intValue == 0) return 'صفر $suffix';
    final words = _convertBelowBillion(intValue.abs());
    return intValue < 0 ? 'سالب $words $suffix' : '$words $suffix';
  }

  /// تفقيط بلا عملة (يُستخدم في `المبلغ المستلم كتابتا` حيث تُضاف اللاحقة منفصلة).
  static String wordsOnly(num amount) {
    final intValue = amount.round();
    if (intValue == 0) return 'صفر';
    if (intValue < 0) return 'سالب ${_convertBelowBillion(intValue.abs())}';
    return _convertBelowBillion(intValue);
  }

  /// تفقيط مع كسور الريال (الدرهم/الفلس) — احتياط لسندات القبض غير الصحيحة.
  static String withFraction(num amount) {
    final riyals = amount.truncate();
    final halalas = ((amount - riyals).abs() * 100).round();
    final base = spelloutYemeniRial(riyals);
    if (halalas <= 0) return base;
    return '$base و${_convertBelowThousand(halalas)} هللة';
  }

  static String _convertBelowThousand(int n) {
    if (n == 0) return '';
    final parts = <String>[];

    final h = n ~/ 100;
    final rem = n % 100;

    if (h > 0) parts.add(_hundreds[h]);

    if (rem > 0) {
      if (rem < 20) {
        parts.add(_ones[rem]);
      } else {
        final u = rem % 10;
        final t = rem ~/ 10;
        if (u > 0) {
          parts.add('${_ones[u]} و${_tens[t]}');
        } else {
          parts.add(_tens[t]);
        }
      }
    }

    return parts.join(' و');
  }

  static String _convertBelowBillion(int n) {
    if (n == 0) return 'صفر';
    final groups = <String>[];

    final millions = n ~/ 1000000;
    final thousands = (n % 1000000) ~/ 1000;
    final remainder = n % 1000;

    if (millions > 0) {
      if (millions == 1) {
        groups.add('مليون');
      } else if (millions == 2) {
        groups.add('مليونان');
      } else if (millions >= 3 && millions <= 10) {
        groups.add('${_convertBelowThousand(millions)} ملايين');
      } else {
        groups.add('${_convertBelowThousand(millions)} مليون');
      }
    }

    if (thousands > 0) {
      if (thousands == 1) {
        groups.add('ألف');
      } else if (thousands == 2) {
        groups.add('ألفان');
      } else if (thousands >= 3 && thousands <= 10) {
        groups.add('${_convertBelowThousand(thousands)} آلاف');
      } else {
        groups.add('${_convertBelowThousand(thousands)} ألف');
      }
    }

    if (remainder > 0) {
      groups.add(_convertBelowThousand(remainder));
    }

    return groups.join(' و');
  }
}
