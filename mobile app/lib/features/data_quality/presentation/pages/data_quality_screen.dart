import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/l10n/app_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../admin_shared/presentation/widgets/admin_permission_guard.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../data/data_quality_remote_data_source.dart';
import '../../domain/data_quality_issue.dart';
import '../cubit/data_quality_cubit.dart';

/// شاشة «جودة البيانات» (S7-10).
///
/// ليست تقريراً للقراءة بل قائمة مهام: كل سطر مشكلة قابلة للإصلاح، ومعه أمثلة
/// تنقل المشرف إلى الشاشة التي تُصلحها. التقرير لا يُخزّن محلياً — الحقيقة
/// على الخادم، ونسخة قديمة تُضلّل.
class DataQualityScreen extends StatelessWidget {
  const DataQualityScreen({
    required this.remote,
    this.can,
    super.key,
  });

  final DataQualityRemoteDataSource remote;
  final AdminPermissionCheck? can;

  @override
  Widget build(BuildContext context) {
    if (can != null && !can!(AdminPermissions.viewUsers)) {
      return const Scaffold(
        body: AdminErrorView(message: 'تقرير جودة البيانات يحتاج صلاحية ViewAny:User.'),
      );
    }
    return BlocProvider(
      create: (_) => DataQualityCubit(remote)..load(),
      child: const _DataQualityView(),
    );
  }
}

class _DataQualityView extends StatelessWidget {
  const _DataQualityView();

  @override
  Widget build(BuildContext context) {
    return SubPageScaffold(
      title: AppStrings.of(context).dataQualityTitle,
      subtitle: 'قائمة مهام: كل مشكلة تنقلك إلى مكان الإصلاح',
      actions: <Widget>[
        BlocBuilder<DataQualityCubit, DataQualityState>(
          builder: (context, state) => PopupMenuButton<int>(
            tooltip: 'عتبة القراءة القديمة',
            icon: const Icon(Icons.filter_alt_outlined, size: 20),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
            onSelected: (days) => context.read<DataQualityCubit>().load(staleDays: days),
            itemBuilder: (_) => <PopupMenuEntry<int>>[
              for (final days in <int>[30, 45, 60, 90])
                PopupMenuItem<int>(
                  value: days,
                  child: Text('قراءة قديمة: $days يوماً${state.staleDays == days ? ' ✓' : ''}'),
                ),
            ],
          ),
        ),
      ],
      child: BlocBuilder<DataQualityCubit, DataQualityState>(
        builder: (context, state) {
          if (state.status == DataQualityStatus.loading && state.report == null) {
            return const AppLoader();
          }
          if (state.status == DataQualityStatus.failure && state.report == null) {
            return AdminErrorView(
              message: state.errorMessage ?? 'تعذّر تحميل التقرير.',
              onRetry: () => context.read<DataQualityCubit>().load(),
            );
          }
          return ListView(
            padding: const EdgeInsets.all(AppSpacing.md),
            children: <Widget>[
              _SummaryCard(state: state),
              const SizedBox(height: AppSpacing.md),
              if (state.isClean)
                const AppCard(
                  child: Row(
                    children: <Widget>[
                      Icon(Icons.verified_rounded, color: AppColors.success),
                      SizedBox(width: AppSpacing.sm),
                      Expanded(child: Text('لا مشكلات في البيانات ضمن هذه العتبة.')),
                    ],
                  ),
                )
              else
                for (final issue in state.problems)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: _IssueCard(issue: issue),
                  ),
              const SizedBox(height: AppSpacing.md),
              const SectionHeading(
                title: 'المعلومات التشخيصية',
              ),
              const SizedBox(height: AppSpacing.sm),
              for (final issue in state.report!.issues.where((issue) => issue.isInfo))
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                  child: AppCard(
                    child: Row(
                      children: <Widget>[
                        const Icon(Icons.info_outline_rounded, size: 18),
                        const SizedBox(width: AppSpacing.sm),
                        Expanded(child: Text('${issue.title} — ${issue.detail}')),
                      ],
                    ),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.state});

  final DataQualityState state;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final clean = state.isClean;
    return AppCard(
      color: clean ? scheme.surfaceContainerHighest : scheme.errorContainer.withValues(alpha: .25),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Icon(
                clean ? Icons.check_circle_rounded : Icons.rule_rounded,
                color: clean ? AppColors.success : scheme.error,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Text(
                  clean
                      ? 'البيانات سليمة'
                      : '${state.totalProblems} سجلاً يحتاج إصلاحاً في ${state.problems.length} فئة',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(
            'عتبة القراءة القديمة: ${state.staleDays} يوماً. التقرير يُحسب لحظياً من الخادم.',
            style: Theme.of(context)
                .textTheme
                .bodySmall
                ?.copyWith(color: scheme.onSurfaceVariant),
          ),
        ],
      ),
    );
  }
}

class _IssueCard extends StatelessWidget {
  const _IssueCard({required this.issue});

  final DataQualityIssue issue;

  Color _severityColor(BuildContext context) => switch (issue.severity) {
        'high' => Theme.of(context).colorScheme.error,
        'medium' => AppColors.warning,
        'low' => AppColors.info,
        _ => Theme.of(context).colorScheme.outline,
      };

  String? _routeOf(String? route) => switch (route) {
        'subscribers' => RouteNames.subscribers,
        'meters' => RouteNames.meters,
        'readings' => RouteNames.readings,
        _ => null,
      };

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              AppPill(label: issue.severityLabel, dense: true, color: _severityColor(context)),
              const SizedBox(width: AppSpacing.xs),
              Expanded(
                child: Text(issue.title, style: Theme.of(context).textTheme.titleSmall),
              ),
              Text(
                '${issue.count}',
                style: Theme.of(context)
                    .textTheme
                    .titleMedium
                    ?.copyWith(color: _severityColor(context)),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(
            issue.detail,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
          ),
          if (issue.samples.isNotEmpty) ...<Widget>[
            const SizedBox(height: AppSpacing.sm),
            Wrap(
              spacing: AppSpacing.xs,
              runSpacing: AppSpacing.xs,
              children: <Widget>[
                for (final sample in issue.samples)
                  AppPill(
                    label: sample.meta == null ? sample.label : '${sample.label} • ${sample.meta}',
                    icon: Icons.open_in_new_rounded,
                    onTap: () {
                      final route = _routeOf(sample.route);
                      if (route != null) context.push(route);
                    },
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
