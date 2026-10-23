import '../../../../core/network/api_client.dart';
import '../models/ledger_transaction_model.dart';

class TransactionsRemoteDatasource {
  TransactionsRemoteDatasource(this._api);
  final ApiClient _api;

  Future<List<LedgerTransactionModel>> getTransactions(Map<String, dynamic> query) async {
    final data = await _api.get('/transactions', query: query);
    final list = data is Map<String, dynamic> ? (data['data'] as List) : data as List;
    return list.map((e) => LedgerTransactionModel.fromJson(e as Map<String, dynamic>)).toList();
  }
}
