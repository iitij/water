/// A strict server statement contract: missing values are never read as zero.
class LedgerStatementModel {
  LedgerStatementModel._(this.opening, this.debit, this.credit, this.closing,
      this.entries, this.hash);

  final int opening;
  final int debit;
  final int credit;
  final int closing;
  final List<LedgerStatementEntry> entries;
  final String hash;

  static int _integer(Object? value) {
    if (value is! int) throw const FormatException('Invalid monetary integer');
    return value;
  }

  factory LedgerStatementModel.fromJson(Object? value, {
    required int subscriberId, required String from, required String to,
  }) {
    if (value is! Map ||
        value['subscriber'] is! Map ||
        (value['subscriber'] as Map)['id'] != subscriberId ||
        value['from_date'] != from || value['to_date'] != to ||
        value['basis'] != 'ledger_posting_date' ||
        value['sign_convention'] != 'positive_credit_negative_debt' ||
        value['entries'] is! List ||
        value['statement_hash'] is! String ||
        !RegExp(r'^[a-f0-9]{64}
      throw const FormatException('Invalid statement identity or basis');
    }
    final opening = _integer(value['opening_balance_minor']);
    final debit = _integer(value['debit_minor']);
    final credit = _integer(value['credit_minor']);
    final closing = _integer(value['closing_balance_minor']);
    final entries = <LedgerStatementEntry>[];
    var running = opening;
    var totalDebit = 0;
    var totalCredit = 0;
    final seen = <int>{};
    for (final row in value['entries'] as List) {
      if (row is! Map || row['posting_date'] is! String || row['type'] is! String) {
        throw const FormatException('Invalid statement entry');
      }
      final id = _integer(row['id']);
      final date = row['posting_date'] as String;
      final parsed = DateTime.tryParse(date);
      if (parsed == null || date.length < 10 ||
          date.substring(0, 10).compareTo(from) < 0 ||
          date.substring(0, 10).compareTo(to) > 0 ||
          !seen.add(id)) {
        throw const FormatException('Invalid entry date or identity');
      }
      final d = _integer(row['debit_minor']);
      final c = _integer(row['credit_minor']);
      final balance = _integer(row['running_balance_minor']);
      if (d < 0 || c < 0 || (d > 0 && c > 0) ||
          _integer(row['amount_minor']) != c - d) {
        throw const FormatException('Invalid entry amounts');
      }
      if (entries.isNotEmpty) {
        final prior = entries.last;
        final order = date.compareTo(prior.date);
        if (order < 0 || (order == 0 && id <= prior.id)) {
          throw const FormatException('Invalid entry ordering');
        }
      }
      running += c - d;
      totalDebit += d;
      totalCredit += c;
      if (running != balance) throw const FormatException('Invalid running balance');
      entries.add(LedgerStatementEntry(id, date,
          row['description']?.toString() ?? row['type'] as String,
          row['meter_code']?.toString(), d, c, balance));
    }
    if (debit != totalDebit || credit != totalCredit || closing != running ||
        _integer(value['entry_count']) != entries.length) {
      throw const FormatException('Statement totals do not reconcile');
    }
    return LedgerStatementModel._(opening, debit, credit, closing,
        List.unmodifiable(entries), value['statement_hash'] as String);
  }
}

class LedgerStatementEntry {
  const LedgerStatementEntry(this.id, this.date, this.description,
      this.meterCode, this.debit, this.credit, this.balance);
  final int id;
  final String date;
  final String description;
  final String? meterCode;
  final int debit;
  final int credit;
  final int balance;
}
).hasMatch(value['statement_hash'] as String)) {
      throw const FormatException('Invalid statement identity or basis');
    }
    final opening = _integer(value['opening_balance_minor']);
    final debit = _integer(value['debit_minor']);
    final credit = _integer(value['credit_minor']);
    final closing = _integer(value['closing_balance_minor']);
    final entries = <LedgerStatementEntry>[];
    var running = opening;
    var totalDebit = 0;
    var totalCredit = 0;
    final seen = <int>{};
    for (final row in value['entries'] as List) {
      if (row is! Map || row['posting_date'] is! String || row['type'] is! String) {
        throw const FormatException('Invalid statement entry');
      }
      final id = _integer(row['id']);
      final date = row['posting_date'] as String;
      final parsed = DateTime.tryParse(date);
      if (parsed == null || date.length < 10 ||
          date.substring(0, 10).compareTo(from) < 0 ||
          date.substring(0, 10).compareTo(to) > 0 ||
          !seen.add(id)) {
        throw const FormatException('Invalid entry date or identity');
      }
      final d = _integer(row['debit_minor']);
      final c = _integer(row['credit_minor']);
      final balance = _integer(row['running_balance_minor']);
      if (d < 0 || c < 0 || (d > 0 && c > 0) ||
          _integer(row['amount_minor']) != c - d) {
        throw const FormatException('Invalid entry amounts');
      }
      if (entries.isNotEmpty) {
        final prior = entries.last;
        final order = date.compareTo(prior.date);
        if (order < 0 || (order == 0 && id <= prior.id)) {
          throw const FormatException('Invalid entry ordering');
        }
      }
      running += c - d;
      totalDebit += d;
      totalCredit += c;
      if (running != balance) throw const FormatException('Invalid running balance');
      entries.add(LedgerStatementEntry(id, date,
          row['description']?.toString() ?? row['type'] as String,
          row['meter_code']?.toString(), d, c, balance));
    }
    if (debit != totalDebit || credit != totalCredit || closing != running ||
        _integer(value['entry_count']) != entries.length) {
      throw const FormatException('Statement totals do not reconcile');
    }
    return LedgerStatementModel._(opening, debit, credit, closing,
        List.unmodifiable(entries));
  }
}

class LedgerStatementEntry {
  const LedgerStatementEntry(this.id, this.date, this.description,
      this.meterCode, this.debit, this.credit, this.balance);
  final int id;
  final String date;
  final String description;
  final String? meterCode;
  final int debit;
  final int credit;
  final int balance;
}
