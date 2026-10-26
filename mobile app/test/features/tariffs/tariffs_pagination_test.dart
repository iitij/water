import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/error/failures.dart';
import 'package:water_project/features/tariffs/data/models/tariff_model.dart';
import 'package:water_project/features/tariffs/data/models/tariffs_page.dart';
import 'package:water_project/features/tariffs/domain/repositories/paginated_tariffs_repository.dart';
import 'package:water_project/features/tariffs/domain/repositories/tariffs_repository.dart';
import 'package:water_project/features/tariffs/presentation/cubit/tariffs_cubit.dart';

class _Repository extends Mock implements TariffsRepository, PaginatedTariffsRepository {}

void main() {
  setUpAll(() => registerFallbackValue(TariffsTrashFilter.without));
  test('pages retain server active price even when the active tariff is on another page', () async {
    final repository = _Repository();
    final pages = <int>[];
    when(() => repository.listPage(trash: any(named: 'trash'), search: any(named: 'search'),
      page: any(named: 'page'), pageSize: any(named: 'pageSize'), sortBy: any(named: 'sortBy'),
      sortDirection: any(named: 'sortDirection'))).thenAnswer((call) async {
      final number = call.namedArguments[#page] as int;
      pages.add(number);
      return TariffsPage(tariffs: [TariffModel(id: number, name: 'قديم', unitPrice: 100)],
        currentPage: number, lastPage: 3, total: 21, activePrice: 700);
    });
    final cubit = TariffsCubit(repository: repository);
    addTearDown(cubit.close);
    await cubit.load();
    expect(cubit.state.activePrice, 700);
    expect(cubit.state.totalCount, 21);
    await cubit.goToPage(2);
    expect(cubit.state.tariffs.single.id, 2);
    expect(cubit.state.activePrice, 700);
    await cubit.search('حديث');
    expect(cubit.state.page, 1);
    await cubit.setPresentation(pageSize: 10, sortBy: 'unit_price', sortDirection: 'asc');
    expect(cubit.state.sortBy, 'unit_price');
    expect(cubit.state.pageSize, 10);
    expect(pages, [1, 2, 1, 1]);
    verifyNever(() => repository.activePrice());
  });

  test('missing server active price is an error rather than a derived partial-page price', () {
    expect(() => TariffsPage.fromJson({'data': <Object?>[], 'current_page': 1, 'last_page': 1, 'total': 0}),
      throwsA(isA<ServerFailure>()));
  });
}
