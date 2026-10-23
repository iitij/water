import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_api_client.dart';
import '../../../../core/network/finance_endpoints.dart';
import '../../../../core/utils/json_utils.dart';
import '../models/tariff_model.dart';

/// مصدر بيانات التعرفة (§2.3-58): القائمة مع فلتر المحذوفات + `active_price`،
/// الإنشاء، التعديل، الحذف الناعم، الاستعادة، والحذف النهائي.
class TariffsRemoteDataSource {
  const TariffsRemoteDataSource(this._client);

  final FinanceApiClient _client;

  /// يرجع (القائمة، السعر النشط). `active_price` مفتاح اختياري يضيفه Dev 1
  /// على نفس الاستجابة؛ وإن غاب يُشتق محلياً بمنطق `Tariff::getActivePrice()`.
  Future<({List<TariffModel> tariffs, double? activePrice})> fetch({
    TariffsTrashFilter trash = TariffsTrashFilter.without,
    String? search,
  }) async {
    final response = await _client.getRaw(
      FinanceEndpoints.tariffs,
      query: <String, dynamic>{
        'trashed': trash.wire,
        if (search != null && search.trim().isNotEmpty) 'query': search.trim(),
      },
    );

    final items = JsonUtils.unwrapList(response);
    final tariffs = items.map(TariffModel.fromJson).toList(growable: false);

    double? activePrice;
    if (response is Map) {
      activePrice = JsonUtils.asDoubleOrNull(response['active_price']);
    }
    activePrice ??= deriveActivePrice(tariffs);

    return (tariffs: tariffs, activePrice: activePrice);
  }

  /// إعادة إنتاج `Tariff::getActivePrice()`: نشط + داخل النافذة الزمنية + `orderByDesc('id')`،
  /// ومع غياب أي شريحة يرجع `600` (نفس القيمة الافتراضية في Laravel).
  static double deriveActivePrice(List<TariffModel> tariffs, {DateTime? now}) {
    final reference = now ?? DateTime.now();
    final candidates = tariffs
        .where((t) => !t.isTrashed && t.isEffectiveOn(reference))
        .toList()
      ..sort((a, b) => b.id.compareTo(a.id));
    if (candidates.isEmpty) return 600;
    return candidates.first.unitPrice;
  }

  Future<TariffModel> create(Map<String, dynamic> payload) async {
    final response = await _client.post(FinanceEndpoints.tariffs, body: payload);
    return _parseSingle(response);
  }

  Future<TariffModel> update(int id, Map<String, dynamic> payload) async {
    final response = await _client.put(FinanceEndpoints.tariff(id), body: payload);
    return _parseSingle(response, fallbackId: id);
  }

  Future<void> softDelete(int id) => _client.delete(FinanceEndpoints.tariff(id));

  Future<void> restore(int id) =>
      _client.post(FinanceEndpoints.tariffRestore(id));

  Future<void> forceDelete(int id) =>
      _client.delete(FinanceEndpoints.tariffForce(id));

  TariffModel _parseSingle(Object? response, {int? fallbackId}) {
    final map = JsonUtils.asMap(response);
    if (map == null) {
      throw const UnexpectedFailure('استجابة غير صالحة من خادم التعرفة.');
    }
    final data = JsonUtils.asMap(map['tariff']) ?? JsonUtils.asMap(map['data']) ?? map;
    if (!data.containsKey('unit_price') && fallbackId != null) {
      return TariffModel(id: fallbackId, name: '', unitPrice: 0);
    }
    return TariffModel.fromJson(data);
  }
}
