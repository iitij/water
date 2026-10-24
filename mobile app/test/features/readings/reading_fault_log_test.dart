import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/readings/data/reading_fault_log.dart';
import 'package:water_project/features/readings/domain/reading_fault.dart';

/// S3-04 — تسجيل «القراءة المتعذرة».
///
/// القاعدة التي نحميها هنا: تعذّر القراءة لا يُحوَّل أبداً إلى قراءة رقمية،
/// ويبقى مرتبطاً بعداد واحد لكل يوم حتى لا يتكرر على المشرف.
void main() {
  ReadingFault fault({
    int meterId = 7,
    ReadingFaultReason reason = ReadingFaultReason.meterBroken,
    String? note,
    DateTime? at,
  }) =>
      ReadingFault(
        meterId: meterId,
        reason: reason,
        recordedAt: at ?? DateTime(2026, 10, 24, 9, 30),
        meterCode: 'MTR-77',
        subscriberName: 'أحمد',
        squareId: 3,
        note: note,
      );

  group('ReadingFaultReason', () {
    test('كل سبب له كود ثابت ونص عربي', () {
      expect(ReadingFaultReason.values.map((r) => r.code).toSet().length,
          ReadingFaultReason.values.length);
      for (final reason in ReadingFaultReason.values) {
        expect(reason.label, isNotEmpty);
      }
    });

    test('استرجاع الكود لا ينشئ قيمة جديدة ولا يفقد «سبب آخر»', () {
      for (final reason in ReadingFaultReason.values) {
        expect(ReadingFaultReasonX.fromCode(reason.code), reason);
      }
      expect(ReadingFaultReasonX.fromCode('غير معروف'), ReadingFaultReason.other);
      expect(ReadingFaultReasonX.fromCode(null), ReadingFaultReason.other);
    });

    test('«سبب آخر» وحده يطلب ملاحظة', () {
      expect(ReadingFaultReason.other.needsNote, isTrue);
      for (final reason in ReadingFaultReason.values) {
        if (reason != ReadingFaultReason.other) {
          expect(reason.needsNote, isFalse);
        }
      }
    });
  });

  group('ReadingFault', () {
    test('toJson/fromJson يحافظان على كل الحقول', () {
      final original = fault(note: 'العداد مقلوب');
      final restored = ReadingFault.fromJson(original.toJson());

      expect(restored.meterId, original.meterId);
      expect(restored.reason, original.reason);
      expect(restored.recordedAt, original.recordedAt);
      expect(restored.meterCode, original.meterCode);
      expect(restored.subscriberName, original.subscriberName);
      expect(restored.squareId, original.squareId);
      expect(restored.note, original.note);
    });

    test('day يجرّد الوقت وsummary يجمع السبب بالملاحظة', () {
      final item = fault(note: 'الزجاج مكسور');
      expect(item.day, DateTime(2026, 10, 24));
      expect(item.summary, contains('العداد معطّل'));
      expect(item.summary, contains('الزجاج مكسور'));
    });

    test('isSameDay يفرّق بين يومين ويجمع نفس اليوم بأوقات مختلفة', () {
      expect(isSameDay(DateTime(2026, 10, 24, 1), DateTime(2026, 10, 24, 23)), isTrue);
      expect(isSameDay(DateTime(2026, 10, 24), DateTime(2026, 10, 25)), isFalse);
    });
  });

  group('InMemoryReadingFaultLog', () {
    test('يسجّل ويقرأ ليوم واحد فقط', () async {
      final log = InMemoryReadingFaultLog();
      await log.record(fault(at: DateTime(2026, 10, 24, 8)));
      await log.record(fault(meterId: 9, at: DateTime(2026, 10, 25, 8)));

      final today = await log.forDay(DateTime(2026, 10, 24));
      expect(today.length, 1);
      expect(today.single.meterId, 7);

      final tomorrow = await log.forDay(DateTime(2026, 10, 25));
      expect(tomorrow.single.meterId, 9);
    });

    test('تعذّر جديد لنفس العداد يستبدل القديم ولا يكرره', () async {
      final log = InMemoryReadingFaultLog();
      await log.record(fault(reason: ReadingFaultReason.meterLocked));
      await log.record(fault(reason: ReadingFaultReason.meterBroken, note: 'مكسور'));

      final all = await log.forDay(DateTime(2026, 10, 24));
      expect(all.length, 1);
      expect(all.single.reason, ReadingFaultReason.meterBroken);
      expect(all.single.note, 'مكسور');
    });

    test('clear يحذف تعذّر عداد واحد ويترك الباقي', () async {
      final log = InMemoryReadingFaultLog();
      await log.record(fault());
      await log.record(fault(meterId: 8));

      await log.clear(meterId: 7, day: DateTime(2026, 10, 24));

      final all = await log.forDay(DateTime(2026, 10, 24));
      expect(all.map((f) => f.meterId), <int>[8]);
    });

    test('البذرة الأولية تُبنى متزامنة وتُقرأ بعدها', () async {
      final log = InMemoryReadingFaultLog(<ReadingFault>[fault(), fault(meterId: 11)]);
      final all = await log.forDay(DateTime(2026, 10, 24));
      expect(all.length, 2);
    });
  });

  group('SharedPreferencesReadingFaultLog', () {
    test('مفتاح اليوم ثابت ومرتّب بلا اعتماد على intl', () {
      expect(
        SharedPreferencesReadingFaultLog.keyFor(DateTime(2026, 1, 5)),
        'reading.faults.2026-01-05',
      );
      expect(
        SharedPreferencesReadingFaultLog.keyFor(DateTime(2026, 12, 31, 23, 59)),
        'reading.faults.2026-12-31',
      );
    });
  });
}
