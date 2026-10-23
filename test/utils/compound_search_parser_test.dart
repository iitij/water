import 'package:flutter_test/flutter_test.dart';
import 'package:water_mobile/core/utils/compound_search_parser.dart';

/// اختبارات محلل البحث المركّب — المرجع `InvoiceController::search`
/// (`preg_match('/^(\d+)[\/\-\\\\_](\d+)$/', $query, $matches)`) والخطة §1.3 القاعدة 5
/// و§7.1-1: `4/1` يعني «مربع ٤، مسار ١».
void main() {
  group('FinanceSearchQuery.parse', () {
    test('صيغة (مربع/مسار) بالفاصلة المائلة', () {
      final query = FinanceSearchQuery.parse('4/1');

      expect(query.isCompoundPathSearch, true);
      expect(query.squareId, 4);
      expect(query.pathNumber, 1);
      expect(query.hasSplitParams, true);
    });

    test('تقبل الشرطة والشرطة المائلة المقلوبة والشرطة السفلية', () {
      for (final raw in <String>['4-1', r'4\1', '4_1']) {
        final query = FinanceSearchQuery.parse(raw);
        expect(query.isCompoundPathSearch, true, reason: raw);
        expect(query.squareId, 4, reason: raw);
        expect(query.pathNumber, 1, reason: raw);
      }
    });

    test('الأرقام العربية تُطبَّع قبل التحليل', () {
      final query = FinanceSearchQuery.parse('٤/١');

      expect(query.isCompoundPathSearch, true);
      expect(query.squareId, 4);
      expect(query.pathNumber, 1);
      expect(query.rawQuery, '4/1');
    });

    test('النص العادي ليس بحث مسار', () {
      final query = FinanceSearchQuery.parse('أحمد 4/1');

      expect(query.isCompoundPathSearch, false);
      expect(query.squareId, isNull);
      expect(query.pathNumber, isNull);
      expect(query.rawQuery, 'أحمد 4/1');
    });

    test('رقم واحد فقط لا يُعتبر مساراً', () {
      final query = FinanceSearchQuery.parse('4');
      expect(query.isCompoundPathSearch, false);
      expect(query.hasSplitParams, false);
    });

    test('المسار الصفري مقبول (مربع 4 مسار 0) — يُرسل كما هو للخادم', () {
      final query = FinanceSearchQuery.parse('4/0');
      expect(query.isCompoundPathSearch, true);
      expect(query.pathNumber, 0);
      // `0` قيمة صحيحة وليست «فارغة».
      expect(query.hasSplitParams, true);
    });

    test('الفراغات حول النص تُزال', () {
      final query = FinanceSearchQuery.parse('  4/12  ');
      expect(query.squareId, 4);
      expect(query.pathNumber, 12);
    });

    test('البحث الفارغ', () {
      expect(FinanceSearchQuery.parse('').isEmpty, true);
      expect(FinanceSearchQuery.parse('   ').isEmpty, true);
      expect(FinanceSearchQuery.parse('اسم').isEmpty, false);
    });
  });

  group('بارامترات الطلب', () {
    test('ترسل البارامترات المنفصلة عند دعم الخادم لها', () {
      final map = FinanceSearchQuery.parse('4/1').toQueryMap();

      expect(map['query'], '4/1');
      expect(map['square_id'], 4);
      expect(map['path_number'], 1);
    });

    test('البحث النصي يرسل `q` فقط', () {
      final map = FinanceSearchQuery.parse('أحمد').toQueryMap(key: 'q');

      expect(map['q'], 'أحمد');
      expect(map.containsKey('square_id'), false);
      expect(map.containsKey('path_number'), false);
    });
  });

  group('normalizeDigits', () {
    test('يطبع الأرقام العربية والفارسية', () {
      expect(FinanceSearchQuery.normalizeDigits('١٢٣٤٥٦٧٨٩٠'), '1234567890');
      expect(FinanceSearchQuery.normalizeDigits('۱۲۳'), '123');
      expect(FinanceSearchQuery.normalizeDigits(' خليط ٤5 '), ' خليط 45 ');
    });
  });
}
