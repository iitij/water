import '../../data/models/tariff_model.dart';
import '../../data/models/tariffs_page.dart';

abstract interface class PaginatedTariffsRepository {
  Future<TariffsPage> listPage({
    TariffsTrashFilter trash = TariffsTrashFilter.without,
    String? search,
    int page = 1,
    int pageSize = 25,
    String sortBy = 'start_date',
    String sortDirection = 'desc',
  });
}
