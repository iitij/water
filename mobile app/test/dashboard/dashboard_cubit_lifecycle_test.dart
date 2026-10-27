import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/dashboard/domain/entities/dashboard_entities.dart';
import 'package:water_project/features/dashboard/domain/repositories/dashboard_repository.dart';
import 'package:water_project/features/dashboard/presentation/cubit/dashboard_cubit.dart';

class _PendingDashboardRepository implements DashboardRepository {
  final pending = Completer<DashboardSnapshot>();

  @override
  Future<DashboardSnapshot> getDashboard({bool forceRefresh = false}) =>
      pending.future;

  @override
  Future<ReportSnapshot> getReport(ReportFilter filter) =>
      throw UnimplementedError();
}

void main() {
  test('a failed request may finish after the dashboard closes', () async {
    final repository = _PendingDashboardRepository();
    final cubit = DashboardCubit(repository);
    final request = cubit.load();

    await cubit.close();
    repository.pending.completeError(StateError('database unavailable'));

    await request;
    expect(cubit.isClosed, isTrue);
  });

  test('a successful request may finish after the dashboard closes', () async {
    final repository = _PendingDashboardRepository();
    final cubit = DashboardCubit(repository);
    final request = cubit.load();

    await cubit.close();
    repository.pending.complete(
      DashboardSnapshot(
        data: const DashboardData(
          stats: DashboardStats(
            activeSubscribersCount: 0,
            totalSubscribersCount: 0,
            outstandingAmount: 0,
            monthlyCollection: 0,
            overdueInvoicesCount: 0,
            unpaidInvoicesCount: 0,
            readingsThisMonth: 0,
            subscribersSparkline: [],
            collectionSparkline: [],
          ),
          monthlyRevenue: MonthlyRevenue(labels: [], values: []),
          latestInvoices: [],
          overdueInvoices: [],
        ),
        updatedAt: DateTime(2026, 10, 27),
        isFromCache: false,
      ),
    );

    await request;
    expect(cubit.isClosed, isTrue);
  });
}
