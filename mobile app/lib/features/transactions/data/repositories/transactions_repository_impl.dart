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
        // يُرسَل التنسيقان معاً: `from`/`to` للخادم الحالي و`from_date`/`to_date`
        // لتوافق خادم Laravel الموسّع — المفاتيح غير المعروفة تُتجاهل دائماً.
        if (fromDate != null) ...<String, dynamic>{'from': fromDate, 'from_date': fromDate},
        if (toDate != null) ...<String, dynamic>{'to': toDate, 'to_date': toDate},
      });
}
