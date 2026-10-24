import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/json_read.dart';
import '../models/ledger_transaction_model.dart';

class TransactionsRemoteDatasource {
  TransactionsRemoteDatasource(this._api);
  final ApiClient _api;

  Future<List<LedgerTransactionModel>> getTransactions(Map<String, dynamic> query) async {
    final data = await _api.get('/transactions', query: query);
    return jMapList(data, const ['transactions']).map(LedgerTransactionModel.fromJson).toList();
  }
}
