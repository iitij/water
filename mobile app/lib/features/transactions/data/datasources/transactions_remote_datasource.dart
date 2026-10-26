import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/json_read.dart';
import '../../../../core/network/paginated_collection.dart';
import '../models/ledger_transaction_model.dart';

class TransactionsRemoteDatasource {
  TransactionsRemoteDatasource(this._api);
  final ApiClient _api;

  Future<List<LedgerTransactionModel>> getTransactions(Map<String, dynamic> query) async {
    final data = await collectPaginatedCollection((page) => _api.get('/transactions',
      query: {...query, 'page': page}), collectionKeys: const ['transactions']);
    return jMapList(data, const ['transactions']).map(LedgerTransactionModel.fromJson).toList();
  }
}
