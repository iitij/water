import '../datasources/tariffs_remote_datasource.dart';
import '../models/tariff_model.dart';
import '../../domain/repositories/tariffs_repository.dart';

class TariffsRepositoryImpl implements TariffsRepository {
  const TariffsRepositoryImpl(this._remote);

  final TariffsRemoteDataSource _remote;

  /// كاش خفيف للسعر النشط كي تُقرأ منه شاشات كثيرة دون ضغط على الخادم.
  static double? _cachedActivePrice;

  static void clearCache() => _cachedActivePrice = null;

  @override
  Future<List<TariffModel>> list({
    TariffsTrashFilter trash = TariffsTrashFilter.without,
    String? search,
  }) async {
    final result = await _remote.fetch(trash: trash, search: search);
    _cachedActivePrice = result.activePrice;
    return result.tariffs;
  }

  @override
  Future<double> activePrice() async {
    final result = await _remote.fetch();
    _cachedActivePrice = result.activePrice;
    return result.activePrice ?? 600;
  }

  double? get cachedActivePrice => _cachedActivePrice;

  @override
  Future<TariffModel> create(TariffModel tariff) async {
    final created = await _remote.create(tariff.toPayload());
    clearCache();
    return created;
  }

  @override
  Future<TariffModel> update(TariffModel tariff) async {
    final updated = await _remote.update(tariff.id, tariff.toPayload());
    clearCache();
    return updated;
  }

  @override
  Future<void> softDelete(int id) async {
    await _remote.softDelete(id);
    clearCache();
  }

  @override
  Future<void> restore(int id) async {
    await _remote.restore(id);
    clearCache();
  }

  @override
  Future<void> forceDelete(int id) async {
    await _remote.forceDelete(id);
    clearCache();
  }
}
