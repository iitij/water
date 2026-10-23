import '../../domain/entities/ledger_transaction.dart';
import '../../domain/repositories/transactions_repository.dart';
import '../datasources/transactions_remote_datasource.dart';

class TransactionsRepositoryImpl implements TransactionsRepository {
  TransactionsRepositoryImpl(this._remote);
  final TransactionsRemoteDatasource _remote;

  @override
  Future<List<LedgerTransaction>> getTransactions({String? search, int? subscriberId, String? meterId, String? type, String? fromDate, String? toDate}) =>
      _remote.getTransactions({
        if (search != null && search.isNotEmpty) 'search': search,
        if (subscriberId != null) 'subscriber_id': subscriberId,
        if (meterId != null) 'meter_id': meterId,
        if (type != null) 'type': type,
        if (fromDate != null) 'from_date': fromDate,
        if (toDate != null) 'to_date': toDate,
      });
}
