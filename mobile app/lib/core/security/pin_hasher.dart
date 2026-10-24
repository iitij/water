import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';

/// تجزئة رقم الحماية (PIN) — نقية وقابلة للاختبار، بلا أي وصول للتخزين.
///
/// لا يُخزَّن الرقم نفسه أبداً: يُخزَّن ملح عشوائي + تجزئة متكرّرة. التكرار
/// المقصود يجعل تخمين 4 أرقام من نسخة مسروقة من التخزين مكلفاً زمنياً.
class PinHasher {
  const PinHasher({this.iterations = defaultIterations});

  static const int defaultIterations = 12000;
  static const int minLength = 4;
  static const int maxLength = 8;

  final int iterations;

  /// رقم صالح: من 4 إلى 8 أرقام فقط (لا حروف ولا مسافات).
  static bool isValidPin(String pin) {
    if (pin.length < minLength || pin.length > maxLength) return false;
    return RegExp(r'^\d+$').hasMatch(pin);
  }

  static String newSalt([Random? random]) {
    final source = random ?? Random.secure();
    final bytes = List<int>.generate(16, (_) => source.nextInt(256));
    return base64UrlEncode(bytes);
  }

  /// تجزئة (salt + pin) بتكرار SHA-256.
  String hash(String pin, String salt) {
    var digest = sha256.convert(utf8.encode('$salt:$pin')).bytes;
    for (var round = 1; round < iterations; round++) {
      digest = sha256.convert(digest).bytes;
    }
    return base64Encode(digest);
  }

  bool verify(String pin, {required String salt, required String expectedHash}) {
    final actual = hash(pin, salt);
    return constantTimeEquals(actual, expectedHash);
  }

  /// مقارنة بزمن ثابت حتى لا يسرّب طول البادئة الصحيحة عبر الزمن.
  static bool constantTimeEquals(String a, String b) {
    if (a.length != b.length) return false;
    var diff = 0;
    for (var i = 0; i < a.length; i++) {
      diff |= a.codeUnitAt(i) ^ b.codeUnitAt(i);
    }
    return diff == 0;
  }
}
