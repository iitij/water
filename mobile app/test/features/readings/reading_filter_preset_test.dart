import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:water_project/features/readings/data/reading_filter_preset_store.dart';
import 'package:water_project/features/readings/domain/reading_filter_preset.dart';

/// فلاتر القراءات المحفوظة (S7-06).
///
/// الشاشة تعمل ميدانياً بلا شبكة، فالفلتر يجب أن يُستعاد من الجهاز كما حُفظ
/// تماماً: المربع، ونوع الفوترة، والفترة، ونص البحث.
void main() {
  ReadingFilterPreset preset({
    String id = 'مربع 3 — غير مفوترة',
    String? squareName = 'مربع 3',
    int? squareId = 3,
    String filter = 'notInvoiced',
    DateTime? from,
    DateTime? to,
    String query = '',
  }) =>
      ReadingFilterPreset(
        id: id,
        name: id,
        squareId: squareId,
        squareName: squareName,
        invoiceFilterCode: filter,
        fromDate: from,
        toDate: to,
        query: query,
      );

  group('ReadingFilterPreset', () {
    test('toJson/fromJson يحافظان على كل الحقول', () {
      final original = preset(
        from: DateTime(2026, 10, 1),
        to: DateTime(2026, 10, 24),
        query: 'MTR-12',
      );
      final restored = ReadingFilterPreset.fromJson(original.toJson());

      expect(restored.id, original.id);
      expect(restored.squareId, 3);
      expect(restored.squareName, 'مربع 3');
      expect(restored.invoiceFilterCode, 'notInvoiced');
      expect(restored.fromDate, DateTime(2026, 10, 1));
      expect(restored.query, 'MTR-12');
    });

    test('isEmpty صحيح فقط حين لا يوجد أي وجه', () {
      expect(const ReadingFilterPreset(id: 'x', name: 'x').isEmpty, isTrue);
      expect(preset().isEmpty, isFalse);
      expect(
        const ReadingFilterPreset(id: 'q', name: 'q', query: '   ').isEmpty,
        isTrue,
        reason: 'مسافة بيضاء ليست بحثاً',
      );
    });

    test('facetCount يعدّ المربع والفترة والفوترة والبحث مرةً لكلٍّ', () {
      expect(const ReadingFilterPreset(id: 'x', name: 'x').facetCount, 0);
      expect(preset().facetCount, 2); // مربع + غير مفوترة
      expect(
        preset(from: DateTime(2026, 10, 1), query: 'أحمد').facetCount,
        4,
      );
    });

    test('suggestName يبني اسماً عربياً مفهوماً', () {
      expect(
        ReadingFilterPreset.suggestName(squareName: 'مربع 3', invoiceFilterCode: 'notInvoiced'),
        'مربع 3 • غير مفوترة',
      );
      expect(
        ReadingFilterPreset.suggestName(squareId: 7, invoiceFilterCode: 'all'),
        'مربع #7',
      );
      expect(ReadingFilterPreset.suggestName(), 'فلتر عام');
      expect(
        ReadingFilterPreset.suggestName(
          squareName: 'مربع 1',
          invoiceFilterCode: 'invoiced',
          fromDate: DateTime(2026, 10, 1),
          query: 'MTR',
        ),
        'مربع 1 • مفوترة • بفترة • «MTR»',
      );
    });
  });

  group('InMemoryReadingFilterPresetStore', () {
    test('يحفظ ويقرأ القائمة كما هي', () async {
      final store = InMemoryReadingFilterPresetStore();
      await store.save(<ReadingFilterPreset>[preset(), preset(id: 'آخر')]);
      final loaded = await store.load();
      expect(loaded.map((item) => item.id), <String>['مربع 3 — غير مفوترة', 'آخر']);
    });

    test('قائمة فارغة لا تُفشل القراءة', () async {
      expect(await InMemoryReadingFilterPresetStore().load(), isEmpty);
    });
  });

  group('SharedPreferencesReadingFilterPresetStore', () {
    setUp(() => SharedPreferences.setMockInitialValues(<String, Object>{}));

    test('دورة حفظ/قراءة عبر التخزين الحقيقي', () async {
      final store = SharedPreferencesReadingFilterPresetStore();
      await store.save(<ReadingFilterPreset>[preset()]);
      final loaded = await store.load();
      expect(loaded.single.squareId, 3);
      expect(loaded.single.invoiceFilterCode, 'notInvoiced');
    });

    test('يحدّ العدد ويُبقي الأحدث', () async {
      final store = SharedPreferencesReadingFilterPresetStore(maxPresets: 2);
      await store.save(<ReadingFilterPreset>[
        preset(id: '1'),
        preset(id: '2'),
        preset(id: '3'),
      ]);
      expect((await store.load()).map((item) => item.id), <String>['2', '3']);
    });

    test('نص تالف يُمسح بلا استثناء', () async {
      SharedPreferences.setMockInitialValues(<String, Object>{
        SharedPreferencesReadingFilterPresetStore.storageKey: '{ليس JSON',
      });
      final store = SharedPreferencesReadingFilterPresetStore();
      expect(await store.load(), isEmpty);
      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString(SharedPreferencesReadingFilterPresetStore.storageKey), isNull);
    });
  });
}
