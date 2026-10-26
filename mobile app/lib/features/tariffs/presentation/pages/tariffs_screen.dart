import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/permissions/finance_permissions.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/list_presentation_controls.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../data/models/tariff_model.dart';
import '../../domain/repositories/tariffs_repository.dart';
import '../cubit/tariffs_cubit.dart';
import '../sheets/tariff_form_sheet.dart';

/// `SCR-21` — إدارة التسعيرات، مطابقةً لـ `TariffResource` في Filament:
/// قائمة + سعر نشط في الترويسة + `TrashedFilter` (الحية/الكل/المحذوفة) +
/// استعادة/حذف نهائي، مع نموذج الإنشاء والتعديل في درج سفلي.
class TariffsScreen extends StatelessWidget {
  const TariffsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<TariffsCubit>(
      create: (_) => TariffsCubit(repository: sl<TariffsRepository>())..load(),
      child: const _TariffsBody(),
    );
  }
}

class _TariffsBody extends StatefulWidget {
  const _TariffsBody();

  @override
  State<_TariffsBody> createState() => _TariffsBodyState();
}

class _TariffsBodyState extends State<_TariffsBody> {
  final TextEditingController _search = TextEditingController();
  ListPresentationSettings _presentation = const ListPresentationSettings(
    sortBy: 'start_date',
  );

  void _applyPresentation(ListPresentationSettings value) {
    final previous = _presentation;
    setState(() => _presentation = value);
    if (previous.sortBy != value.sortBy ||
        previous.descending != value.descending ||
        previous.pageSize != value.pageSize) {
      context.read<TariffsCubit>().setPresentation(
        pageSize: value.pageSize,
        sortBy: value.sortBy,
        sortDirection: value.descending ? 'desc' : 'asc',
      );
    }
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<TariffsCubit>();

    return Scaffold(
      appBar: AppBar(
        title: const Text('التسعيرات'),
        actions: <Widget>[
          BlocBuilder<TariffsCubit, TariffsState>(
            builder: (context, state) => IgnorePointer(
              ignoring: state.isLoading,
              child: ListPresentationControls(
                settings: _presentation,
                sortOptions: const {
                  'start_date': ListOptionsStrings.startDate,
                  'end_date': ListOptionsStrings.endDate,
                  'name': ListOptionsStrings.name,
                  'unit_price': ListOptionsStrings.unitPrice,
                  'id': ListOptionsStrings.id,
                },
                fields: const {'period': ListOptionsStrings.period},
                onChanged: _applyPresentation,
              ),
            ),
          ),
          BlocBuilder<TariffsCubit, TariffsState>(
            builder: (context, state) =>
                state.showsTrashed && state.visible.isNotEmpty
                ? IconButton(
                    tooltip: state.selectionMode
                        ? 'إنهاء التحديد'
                        : 'تحديد متعدد',
                    onPressed: () => cubit.toggleSelectionMode(),
                    icon: Icon(
                      state.selectionMode
                          ? Icons.close_rounded
                          : Icons.checklist_rtl_rounded,
                    ),
                  )
                : const SizedBox.shrink(),
          ),
          IconButton(
            tooltip: 'إعادة تحميل',
            onPressed: () => cubit.load(refresh: true),
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      floatingActionButton: context.canManageTariffs
          ? FloatingActionButton.extended(
              onPressed: () async {
                await TariffFormSheet.show(context);
              },
              icon: const Icon(Icons.add),
              label: const Text('تسعيرة جديدة'),
            )
          : null,
      body: SafeArea(
        child: BlocBuilder<TariffsCubit, TariffsState>(
          builder: (context, state) {
            final tariffs = state.visible;

            return Column(
              children: <Widget>[
                const SizedBox(height: 10),
                AppFilterBar(
                  controller: _search,
                  query: state.search,
                  hint: 'اسم التسعيرة',
                  onChanged: cubit.search,
                ),
                const SizedBox(height: 8),
                AppFilterChips(
                  children: <Widget>[
                    for (final filter in TariffsTrashFilter.values)
                      AppFilterChip(
                        label: filter.label,
                        selected: state.trashFilter == filter,
                        onTap: () => cubit.setTrashFilter(filter),
                      ),
                  ],
                ),
                _ActivePriceHeader(state: state),
                if (state.selectionMode)
                  _BulkBar(
                    state: state,
                    onSelectAll: cubit.selectAllVisible,
                    onClear: cubit.clearSelection,
                    onRestore: () => _bulkRestore(cubit),
                    onForceDelete: () => _bulkForceDelete(cubit),
                  ),
                Expanded(child: _buildList(context, cubit, state, tariffs)),
                SafeArea(
                  top: false,
                  child: Wrap(
                    spacing: 8,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(
                        '${ListOptionsStrings.page} ${state.page}${state.lastPage == null ? '' : ' / ${state.lastPage}'}',
                      ),
                      if (state.totalCount != null)
                        Text(
                          '${ListOptionsStrings.allRecords}: ${state.totalCount}',
                        ),
                      TextButton(
                        onPressed: !state.isLoading && state.hasPreviousPage
                            ? () => cubit.goToPage(state.page - 1)
                            : null,
                        child: const Text(ListOptionsStrings.previous),
                      ),
                      TextButton(
                        onPressed: !state.isLoading && state.hasNextPage
                            ? () => cubit.goToPage(state.page + 1)
                            : null,
                        child: const Text(ListOptionsStrings.next),
                      ),
                    ],
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _buildList(
    BuildContext context,
    TariffsCubit cubit,
    TariffsState state,
    List<TariffModel> tariffs,
  ) {
    if (state.isLoading && tariffs.isEmpty) {
      return const SkeletonList(count: 5, lines: 1);
    }

    if (state.status == TariffsLoadStatus.failure && tariffs.isEmpty) {
      return ErrorStateWidget(
        message: state.errorMessage ?? 'تعذّر تحميل التسعيرات.',
        onRetry: () => cubit.load(refresh: true),
      );
    }

    if (tariffs.isEmpty) {
      return ListView(
        children: <Widget>[
          const SizedBox(height: 60),
          EmptyStateWidget(
            title: state.search.isEmpty ? 'لا توجد تسعيرات' : 'لا نتائج للبحث',
            message: state.showsTrashed
                ? 'لا تسعيرات محذوفة.'
                : 'أضف تسعيرة جديدة لتبدأ الفوترة التلقائية.',
            icon: Icons.sell_outlined,
            compact: true,
          ),
        ],
      );
    }

    return RefreshIndicator(
      onRefresh: () => cubit.load(refresh: true),
      child: ListView.builder(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 88),
        itemCount: tariffs.length,
        itemBuilder: (context, index) {
          final tariff = tariffs[index];
          return TariffCard(
            tariff: tariff,
            dense: _presentation.compact,
            showPeriod: _presentation.isVisible('period'),
            isActive: state.activeTariff?.id == tariff.id,
            busy: state.busyId == tariff.id,
            selected: state.isSelected(tariff.id),
            onSelectToggle: state.selectionMode
                ? () => cubit.toggleSelected(tariff.id)
                : null,
            onTap: () => TariffFormSheet.show(context, tariff: tariff),
            onSoftDelete: !tariff.isTrashed && context.canManageTariffs
                ? () => _softDelete(cubit, tariff)
                : null,
            onRestore: tariff.isTrashed ? () => _restore(cubit, tariff) : null,
            onForceDelete: tariff.isTrashed
                ? () => _forceDelete(cubit, tariff)
                : null,
          );
        },
      ),
    );
  }

  Future<void> _softDelete(TariffsCubit cubit, TariffModel tariff) async {
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'حذف التسعيرة (سلة المحذوفات)',
      message:
          'ستُنقل التسعيرة إلى سلة المحذوفات ويمكن استعادتها لاحقاً. الفواتير السابقة لن تتأثر.',
      details: <String>[
        tariff.name,
        CurrencyFormatter.withLabel(tariff.unitPrice),
      ],
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (!confirmed || !mounted) return;

    final ok = await cubit.softDelete(tariff.id);
    if (!mounted) return;
    if (ok) {
      AppFeedback.success(
        context,
        'تم نقل «${tariff.name}» إلى سلة المحذوفات.',
      );
    } else {
      AppFeedback.error(
        context,
        cubit.state.errorMessage ?? 'تعذّر حذف التسعيرة.',
      );
    }
  }

  Future<void> _restore(TariffsCubit cubit, TariffModel tariff) async {
    final ok = await cubit.restore(tariff.id);
    if (!mounted) return;
    if (ok) {
      AppFeedback.success(context, 'تمت استعادة «${tariff.name}».');
    } else {
      AppFeedback.error(
        context,
        cubit.state.errorMessage ?? 'تعذّرت الاستعادة.',
      );
    }
  }

  Future<void> _bulkRestore(TariffsCubit cubit) async {
    final count = cubit.state.selectedIds.length;
    if (count == 0) return;
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'استعادة $count تسعيرة',
      message: 'ستعود التسعيرات المحدّدة إلى القائمة النشطة فوراً.',
      confirmLabel: 'استعادة',
    );
    if (!confirmed || !mounted) return;

    final restored = await cubit.bulkRestore();
    if (!mounted) return;
    if (restored > 0) {
      AppFeedback.success(context, 'تمت استعادة $restored تسعيرة.');
    } else {
      AppFeedback.error(
        context,
        cubit.state.errorMessage ?? 'تعذّرت الاستعادة الجماعية.',
      );
    }
  }

  Future<void> _bulkForceDelete(TariffsCubit cubit) async {
    final count = cubit.state.selectedIds.length;
    if (count == 0) return;
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'حذف نهائي لـ$count تسعيرة',
      message: 'لا يمكن التراجع عن الحذف النهائي. الفواتير السابقة لا تتأثر.',
      confirmLabel: 'حذف نهائي',
      destructive: true,
    );
    if (!confirmed || !mounted) return;

    final deleted = await cubit.bulkForceDelete();
    if (!mounted) return;
    if (deleted > 0) {
      AppFeedback.success(context, 'تم حذف $deleted تسعيرة نهائياً.');
    } else {
      AppFeedback.error(
        context,
        cubit.state.errorMessage ?? 'تعذّر الحذف النهائي الجماعي.',
      );
    }
  }

  Future<void> _forceDelete(TariffsCubit cubit, TariffModel tariff) async {
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'حذف نهائي للتسعيرة',
      message: 'لا يمكن التراجع عن الحذف النهائي. الفواتير السابقة لا تتأثر.',
      details: <String>[
        tariff.name,
        CurrencyFormatter.withLabel(tariff.unitPrice),
      ],
      confirmLabel: 'حذف نهائي',
      destructive: true,
    );
    if (!confirmed || !mounted) return;

    final ok = await cubit.forceDelete(tariff.id);
    if (!mounted) return;
    if (ok) {
      AppFeedback.success(context, 'تم حذف «${tariff.name}» نهائياً.');
    } else {
      AppFeedback.error(
        context,
        cubit.state.errorMessage ?? 'تعذّر الحذف النهائي.',
      );
    }
  }
}

class _ActivePriceHeader extends StatelessWidget {
  const _ActivePriceHeader({required this.state});

  final TariffsState state;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      margin: const EdgeInsetsDirectional.fromSTEB(16, 12, 16, 0),
      padding: const EdgeInsetsDirectional.fromSTEB(14, 12, 14, 12),
      decoration: BoxDecoration(
        color: AppColors.panel,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.panelLine),
      ),
      child: Row(
        children: <Widget>[
          Container(
            width: 30,
            height: 30,
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: .08),
              borderRadius: BorderRadius.circular(AppRadius.sm),
            ),
            child: const Icon(
              Icons.bolt_rounded,
              color: AppColors.onPanelSoft,
              size: 18,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              'السعر النشط المستخدم في الفوترة',
              style: theme.textTheme.labelSmall?.copyWith(
                color: AppColors.onPanelMuted,
              ),
            ),
          ),
          Text(
            state.activePrice == null
                ? '—'
                : CurrencyFormatter.withLabel(state.activePrice),
            style: const TextStyle(
              fontSize: 14,
              height: 1.3,
              fontWeight: FontWeight.w600,
              color: AppColors.onPanel,
              fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }
}

/// شريط العمليات الجماعية: عدّاد + تحديد الكل + استعادة/حذف نهائي.
///
/// يظهر فقط في وضع التحديد داخل «المحذوفة»، لأن هذين الإجراءين لا معنى لهما
/// على تسعيرة حيّة — وهذا ما يمنع حذف تسعيرة نشطة بالخطأ من شريط جماعي.
class _BulkBar extends StatelessWidget {
  const _BulkBar({
    required this.state,
    required this.onSelectAll,
    required this.onClear,
    required this.onRestore,
    required this.onForceDelete,
  });

  final TariffsState state;
  final VoidCallback onSelectAll;
  final VoidCallback onClear;
  final VoidCallback onRestore;
  final VoidCallback onForceDelete;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final count = state.selectedIds.length;
    final busy = state.saving;

    return Container(
      margin: const EdgeInsetsDirectional.fromSTEB(16, 10, 16, 0),
      padding: const EdgeInsetsDirectional.fromSTEB(12, 8, 12, 8),
      decoration: BoxDecoration(
        color: AppColors.accentSoft,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Text(
              count == 0 ? 'حدّد تسعيرات للعمليات الجماعية' : 'المحدّد: $count',
              style: theme.textTheme.labelMedium?.copyWith(
                color: AppColors.inkSoft,
              ),
            ),
          ),
          TextButton(
            onPressed: busy ? null : onSelectAll,
            child: const Text('الكل'),
          ),
          if (count > 0)
            TextButton(
              onPressed: busy ? null : onClear,
              child: const Text('مسح'),
            ),
          if (count > 0) ...<Widget>[
            IconButton(
              tooltip: 'استعادة المحدّد',
              onPressed: busy ? null : onRestore,
              icon: const Icon(Icons.restore_rounded, size: 20),
            ),
            IconButton(
              tooltip: 'حذف نهائي للمحدّد',
              onPressed: busy ? null : onForceDelete,
              icon: const Icon(Icons.delete_forever_rounded, size: 20),
            ),
          ],
        ],
      ),
    );
  }
}
