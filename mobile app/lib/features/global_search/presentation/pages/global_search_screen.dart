import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/l10n/global_search_strings.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/list_presentation_controls.dart';
import '../../../../core/ui/record_location_label.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/widgets/empty_state_widget.dart';
import '../../data/models/global_search_models.dart';
import '../cubit/global_search_cubit.dart';

/// بحث واحد في كل موارد النظام (مشتركون، فواتير، عدادات، سندات قبض ومربعات).
///
/// الخادم لا يعيد إلا المجموعات التي يملك المستخدم صلاحية عرضها، لذلك لا تحتاج
/// الشاشة إلى فلترة إضافية. الضغط على نتيجة يفتح شاشة السجل نفسها المستخدمة في
/// التطبيق (المشترك 360، الفاتورة، السند، …) بدل أي شاشة عرض جديدة.
class GlobalSearchScreen extends StatefulWidget {
  const GlobalSearchScreen({super.key});

  @override
  State<GlobalSearchScreen> createState() => _GlobalSearchScreenState();
}

class _GlobalSearchScreenState extends State<GlobalSearchScreen> {
  ListPresentationSettings _view = const ListPresentationSettings();
  @override
  Widget build(BuildContext context) {
    return BlocBuilder<GlobalSearchCubit, GlobalSearchState>(
      builder: (context, state) {
        return SubPageScaffold(
          title: 'البحث الشامل',
          actions: [
            ListPresentationControls(
              settings: _view,
              filterOptions: const ListFilterOptions(
                hasCreatedAt: true,
                search: false,
              ),
              sortOptions: const {
                'id': ListOptionsStrings.id,
                'created_at': ListOptionsStrings.createdAt,
              },
              onChanged: (view) {
                setState(() => _view = view);
                context.read<GlobalSearchCubit>().applyFacets(
                  addedFrom: view.createdFrom,
                  addedTo: view.createdTo,
                  sortBy: view.sortBy,
                  descending: view.descending,
                );
              },
            ),
          ],
          subtitle: GlobalSearchStrings.searchHint,
          child: SafeArea(
            top: false,
            child: Column(
              children: <Widget>[
                AppFilterBar(
                  query: state.query,
                  hint: GlobalSearchStrings.searchHint,
                  onChanged: context.read<GlobalSearchCubit>().search,
                  onClear: state.query.isEmpty
                      ? null
                      : () => context.read<GlobalSearchCubit>().search(''),
                  padding: const EdgeInsetsDirectional.fromSTEB(
                    AppSpacing.md,
                    AppSpacing.sm,
                    AppSpacing.md,
                    AppSpacing.xs,
                  ),
                ),
                if (state.loading) const LinearProgressIndicator(minHeight: 2),
                Expanded(child: _body(context, state)),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _body(BuildContext context, GlobalSearchState state) {
    if (state.error != null) {
      return EmptyStateWidget(
        title: 'تعذّر تنفيذ البحث',
        message: state.error!,
        icon: Icons.cloud_off_rounded,
        action: TextButton.icon(
          onPressed: context.read<GlobalSearchCubit>().retry,
          icon: const Icon(Icons.refresh_rounded),
          label: const Text('إعادة المحاولة'),
        ),
      );
    }

    if (state.needsMoreCharacters) {
      return const EmptyStateWidget(
        title: GlobalSearchStrings.minimumTitle,
        message: GlobalSearchStrings.minimumMessage,
        icon: Icons.keyboard_rounded,
      );
    }

    if (!state.searched) {
      return EmptyStateWidget(
        title: 'ابحث في كل النظام',
        message: GlobalSearchStrings.introduction,
        icon: Icons.manage_search_rounded,
        action: TextButton.icon(
          onPressed: () => context.push('/services'),
          icon: const Icon(Icons.apps_rounded),
          label: const Text('تصفّح كل الخدمات'),
        ),
      );
    }

    if (state.loading && !state.hasResults) {
      return const Center(child: CircularProgressIndicator(strokeWidth: 2.4));
    }

    if (!state.hasResults) {
      return const EmptyStateWidget(
        title: 'لا توجد نتائج',
        message:
            'جرّب اسم المشترك كاملاً، أو رقم الهاتف، أو رقم الفاتورة كما يظهر في السند.',
        icon: Icons.search_off_rounded,
      );
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.md,
        AppSpacing.xs,
        AppSpacing.md,
        AppSpacing.xxl,
      ),
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      children: <Widget>[
        for (final group in state.groups) ...<Widget>[
          _GroupHeader(group: group),
          for (final item in group.items)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: _ResultTile(type: group.type, item: item),
            ),
        ],
      ],
    );
  }
}

class _GroupHeader extends StatelessWidget {
  const _GroupHeader({required this.group});

  final GlobalSearchGroup group;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(
        AppSpacing.xs,
        AppSpacing.md,
        AppSpacing.xs,
        AppSpacing.xs,
      ),
      child: Row(
        children: <Widget>[
          Text(
            group.label,
            style: theme.textTheme.labelLarge?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Text(
            '${group.items.length}',
            style: theme.textTheme.labelSmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}

class _ResultTile extends StatelessWidget {
  const _ResultTile({required this.type, required this.item});

  final String type;
  final GlobalSearchItem item;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final visual = _visualFor(type, scheme);
    final caption = item.caption;

    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(
        AppSpacing.sm,
        10,
        AppSpacing.sm,
        10,
      ),
      onTap: () => _open(context),
      child: Row(
        children: <Widget>[
          AppGlyph(icon: visual.icon, tone: visual.tone, size: 38),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  item.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleSmall,
                ),
                if (type != 'square')
                  RecordLocationLabel(location: item.location),
                if (caption != null) ...<Widget>[
                  const SizedBox(height: 2),
                  Text(
                    caption,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          if (item.amount == null)
            Icon(
              Icons.chevron_left_rounded,
              size: 18,
              color: scheme.onSurfaceVariant.withValues(alpha: .7),
            )
          else
            Text(
              CurrencyFormatter.withLabel(item.amount),
              style: theme.textTheme.labelMedium?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
        ],
      ),
    );
  }

  void _open(BuildContext context) {
    final route = switch (type) {
      'subscriber' => '/subscribers/${item.id}',
      'invoice' => '/invoices/${item.id}',
      'payment' => '/payments/${item.id}/edit',
      'meter' =>
        item.subscriberId != null
            ? '/subscribers/${item.subscriberId}'
            : '/meters',
      'square' => '/squares',
      _ => '/home',
    };
    context.push(route);
  }

  static ({IconData icon, Color tone}) _visualFor(
    String type,
    ColorScheme scheme,
  ) {
    return switch (type) {
      'subscriber' => (icon: Icons.person_rounded, tone: scheme.primary),
      'invoice' => (icon: Icons.receipt_long_rounded, tone: AppColors.warning),
      'meter' => (icon: Icons.water_damage_outlined, tone: AppColors.info),
      'payment' => (icon: Icons.payments_rounded, tone: AppColors.success),
      'square' => (icon: Icons.grid_view_rounded, tone: AppColors.violet),
      _ => (icon: Icons.search_rounded, tone: scheme.primary),
    };
  }
}
