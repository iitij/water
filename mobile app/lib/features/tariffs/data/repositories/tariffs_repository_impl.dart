import '../../../../core/error/failures.dart';
import '../../domain/repositories/paginated_tariffs_repository.dart';
import '../../domain/repositories/tariffs_repository.dart';
import '../datasources/tariffs_remote_datasource.dart';
import '../models/tariff_model.dart';
import '../models/tariffs_page.dart';

class TariffsRepositoryImpl
    implements TariffsRepository, PaginatedTariffsRepository {
  const TariffsRepositoryImpl(this._remote);

  final TariffsRemoteDataSource _remote;

  @override
  Future<TariffsPage> listPage({
    TariffsTrashFilter trash = TariffsTrashFilter.without,
    String? search,
    int page = 1,
    int pageSize = 25,
    String sortBy = 'start_date',
    String sortDirection = 'desc',
    DateTime? addedFrom,
    DateTime? addedTo,
  }) async {
    final result = await _remote.listPage(
      trash: trash,
      search: search,
      page: page,
      pageSize: pageSize,
      sortBy: sortBy,
      sortDirection: sortDirection,
      addedFrom: addedFrom,
      addedTo: addedTo,
    );
    _cachedActivePrice = result.activePrice;
    return result;
  }

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
    final price = result.activePrice;
    if (price == null) {
      throw const UnexpectedFailure('لا توجد تعرفة حالية معتمدة وغير متعارضة.');
    }
    return price;
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

  @override
  Future<int> bulkRestore(List<int> ids) async {
    if (ids.isEmpty) return 0;
    final count = await _remote.bulkRestore(ids);
    clearCache();
    return count;
  }

  @override
  Future<int> bulkForceDelete(List<int> ids) async {
    if (ids.isEmpty) return 0;
    final count = await _remote.bulkForceDelete(ids);
    clearCache();
    return count;
  }
}
