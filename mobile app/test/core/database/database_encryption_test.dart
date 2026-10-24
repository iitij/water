import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/database/database_encryption.dart';

/// S5-02 — طبقة مفتاح التشفير.
///
/// نختبر ما يمكن اختباره بلا مكتبة SQLCipher أصلية على مضيف الاختبار:
/// توليد المفتاح، صياغة `PRAGMA key` بأمان، وكشف الملف القديم غير المشفّر
/// (وهو الشرط الذي يمنع فقدان بيانات الميدان عند أول تشغيل مشفّر).
void main() {
  group('generateDatabaseKey', () {
    test('يعيد 32 بايت بترميز base64Url بصيغة ثابتة', () {
      final key = generateDatabaseKey(Random(7));
      final bytes = base64UrlDecode(key);
      expect(bytes.length, 32);
      expect(key, isNot(contains("'")));
    });

    test('مفاتيح مختلفة لنفس البذرة المطلوبة ومختلفة عبر البذور', () {
      expect(generateDatabaseKey(Random(1)), generateDatabaseKey(Random(1)));
      expect(generateDatabaseKey(Random(1)), isNot(generateDatabaseKey(Random(2))));
    });
  });

  group('pragmaKeyStatement', () {
    test('يلف المفتاح بعلامة اقتباس واحدة', () {
      expect(pragmaKeyStatement('abc'), "PRAGMA key = 'abc';");
    });

    test('يضاعف علامة الاقتباس المفردة بدل كسر الجملة', () {
      expect(pragmaKeyStatement("a'b"), "PRAGMA key = 'a''b';");
    });

    test('rekey يستخدم نفس الصياغة الآمنة', () {
      expect(pragmaRekeyStatement("a'b"), "PRAGMA rekey = 'a''b';");
    });
  });

  group('isPlaintextSqliteFile', () {
    late Directory dir;

    setUp(() async {
      dir = await Directory.systemTemp.createTemp('water_db_test_');
    });

    tearDown(() async {
      if (await dir.exists()) await dir.delete(recursive: true);
    });

    test('ملف غير موجود ليس ملفاً قديماً', () async {
      expect(await isPlaintextSqliteFile(File('${dir.path}/missing.sqlite')), isFalse);
    });

    test('ملف فارغ لا يُعدّ SQLite عادياً', () async {
      final file = File('${dir.path}/empty.sqlite');
      await file.writeAsBytes(const <int>[]);
      expect(await isPlaintextSqliteFile(file), isFalse);
    });

    test('ملف بسحر SQLite يُكتشف كغير مشفّر', () async {
      final file = File('${dir.path}/plain.sqlite');
      await file.writeAsBytes(<int>[
        ...'SQLite format 3'.codeUnits,
        0,
        ...List<int>.filled(32, 0),
      ]);
      expect(await isPlaintextSqliteFile(file), isTrue);
    });

    test('ملف بترويسة عشوائية (مشفّر) لا يُكتشف', () async {
      final file = File('${dir.path}/enc.sqlite');
      await file.writeAsBytes(List<int>.generate(48, (i) => 255 - i));
      expect(await isPlaintextSqliteFile(file), isFalse);
    });
  });

  group('مزوّدات المفتاح', () {
    test('Noop يعيد null دائماً — أي «بلا تشفير»', () async {
      expect(await const NoopDatabaseKeyProvider().key(), isNull);
    });

    test('Static يعيد القيمة المعطاة', () async {
      const withKey = StaticDatabaseKeyProvider('k');
      const withoutKey = StaticDatabaseKeyProvider(null);
      expect(await withKey.key(), 'k');
      expect(await withoutKey.key(), isNull);
    });

    test('التشفير غير مفعّل افتراضياً حتى لا يتغيّر الاختبار/التطوير', () {
      expect(kEncryptLocalDatabase, isFalse);
    });
  });
}
