import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_api_client.dart';
import '../../../../core/network/finance_endpoints.dart';
import '../../../../core/utils/json_utils.dart';
import '../models/tariff_model.dart';
import '../models/tariffs_page.dart';

/// مصدر بيانات التعرفة (§2.3-58): القائمة مع فلتر المحذوفات + `active_price`،
/// الإنشاء، التعديل، الحذف الناعم، الاستعادة، والحذف النهائي.
class TariffsRemoteDataSource {
  const TariffsRemoteDataSource(this._client);

  final FinanceApiClient _client;

  Future<TariffsPage> listPage({
    TariffsTrashFilter trash = TariffsTrashFilter.without,
    String? search,
    int page = 1,
    int pageSize = 25,
    String sortBy = 'start_date',
    String sortDirection = 'desc',
    DateTime? addedFrom,
    DateTime? addedTo,
  }) async => TariffsPage.fromJson(
    await _client.getRaw(
      FinanceEndpoints.tariffs,
      query: <String, dynamic>{
        'trashed': trash.wire,
        if (search != null && search.trim().isNotEmpty) 'q': search.trim(),
        'page': page,
        'per_page': pageSize,
        'sort_by': sortBy,
        'sort_direction': sortDirection,
        if (addedFrom != null)
          'added_from': addedFrom.toIso8601String().split('T').first,
        if (addedTo != null)
          'added_to': addedTo.toIso8601String().split('T').first,
      },
    ),
  );

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
        if (search != null && search.trim().isNotEmpty) 'q': search.trim(),
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
    final candidates =
        tariffs
            .where((t) => !t.isTrashed && t.isEffectiveOn(reference))
            .toList()
          ..sort((a, b) => b.id.compareTo(a.id));
    if (candidates.isEmpty) return 600;
    return candidates.first.unitPrice;
  }

  Future<TariffModel> create(Map<String, dynamic> payload) async {
    final response = await _client.post(
      FinanceEndpoints.tariffs,
      body: payload,
    );
    return _parseSingle(response);
  }

  Future<TariffModel> update(int id, Map<String, dynamic> payload) async {
    final response = await _client.put(
      FinanceEndpoints.tariff(id),
      body: payload,
    );
    return _parseSingle(response, fallbackId: id);
  }

  Future<void> softDelete(int id) =>
      _client.delete(FinanceEndpoints.tariff(id));

  Future<void> restore(int id) =>
      _client.post(FinanceEndpoints.tariffRestore(id));

  Future<void> forceDelete(int id) =>
      _client.delete(FinanceEndpoints.tariffForce(id));

  /// استعادة جماعية — تعيد عدد ما استُعيد فعلاً كما يبلّغ الخادم.
  Future<int> bulkRestore(List<int> ids) async {
    final response = await _client.post(
      FinanceEndpoints.tariffsBulkRestore,
      body: <String, dynamic>{'ids': ids},
    );
    return _count(response, 'restored_count');
  }

  /// حذف نهائي جماعي — تعيد عدد ما حُذف فعلاً.
  Future<int> bulkForceDelete(List<int> ids) async {
    final response = await _client.post(
      FinanceEndpoints.tariffsBulkForceDelete,
      body: <String, dynamic>{'ids': ids},
    );
    return _count(response, 'force_deleted_count');
  }

  /// قراءة عدّاد التقرير مع تجاهل أي استجابة غير متوقعة (لا نُفشل العملية
  /// بعد نجاحها على الخادم لمجرد أن العدّاد غاب من الاستجابة).
  int _count(Object? response, String key) {
    final map = JsonUtils.asMap(response);
    final value = map?[key];
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value) ?? 0;
    return 0;
  }

  TariffModel _parseSingle(Object? response, {int? fallbackId}) {
    final map = JsonUtils.asMap(response);
    if (map == null) {
      throw const UnexpectedFailure('استجابة غير صالحة من خادم التعرفة.');
    }
    final data =
        JsonUtils.asMap(map['tariff']) ?? JsonUtils.asMap(map['data']) ?? map;
    if (!data.containsKey('unit_price') && fallbackId != null) {
      return TariffModel(id: fallbackId, name: '', unitPrice: 0);
    }
    return TariffModel.fromJson(data);
  }
}
