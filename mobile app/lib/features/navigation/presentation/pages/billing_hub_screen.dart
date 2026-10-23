import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/navigation/module_catalog.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/permissions/permission_service.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_motion.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../../dashboard/domain/repositories/dashboard_repository.dart';
import '../../../dashboard/presentation/cubit/dashboard_cubit.dart';
import '../../../dashboard/presentation/widgets/collection_progress_card.dart';
import '../widgets/module_section.dart';

class BillingHubScreen extends StatelessWidget {
  const BillingHubScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final permissions = GetIt.I<PermissionService>();
    final modules = ModuleCatalog.visible(permissions, group: AppModuleGroup.billing);
    final canSeeStats = permissions.isAdmin ||
        permissions.hasAnyPermission(const <String>[
          'View:StatsOverview',
          'View:MonthlyRevenueChart',
          'View:OverdueInvoicesWidget',
        ]);

    return AppPageBody(
      children: <Widget>[
        AppReveal(
          child: AppHeroPanel(
            gradient: const LinearGradient(
              begin: AlignmentDirectional.topStart,
              end: AlignmentDirectional.bottomEnd,
              colors: <Color>[Color(0xFF062733), Color(0xFF0A4B5A), Color(0xFF0E7C86)],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                const HeroCopy(
                  eyebrow: 'التحصيل والمالية',
                  title: 'كل ريال واضح',
                  subtitle:
                      'حصّل الفواتير، أصدر السندات وأرسل الإشعارات — مع توزيع تلقائي للدفعات وطباعة حرارية فورية.',
                ),
                const Gap(AppSpacing.md),
                const Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: <Widget>[
                    HeroMetaChip(icon: Icons.bolt_rounded, label: 'تحصيل فوري'),
                    HeroMetaChip(icon: Icons.print_rounded, label: 'طباعة سندات'),
                    HeroMetaChip(icon: Icons.whatsapp_rounded, label: 'إشعار واتساب'),
                  ],
                ),
                const Gap(AppSpacing.lg),
                HeroActions(
                  primary: HeroButton(
                    label: 'بدء التحصيل السريع',
                    icon: Icons.point_of_sale_rounded,
                    onPressed: () => context.push(RouteNames.quickCollector),
                  ),
                  secondary: HeroGhostButton(
                    label: 'قائمة الفواتير',
                    icon: Icons.receipt_long_outlined,
                    onPressed: () => context.push(RouteNames.invoices),
                  ),
                ),
              ],
            ),
          ),
        ),
        if (canSeeStats) ...<Widget>[
          const Gap(AppSpacing.lg),
          AppReveal(
            delay: const Duration(milliseconds: 70),
            child: _FinanceSnapshot(onOpenReports: () => context.push(RouteNames.reports)),
          ),
        ],
        const Gap(AppSpacing.md),
        const AppReveal(
          delay: Duration(milliseconds: 100),
          child: SyncSummaryCard(showDescription: false),
        ),
        const Gap(AppSpacing.xl),
        AppReveal(
          delay: const Duration(milliseconds: 130),
          child: ModuleGridSection(
            modules: modules,
            title: 'أدوات التحصيل',
            subtitle: 'من إصدار الفاتورة حتى تسليم السند',
          ),
        ),
        const Gap(AppSpacing.xl),
        AppReveal(
          delay: const Duration(milliseconds: 170),
          child: AppNotice(
            title: 'توزيع ذكي للدفعات',
            message:
                'عند تحصيل أي مبلغ يوزعه النظام تلقائياً على أقدم الفواتير غير المسددة (FIFO) ثم يحدّث الأرصدة والكشوف فوراً.',
            icon: Icons.auto_awesome_rounded,
            color: AppColors.violet,
            action: TextButton(
              onPressed: () => context.push(RouteNames.payments),
              style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(0, 28)),
              child: const Text('مراجعة سندات القبض'),
            ),
          ),
        ),
      ],
    );
  }
}

class _FinanceSnapshot extends StatelessWidget {
  const _FinanceSnapshot({this.onOpenReports});

  final VoidCallback? onOpenReports;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<DashboardCubit>(
      create: (_) => DashboardCubit(GetIt.I<DashboardRepository>())..load(),
      child: BlocBuilder<DashboardCubit, DashboardState>(
        builder: (context, state) {
          final snapshot = state.snapshot;
          if (snapshot == null) {
            if (state.status == DashboardRequestStatus.failure) {
              return const SizedBox.shrink();
            }
            return const AppStatStrip(
              items: <AppStatItem>[
                AppStatItem(
                  label: 'محصّل هذا الشهر',
                  value: '—',
                  icon: Icons.payments_outlined,
                  color: AppColors.success,
                ),
                AppStatItem(
                  label: 'ديون مستحقة',
                  value: '—',
                  icon: Icons.account_balance_wallet_outlined,
                  color: AppColors.danger,
                ),
                AppStatItem(
                  label: 'فواتير متأخرة',
                  value: '—',
                  icon: Icons.schedule_rounded,
                  color: AppColors.warning,
                ),
              ],
            );
          }
          final stats = snapshot.data.stats;
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              AppStatStrip(
                items: <AppStatItem>[
                  AppStatItem(
                    label: 'محصّل هذا الشهر',
                    value: AdminFormat.compactMoney(stats.monthlyCollection),
                    icon: Icons.payments_outlined,
                    color: AppColors.success,
                  ),
                  AppStatItem(
                    label: 'ديون مستحقة',
                    value: AdminFormat.compactMoney(stats.outstandingAmount),
                    icon: Icons.account_balance_wallet_outlined,
                    color: AppColors.danger,
                  ),
                  AppStatItem(
                    label: 'فواتير متأخرة',
                    value: AdminFormat.number(stats.overdueInvoicesCount),
                    icon: Icons.schedule_rounded,
                    color: stats.overdueInvoicesCount > 0 ? AppColors.warning : AppColors.success,
                  ),
                ],
              ),
              const Gap(AppSpacing.md),
              CollectionProgressCard(stats: stats, onOpenReports: onOpenReports),
            ],
          );
        },
      ),
    );
  }
}
