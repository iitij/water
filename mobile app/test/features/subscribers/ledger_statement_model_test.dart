import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/subscribers/data/models/ledger_statement_model.dart';

Map<String, dynamic> fixture() => {
  'subscriber': {'id': 7},
  'from_date': '2026-03-01', 'to_date': '2026-03-31',
  'basis': 'ledger_posting_date',
  'statement_hash': List.filled(64, 'a').join(),
  'sign_convention': 'positive_credit_negative_debt',
  'opening_balance_minor': -10025, 'debit_minor': 0, 'credit_minor': 5075,
  'closing_balance_minor': -4950, 'entry_count': 1,
  'entries': [
    {'id': 10, 'posting_date': '2026-03-02 00:00:00', 'type': 'payment',
     'amount_minor': 5075, 'debit_minor': 0, 'credit_minor': 5075,
     'running_balance_minor': -4950}
  ],
};

LedgerStatementModel parse(Map<String, dynamic> data) =>
    LedgerStatementModel.fromJson(data, subscriberId: 7,
        from: '2026-03-01', to: '2026-03-31');

void main() {
  test('uses exact server minor units including opening balance', () {
    final statement = parse(fixture());
    expect(statement.opening, -10025);
    expect(statement.closing, -4950);
    expect(statement.entries.single.balance, -4950);
  });
  test('rejects missing or mismatched totals and wrong account', () {
    for (final data in [
      fixture()..remove('opening_balance_minor'),
      fixture()..['closing_balance_minor'] = 0,
      fixture()..['subscriber'] = {'id': 8},
      fixture()..['basis'] = 'payment_date',
      fixture()..remove('statement_hash'),
      fixture()..['statement_hash'] = 'invalid',
    ]) {
      expect(() => parse(data), throwsFormatException);
    }
  });
  test('rejects corrupted running balance', () {
    final data = fixture();
    (data['entries'] as List).first['running_balance_minor'] = 0;
    expect(() => parse(data), throwsFormatException);
  });
}
