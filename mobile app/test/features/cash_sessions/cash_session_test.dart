import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/cash_sessions/domain/cash_session.dart';

void main() {
  group('CashSession.tryParse', () {
    test('reads the server summary as sent by CashSessionService', () {
      final session = CashSession.tryParse(<String, dynamic>{
        'id': 12,
        'state': 'open',
        'session_date': '2026-10-24',
        'collector': 'أحمد',
        'square': null,
        'opening_amount': '0.00',
        'expected_amount': '1250.00',
        'counted_amount': null,
        'variance_amount': null,
        'receipts': 4,
        'voided_receipts': 1,
        'by_method': <Map<String, dynamic>>[
          <String, dynamic>{'payment_method': 'cash', 'receipts': 3, 'total': '1000.00'},
          <String, dynamic>{'payment_method': 'bank_transfer', 'receipts': 1, 'total': '250.00'},
        ],
        'opened_at': '2026-10-24T06:00:00+00:00',
      });

      expect(session, isNotNull);
      expect(session!.isOpen, isTrue);
      expect(session.expectedAmount, 1250);
      expect(session.receipts, 4);
      expect(session.voidedReceipts, 1);
      expect(session.byMethod.first.arabicLabel, 'نقداً');
      expect(session.byMethod.last.method, 'bank_transfer');
      expect(session.countedAmount, isNull);
    });

    test('flags a shortfall and requires an explanation when there is none', () {
      final session = CashSession.tryParse(<String, dynamic>{
        'id': 13,
        'state': 'closed',
        'session_date': '2026-10-23',
        'opening_amount': '0.00',
        'expected_amount': '800.00',
        'counted_amount': '750.00',
        'variance_amount': '-50.00',
        'receipts': 3,
        'closing_notes': '',
      })!;

      expect(session.isShort, isTrue);
      expect(session.isOver, isFalse);
      expect(session.needsExplanation, isTrue, reason: 'عجز 50 بلا تفسير');
      expect(session.arabicSummary, contains('عجز'));
    });

    test('a tiny difference does not require an explanation', () {
      final session = CashSession.tryParse(<String, dynamic>{
        'id': 14,
        'state': 'closed',
        'session_date': '2026-10-23',
        'opening_amount': '0.00',
        'expected_amount': '100.00',
        'counted_amount': '99.90',
        'variance_amount': '-0.10',
        'receipts': 1,
      })!;

      expect(session.needsExplanation, isFalse);
      expect(session.arabicSummary, contains('عجز 0.10'));
    });

    test('rejects payloads without an id and tolerates unknown states', () {
      expect(CashSession.tryParse(<String, dynamic>{'state': 'open'}), isNull);
      expect(CashSession.tryParse(null), isNull);

      final unknown = CashSession.tryParse(<String, dynamic>{
        'id': 15,
        'state': 'archived',
        'opening_amount': '0',
        'expected_amount': '0',
      })!;
      expect(unknown.state, CashSessionState.closed);
    });
  });
}
