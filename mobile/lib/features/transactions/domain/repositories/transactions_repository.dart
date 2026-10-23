import '../entities/ledger_transaction.dart';

abstract class TransactionsRepository {
  Future<List<LedgerTransaction>> getTransactions({
    String? search,
    int? subscriberId,
    String? meterId, // 'general' أو رقم عداد
    String? type, // payment|invoice|adjustment (credit/debit مقبولة)
    String? fromDate,
    String? toDate,
  });
}
