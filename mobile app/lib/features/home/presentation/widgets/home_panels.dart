part of '../home_screen.dart';

class _SummarySlot extends StatelessWidget {
  const _SummarySlot({
    required this.cubit,
    required this.canViewDashboard,
    required this.onDashboard,
  });

  final DashboardCubit cubit;
  final bool canViewDashboard;
  final VoidCallback onDashboard;

  @override
  Widget build(BuildContext context) =>
      BlocBuilder<DashboardCubit, DashboardState>(
        bloc: cubit,
        builder: (context, state) => HomeProjectSummary(
          snapshot: state.snapshot,
          loading:
              state.status == DashboardRequestStatus.loading ||
              state.isRefreshing,
          failed:
              state.errorMessage != null ||
              state.status == DashboardRequestStatus.failure,
          onRefresh: () => cubit.refresh(),
          onDetails: canViewDashboard ? onDashboard : null,
        ),
      );
}

/// Shows snapshot provenance and amounts from their actual periods.
/// A collection percentage cannot be inferred from monthly and total values.
class HomeProjectSummary extends StatelessWidget {
  const HomeProjectSummary({
    super.key,
    required this.snapshot,
    required this.onRefresh,
    this.loading = false,
    this.failed = false,
    this.onDetails,
  });

  final DashboardSnapshot? snapshot;
  final bool loading;
  final bool failed;
  final VoidCallback onRefresh;
  final VoidCallback? onDetails;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final data = snapshot;
    final stats = data?.data.stats;
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.center,
            spacing: 12,
            children: <Widget>[
              Text(
                OwnerUxStrings.projectSummary,
                style: theme.textTheme.titleLarge,
              ),
              TextButton.icon(
                onPressed: loading ? null : onRefresh,
                icon: const Icon(Icons.refresh_rounded),
                label: const Text(OwnerUxStrings.refresh),
              ),
            ],
          ),
          if (loading) ...<Widget>[
            const LinearProgressIndicator(),
            const SizedBox(height: 8),
            Text(
              OwnerUxStrings.loadingNumbers,
              style: theme.textTheme.bodyMedium,
            ),
          ],
          if (stats != null) ...<Widget>[
            const SizedBox(height: 8),
            AppStatStrip(
              items: <AppStatItem>[
                AppStatItem(
                  label: OwnerUxStrings.outstanding,
                  value:
                      '${CurrencyFormatter.amount(stats.outstandingAmount)} ${OwnerUxStrings.currency}',
                  icon: Icons.account_balance_wallet_outlined,
                  color: AppColors.danger,
                ),
                AppStatItem(
                  label: OwnerUxStrings.monthlyCollection,
                  value:
                      '${CurrencyFormatter.amount(stats.monthlyCollection)} ${OwnerUxStrings.currency}',
                  icon: Icons.payments_outlined,
                  color: AppColors.success,
                ),
              ],
            ),
            const SizedBox(height: 10),
            Text(
              OwnerUxStrings.invoiceCounts(
                stats.unpaidInvoicesCount,
                stats.overdueInvoicesCount,
              ),
              style: theme.textTheme.bodyMedium,
            ),
          ],
          if (data != null) ...<Widget>[
            const SizedBox(height: 8),
            Text(
              OwnerUxStrings.updatedAt(AdminFormat.dateTime(data.updatedAt)),
              style: theme.textTheme.bodySmall,
            ),
            if (data.isFromCache || failed)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  failed
                      ? OwnerUxStrings.refreshFailed
                      : OwnerUxStrings.cachedNumbers,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
              ),
          ] else if (failed) ...<Widget>[
            const SizedBox(height: 8),
            Text(
              OwnerUxStrings.failedNumbers,
              style: theme.textTheme.titleMedium,
            ),
            const SizedBox(height: 6),
            const Text(OwnerUxStrings.failedNumbersHelp),
          ],
          if (onDetails != null) ...<Widget>[
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: onDetails,
              icon: const Icon(Icons.insights_outlined),
              label: const Text(OwnerUxStrings.dashboard),
            ),
          ],
        ],
      ),
    );
  }
}
