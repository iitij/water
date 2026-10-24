import 'dart:math';

import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/security/pin_hasher.dart';

/// تجزئة رقم الحماية (S5-01): لا يُخزَّن الرقم، والملح يغيّر الناتج.
void main() {
  // تكرارات صغيرة حتى تبقى الاختبارات سريعة؛ الإنتاج يستخدم 12000.
  const hasher = PinHasher(iterations: 3);

  group('PinHasher', () {
    test('الرقم نفسه مع الملح نفسه يعطي التجزئة نفسها', () {
      final a = hasher.hash('1234', 'salt-a');
      final b = hasher.hash('1234', 'salt-a');
      expect(a, b);
    });

    test('الملح المختلف يعطي تجزئة مختلفة', () {
      expect(hasher.hash('1234', 'salt-a'), isNot(hasher.hash('1234', 'salt-b')));
    });

    test('الرقم المختلف يعطي تجزئة مختلفة', () {
      expect(hasher.hash('1234', 'salt'), isNot(hasher.hash('1235', 'salt')));
    });

    test('verify يقبل الصحيح ويرفض الخطأ', () {
      final salt = PinHasher.newSalt(Random(7));
      final hash = hasher.hash('4321', salt);

      expect(hasher.verify('4321', salt: salt, expectedHash: hash), isTrue);
      expect(hasher.verify('4322', salt: salt, expectedHash: hash), isFalse);
      expect(hasher.verify('4321', salt: 'another', expectedHash: hash), isFalse);
    });

    test('الملح عشوائي وطوله ثابت', () {
      final salt = PinHasher.newSalt(Random(1));
      expect(salt.length, greaterThanOrEqualTo(20));
      expect(PinHasher.newSalt(Random(2)), isNot(salt));
    });

    test('الأرقام الصالحة من 4 إلى 8 أرقام فقط', () {
      expect(PinHasher.isValidPin('1234'), isTrue);
      expect(PinHasher.isValidPin('12345678'), isTrue);
      expect(PinHasher.isValidPin('123'), isFalse);
      expect(PinHasher.isValidPin('123456789'), isFalse);
      expect(PinHasher.isValidPin('12a4'), isFalse);
      expect(PinHasher.isValidPin(''), isFalse);
    });

    test('المقارنة الثابتة الزمن تتجاهل القيم ذات الأطوال المختلفة', () {
      expect(PinHasher.constantTimeEquals('abcdef', 'abcdef'), isTrue);
      expect(PinHasher.constantTimeEquals('abcdef', 'abcdeg'), isFalse);
      expect(PinHasher.constantTimeEquals('abc', 'abcd'), isFalse);
    });
  });
}
