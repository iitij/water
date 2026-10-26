import '../../../../core/error/failures.dart';
import '../../../../core/l10n/app_strings.dart';
import 'tariff_model.dart';

class TariffsPage {
  const TariffsPage({
    required this.tariffs,
    required this.currentPage,
    required this.lastPage,
    required this.total,
    required this.activePrice,
  });

  final List<TariffModel> tariffs;
  final int currentPage;
  final int lastPage;
  final int total;
  final double activePrice;

  factory TariffsPage.fromJson(Object? raw) {
    if (raw is! Map || raw['data'] is! List) {
      throw const ServerFailure(AppStringsDefaults.serverError);
    }
    final page = int.tryParse(raw['current_page']?.toString() ?? '');
    final last = int.tryParse(raw['last_page']?.toString() ?? '');
    final total = int.tryParse(raw['total']?.toString() ?? '');
    final price = double.tryParse(raw['active_price']?.toString() ?? '');
    if (page == null ||
        page < 1 ||
        last == null ||
        last < 1 ||
        total == null ||
        total < 0 ||
        price == null ||
        !price.isFinite ||
        price < 0) {
      throw const ServerFailure(AppStringsDefaults.serverError);
    }
    final rows = raw['data'] as List;
    if (rows.any((row) => row is! Map)) {
      throw const ServerFailure(AppStringsDefaults.serverError);
    }
    return TariffsPage(
      tariffs: rows
          .map(
            (row) =>
                TariffModel.fromJson(Map<String, dynamic>.from(row as Map)),
          )
          .toList(),
      currentPage: page,
      lastPage: last,
      total: total,
      activePrice: price,
    );
  }
}
