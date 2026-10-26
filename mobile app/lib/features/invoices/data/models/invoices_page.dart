import '../../../../core/error/failures.dart';
import '../../../../core/l10n/app_strings.dart';
import 'invoice_model.dart';

class InvoicesPage {
  const InvoicesPage({
    required this.invoices,
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });

  final List<InvoiceModel> invoices;
  final int currentPage;
  final int lastPage;
  final int total;

  factory InvoicesPage.fromJson(Object? raw) {
    if (raw is! Map || raw['data'] is! List) {
      throw const ServerFailure(AppStringsDefaults.serverError);
    }
    final page = int.tryParse(raw['current_page']?.toString() ?? '');
    final last = int.tryParse(raw['last_page']?.toString() ?? '');
    final total = int.tryParse(raw['total']?.toString() ?? '');
    if (page == null ||
        page < 1 ||
        last == null ||
        last < 1 ||
        total == null ||
        total < 0) {
      throw const ServerFailure(AppStringsDefaults.serverError);
    }
    final rows = raw['data'] as List;
    if (rows.any((row) => row is! Map)) {
      throw const ServerFailure(AppStringsDefaults.serverError);
    }
    return InvoicesPage(
      invoices: rows
          .map(
            (row) =>
                InvoiceModel.fromJson(Map<String, dynamic>.from(row as Map)),
          )
          .toList(),
      currentPage: page,
      lastPage: last,
      total: total,
    );
  }
}
