import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// تشفير قاعدة بيانات الميدان (S5-02).
///
/// التصميم:
/// * مفتاح عشوائي 256-بت يُخزَّن في `flutter_secure_storage` (المخزن الآمن للنظام،
///   لا في `SharedPreferences` النصية).
/// * المفتاح يُمرَّر إلى SQLite عبر `setup:` على اتصال Drift، لا يُكتب في أي ملف.
/// * التفعيل عبر `--dart-define=ENCRYPT_LOCAL_DB=true` حتى تبقى دورات التطوير
///   والاختبارات على SQLite العادي بلا اعتماد على مكتبة SQLCipher.
///
/// تحذير صريح: وحدة العمل هذه لا تُفعّل SQLCipher بنفسها. المطلوب في
/// `pubspec.yaml` استبدال `sqlite3_flutter_libs` بـ`sqlcipher_flutter_libs: ^0.6.8`
/// (SQLCipher 4.9 على Android) — والسبب أن الحزمتين تُدخلان نفس الرموز فيبقى
/// واحدة فقط. لم يُنفَّذ `flutter pub get` ولا بناء جهاز حقيقي بعد، لذا حالة
/// البطاقة تبقى `partial` حتى يُقاس الأثر على جهاز.

/// هل التشفير مطلوب في هذا البناء؟
const bool kEncryptLocalDatabase = bool.fromEnvironment(
  'ENCRYPT_LOCAL_DB',
  defaultValue: false,
);

/// مفتاح قاعدة البيانات.
abstract interface class DatabaseKeyProvider {
  /// يعيد المفتاح، أو `null` إن كان التشفير غير مطلوب/غير ممكن.
  Future<String?> key();
}

/// مزوّد ثابت للاختبارات ولمعاينة سطح المكتب.
class StaticDatabaseKeyProvider implements DatabaseKeyProvider {
  const StaticDatabaseKeyProvider(this._value);

  final String? _value;

  @override
  Future<String?> key() async => _value;
}

/// لا تشفير — يُستخدم في الاختبارات و`flutter run` بدون `--dart-define`.
class NoopDatabaseKeyProvider implements DatabaseKeyProvider {
  const NoopDatabaseKeyProvider();

  @override
  Future<String?> key() async => null;
}

/// مزوّد يعتمد على المخزن الآمن للنظام، ويولّد المفتاح مرة واحدة فقط.
///
/// إن فشل المخزن الآمن (جهاز بلا keychain/keystore) نعيد `null` بدل إسقاط
/// التطبيق: قاعدة غير مشفّرة أفضل من تطبيق لا يفتح. هذا الفشل يُسجّل عبر
/// `onFailure` ليظهر في شاشة التشخيص.
class SecureStorageDatabaseKeyProvider implements DatabaseKeyProvider {
  SecureStorageDatabaseKeyProvider({
    FlutterSecureStorage? storage,
    Random? random,
    this.onFailure,
  })  : _storage = storage ?? const FlutterSecureStorage(),
        _random = random ?? Random.secure();

  static const storageKey = 'water.db.key';

  final FlutterSecureStorage _storage;
  final Random _random;
  final void Function(Object error)? onFailure;

  String? _cached;

  @override
  Future<String?> key() async {
    if (!kEncryptLocalDatabase) return null;
    final cached = _cached;
    if (cached != null) return cached;
    try {
      final existing = await _storage.read(key: storageKey);
      if (existing != null && existing.trim().isNotEmpty) {
        _cached = existing;
        return existing;
      }
      final generated = generateDatabaseKey(_random);
      await _storage.write(key: storageKey, value: generated);
      _cached = generated;
      return generated;
    } catch (error) {
      onFailure?.call(error);
      return null;
    }
  }
}

/// توليد مفتاح 32 بايت (256 بت) بترميز base64 — SQLCipher يقبل النص كما هو.
String generateDatabaseKey(Random random) {
  final bytes = List<int>.generate(32, (_) => random.nextInt(256), growable: false);
  return base64UrlEncode(bytes);
}

/// جملة `PRAGMA key` آمنة.
///
/// سبب هذا التعقيد: SQLCipher يفسّر المفتاح نصاً حرفياً، فأي `'` داخل المفتاح
/// يكسر الجملة. نلفّه بعلامات اقتباس ونضاعف الداخلية. ولا نستعمل `x'..'` لأن
/// المفتاح نصي (base64) لا سداسي.
String pragmaKeyStatement(String key) {
  final escaped = key.replaceAll("'", "''");
  return "PRAGMA key = '$escaped';";
}

/// هل هذا ملف SQLite **غير مشفّر** (يبدأ بسحر SQLite)؟
///
/// نستخدمها لترقية جهاز قديم: الملف موجود من نسخة v1/v2 بلا تشفير، فنفتحه
/// بلا مفتاح ثم `PRAGMA rekey` لتشفيره. بدون هذا الفحص سيُفتح الملف القديم
/// بمفتاح فيفشل بـ`file is not a database` وتضيع بيانات الميدان.
Future<bool> isPlaintextSqliteFile(File file) async {
  if (!await file.exists()) return false;
  final handle = await file.open();
  try {
    final header = await handle.read(16);
    final magic = utf8.encode('SQLite format 3\u0000');
    if (header.length < magic.length) return false;
    for (var i = 0; i < magic.length; i++) {
      if (header[i] != magic[i]) return false;
    }
    return true;
  } finally {
    await handle.close();
  }
}

/// جملة تشفير ملف قائم بلا فقدان بيانات (SQLCipher 4).
String pragmaRekeyStatement(String key) =>
    pragmaKeyStatement(key).replaceFirst('PRAGMA key', 'PRAGMA rekey');
