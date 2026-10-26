import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/l10n/field_experience_strings.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/list_presentation_controls.dart';
import '../../../../core/widgets/empty_state_widget.dart';
import '../../../readings/data/services/reading_invoice_actions_service.dart';
import '../../../readings/domain/readings_repository.dart';
import '../../../readings/presentation/widgets/record_reading_sheet.dart';
import '../../data/models/meter.dart';
import '../../domain/meters_repository.dart';
import '../cubit/meters_cubit.dart';
import '../widgets/meter_card_widget.dart';
import 'meter_form_screen.dart';

class MetersListScreen extends StatelessWidget {
  const MetersListScreen({
    required this.metersRepository,
    required this.readingsRepository,
    this.invoiceActions,
    this.onOpenFieldReader,
    super.key,
  });

  final MetersRepository metersRepository;
  final ReadingsRepository readingsRepository;
  final ReadingInvoiceActionsService? invoiceActions;
  final VoidCallback? onOpenFieldReader;

  @override
  Widget build(BuildContext context) {
    return BlocProvider(
      create: (_) => MetersCubit(metersRepository)..load(),
      child: _MetersListView(
        metersRepository: metersRepository,
        readingsRepository: readingsRepository,
        invoiceActions: invoiceActions,
        onOpenFieldReader: onOpenFieldReader,
      ),
    );
  }
}

class _MetersListView extends StatelessWidget {
  const _MetersListView({
    required this.metersRepository,
    required this.readingsRepository,
    this.invoiceActions,
    this.onOpenFieldReader,
  });

  final MetersRepository metersRepository;
  final ReadingsRepository readingsRepository;
  final ReadingInvoiceActionsService? invoiceActions;
  final VoidCallback? onOpenFieldReader;

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<MetersCubit>();
    return Directionality(
      textDirection: TextDirection.rtl,
      child: SubPageScaffold(
        title: 'إدارة العدادات',
        subtitle: 'متابعة الحالات والقراءات الميدانية',
        actions: [
          BlocBuilder<MetersCubit, MetersState>(
            buildWhen: (previous, current) =>
                previous.selectedIds.length != current.selectedIds.length,
            builder: (context, state) => state.isSelecting
                ? Padding(
                    padding: const EdgeInsetsDirectional.only(end: 4),
                    child: Text(
                      '${state.selectedIds.length}',
                      style: Theme.of(context).textTheme.labelMedium,
                    ),
                  )
                : const SizedBox.shrink(),
          ),
          BlocBuilder<MetersCubit, MetersState>(
            buildWhen: (prev, curr) =>
                prev.selectedIds != curr.selectedIds ||
                prev.isMutating != curr.isMutating ||
                prev.meters.length != curr.meters.length,
            builder: (context, state) {
              return Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (state.meters.isNotEmpty)
                    AppIconButton(
                      size: 38,
                      color: state.isSelecting
                          ? Theme.of(context).colorScheme.primary
                          : null,
                      tooltip: state.isSelecting
                          ? 'إلغاء التحديد'
                          : 'تحديد الكل',
                      onPressed: state.isMutating
                          ? null
                          : (state.isSelecting
                                ? cubit.clearSelection
                                : cubit.selectAllVisible),
                      icon: state.isSelecting
                          ? Icons.deselect
                          : Icons.select_all,
                    ),
                  if (state.isSelecting)
                    AppIconButton(
                      size: 38,
                      color: Theme.of(context).colorScheme.error,
                      tooltip: 'حذف العدادات المحددة',
                      onPressed: state.isMutating
                          ? null
                          : () => _confirmBulkDelete(
                              context,
                              cubit,
                              state.selectedIds.length,
                            ),
                      icon: Icons.delete_sweep_outlined,
                    ),
                  if (!state.isSelecting && onOpenFieldReader != null)
                    AppIconButton(
                      size: 38,
                      tooltip: 'القارئ الميداني',
                      onPressed: onOpenFieldReader,
                      icon: Icons.qr_code_scanner_rounded,
                    ),
                ],
              );
            },
          ),
          const SizedBox(width: 8),
          AppIconButton(
            icon: Icons.add_rounded,
            tooltip: 'إضافة عداد جديد',
            size: 38,
            onPressed: () => _openForm(context),
          ),
        ],
        child: BlocConsumer<MetersCubit, MetersState>(
          listenWhen: (previous, current) =>
              (previous.errorMessage != current.errorMessage &&
                  current.errorMessage != null) ||
              (previous.actionMessage != current.actionMessage &&
                  current.actionMessage != null),
          listener: (context, state) {
            final message = state.errorMessage ?? state.actionMessage;
            if (message != null) {
              ScaffoldMessenger.of(
                context,
              ).showSnackBar(SnackBar(content: Text(message)));
            }
          },
          builder: (context, state) {
            final defaultPositions = {
              for (var index = 0; index < state.meters.length; index++)
                state.meters[index]: index,
            };
            return ListPresentation<Meter>(
              items: state.meters,
              initialSettings: const ListPresentationSettings(
                sortBy: 'default',
                descending: false,
                hiddenFields: {'created_at'},
              ),
              sortValues: {
                'default': (
                  label: ListOptionsStrings.reset,
                  value: (meter) => defaultPositions[meter],
                ),
                'id': (
                  label: ListOptionsStrings.id,
                  value: (meter) => meter.id,
                ),
                'unique_code': (
                  label: FieldExperienceStrings.meterCode,
                  value: (meter) => meter.uniqueCode.toLowerCase(),
                ),
                'path_number': (
                  label: FieldExperienceStrings.path,
                  value: (meter) => meter.pathNumber,
                ),
                'subscriber_name': (
                  label: ListOptionsStrings.subscriber,
                  value: (meter) => meter.subscriberName,
                ),
                'square_name': (
                  label: ListOptionsStrings.square,
                  value: (meter) => meter.squareName,
                ),
                'tenant_name': (
                  label: FieldExperienceStrings.tenant,
                  value: (meter) => meter.tenantName,
                ),
                'status': (
                  label: ListOptionsStrings.status,
                  value: (meter) => meter.status,
                ),
                'balance': (
                  label: ListOptionsStrings.balance,
                  value: (meter) => meter.balance,
                ),
                'last_reading': (
                  label: FieldExperienceStrings.lastReading,
                  value: (meter) => meter.lastReading,
                ),
                'created_at': (
                  label: ListOptionsStrings.createdAt,
                  value: (meter) => meter.createdAt,
                ),
              },
              fields: const {
                'subscriber': ListOptionsStrings.subscriber,
                'code': FieldExperienceStrings.meterCode,
                'square': ListOptionsStrings.square,
                'path': FieldExperienceStrings.path,
                'tenant': FieldExperienceStrings.tenant,
                'status': ListOptionsStrings.status,
                'last_reading': FieldExperienceStrings.lastReading,
                'balance': ListOptionsStrings.balance,
                'created_at': ListOptionsStrings.createdAt,
              },
              builder: (context, visible, settings) => RefreshIndicator(
                onRefresh: cubit.load,
                child: CustomScrollView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  slivers: [
                    SliverToBoxAdapter(
                      child: Column(
                        children: [
                          _filters(context, state, cubit),
                          Padding(
                            padding: const EdgeInsetsDirectional.fromSTEB(
                              16,
                              8,
                              16,
                              8,
                            ),
                            child: AppStatStrip(
                              items: [
                                AppStatItem(
                                  label: 'إجمالي النتائج',
                                  value: '${state.meters.length}',
                                  icon: Icons.speed_rounded,
                                  color: Theme.of(context).colorScheme.primary,
                                ),
                                AppStatItem(
                                  label: 'نشطة',
                                  value:
                                      '${state.meters.where((m) => m.status.toLowerCase() == 'active').length}',
                                  icon: Icons.check_circle_outline_rounded,
                                  color: Theme.of(context).colorScheme.primary,
                                ),
                                AppStatItem(
                                  label: 'غير نشطة',
                                  value:
                                      '${state.meters.where((m) => m.status.toLowerCase() != 'active').length}',
                                  icon: Icons.pause_circle_outline_rounded,
                                  color: Theme.of(
                                    context,
                                  ).colorScheme.onSurfaceVariant,
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                    if (state.isSelecting)
                      SliverToBoxAdapter(
                        child: MaterialBanner(
                          content: Text(
                            'محدد ${state.selectedIds.length} عداد',
                          ),
                          actions: [
                            TextButton(
                              onPressed: state.isMutating
                                  ? null
                                  : cubit.selectAllVisible,
                              child: const Text('تحديد كل الظاهر'),
                            ),
                            TextButton(
                              onPressed: state.isMutating
                                  ? null
                                  : cubit.clearSelection,
                              child: const Text('إلغاء'),
                            ),
                            FilledButton.tonalIcon(
                              onPressed: state.isMutating
                                  ? null
                                  : () => _confirmBulkDelete(
                                      context,
                                      cubit,
                                      state.selectedIds.length,
                                    ),
                              icon: const Icon(
                                Icons.delete_outline,
                                color: Colors.redAccent,
                              ),
                              label: const Text('حذف المحدد'),
                            ),
                          ],
                        ),
                      ),
                    if (state.status == MetersLoadStatus.loading &&
                        state.meters.isEmpty)
                      const SliverFillRemaining(
                        hasScrollBody: false,
                        child: Center(child: CircularProgressIndicator()),
                      )
                    else if (state.status == MetersLoadStatus.failure &&
                        state.meters.isEmpty)
                      SliverFillRemaining(
                        hasScrollBody: false,
                        child: EmptyStateWidget(
                          icon: Icons.wifi_off_outlined,
                          title: 'تعذر تحميل العدادات',
                          message:
                              state.errorMessage ??
                              'تحقق من اتصال الإنترنت ثم أعد المحاولة.',
                          action: TextButton.icon(
                            onPressed: cubit.load,
                            icon: const Icon(Icons.refresh),
                            label: const Text('إعادة المحاولة'),
                          ),
                        ),
                      )
                    else if (state.meters.isEmpty)
                      SliverFillRemaining(
                        hasScrollBody: false,
                        child: EmptyStateWidget(
                          icon: Icons.filter_alt_off_rounded,
                          title: 'لا توجد عدادات مطابقة',
                          message:
                              'غيّر البحث أو أزل الفلاتر، أو أضف عداداً جديداً.',
                          action: TextButton.icon(
                            onPressed: () {
                              cubit.setQuery('');
                              cubit.setSquareFilter(null);
                              cubit.setStatusFilter(null);
                            },
                            icon: const Icon(
                              Icons.restart_alt_rounded,
                              size: 18,
                            ),
                            label: const Text('مسح الفلاتر'),
                          ),
                        ),
                      )
                    else
                      SliverLayoutBuilder(
                        builder: (context, constraints) {
                          final width = constraints.crossAxisExtent;
                          final columns = width >= 1000
                              ? 3
                              : (width >= 650 ? 2 : 1);
                          if (columns == 1) {
                            return SliverList.builder(
                              itemCount: visible.length,
                              itemBuilder: (context, index) => _meterCard(
                                context,
                                state,
                                cubit,
                                visible[index],
                                settings,
                              ),
                            );
                          }
                          return SliverList.builder(
                            itemCount: (visible.length / columns).ceil(),
                            itemBuilder: (context, row) => Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                for (var column = 0; column < columns; column++)
                                  Expanded(
                                    child:
                                        row * columns + column < visible.length
                                        ? _meterCard(
                                            context,
                                            state,
                                            cubit,
                                            visible[row * columns + column],
                                            settings,
                                          )
                                        : const SizedBox.shrink(),
                                  ),
                              ],
                            ),
                          );
                        },
                      ),
                    const SliverToBoxAdapter(child: SizedBox(height: 88)),
                  ],
                ),
              ),
            );
          },
        ),
      ),
    );
  }

  Widget _filters(BuildContext context, MetersState state, MetersCubit cubit) {
    final activeCount =
        (state.squareFilter == null ? 0 : 1) +
        (state.statusFilter == null ? 0 : 1);
    final squareName = _meterSquareName(state);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        const SizedBox(height: AppSpacing.xs),
        AppFilterBar(
          query: state.query,
          hint: 'ابحث بالعداد أو المشترك أو المربع/المسار 4/1',
          activeCount: activeCount,
          onOpenFilters: () => _openFilterSheet(context, state, cubit),
          onChanged: cubit.setQuery,
        ),
        AppFilterChips(
          children: <Widget>[
            AppFilterChip(
              label: 'كل العدادات',
              selected: state.statusFilter == null,
              onTap: () => cubit.setStatusFilter(null),
            ),
            AppFilterChip(
              label: 'نشط',
              selected: state.statusFilter == 'active',
              onTap: () => cubit.setStatusFilter(
                state.statusFilter == 'active' ? null : 'active',
              ),
            ),
            AppFilterChip(
              label: 'غير نشط',
              selected: state.statusFilter == 'inactive',
              onTap: () => cubit.setStatusFilter(
                state.statusFilter == 'inactive' ? null : 'inactive',
              ),
            ),
          ],
        ),
        AppActiveFilters(
          filters: <Widget>[
            if (squareName != null)
              AppFilterChip(
                label: squareName,
                icon: Icons.grid_view_rounded,
                selected: true,
                onRemove: () => cubit.setSquareFilter(null),
              ),
            if (state.statusFilter != null)
              AppFilterChip(
                label:
                    'الحالة · ${state.statusFilter == 'active' ? 'نشط' : 'غير نشط'}',
                selected: true,
                onRemove: () => cubit.setStatusFilter(null),
              ),
          ],
          onClearAll: activeCount == 0
              ? null
              : () {
                  cubit.setSquareFilter(null);
                  cubit.setStatusFilter(null);
                },
        ),
        if ((state.status == MetersLoadStatus.loading &&
                state.meters.isNotEmpty) ||
            state.isMutating)
          const Padding(
            padding: EdgeInsets.symmetric(horizontal: AppSpacing.md),
            child: LinearProgressIndicator(minHeight: 2),
          ),
      ],
    );
  }

  Widget _meterCard(
    BuildContext context,
    MetersState state,
    MetersCubit cubit,
    Meter meter,
    ListPresentationSettings settings,
  ) {
    return MeterCardWidget(
      meter: meter,
      presentation: settings,
      selected: state.selectedIds.contains(meter.id),
      selectionMode: state.isSelecting,
      onSelectChanged: (_) => cubit.toggleSelection(meter.id),
      onLongPress: () => cubit.toggleSelection(meter.id),
      onRecordReading: () => _recordReading(context, meter),
      onEdit: () => _openForm(context, meter),
      onDelete: () => _confirmDelete(context, cubit, meter),
    );
  }

  Future<void> _confirmDelete(
    BuildContext context,
    MetersCubit cubit,
    Meter meter,
  ) async {
    final accepted = await showDialog<bool>(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          title: const Text('حذف العداد'),
          content: Text(
            'هل تريد حذف العداد "${meter.uniqueCode}"؟ لا يمكن حذف عداد له سجل قراءات أو فواتير أو سندات محفوظة.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء'),
            ),
            FilledButton.tonal(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text(
                'حذف',
                style: TextStyle(color: Colors.redAccent),
              ),
            ),
          ],
        ),
      ),
    );
    if (accepted == true) {
      await cubit.deleteMeter(meter);
    }
  }

  Future<void> _confirmBulkDelete(
    BuildContext context,
    MetersCubit cubit,
    int count,
  ) async {
    final accepted = await showDialog<bool>(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          title: const Text('حذف جماعي للعدادات'),
          content: Text(
            'سيتم حذف العدادات المحددة ($count) التي ليس لها سجل قراءات أو فواتير مرتبط، وتخطي المرتبط منها تلقائياً. هل تريد المتابعة؟',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء'),
            ),
            FilledButton.tonal(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text(
                'حذف المحدد',
                style: TextStyle(color: Colors.redAccent),
              ),
            ),
          ],
        ),
      ),
    );
    if (accepted == true) {
      await cubit.deleteSelectedMeters();
    }
  }

  Future<void> _recordReading(BuildContext context, Meter meter) async {
    await RecordReadingSheet.show(
      context,
      meter: meter,
      readingsRepository: readingsRepository,
      invoiceActions: invoiceActions,
      onNextPath: context.read<MetersCubit>().nextPath,
    );
    if (context.mounted) await context.read<MetersCubit>().load();
  }

  Future<void> _openForm(BuildContext context, [Meter? meter]) async {
    final changed = await Navigator.of(context).push<bool>(
      MaterialPageRoute<bool>(
        builder: (_) =>
            MeterFormScreen(repository: metersRepository, meter: meter),
      ),
    );
    if (changed == true && context.mounted) {
      await context.read<MetersCubit>().load();
    }
  }
}

String? _meterSquareName(MetersState state) {
  if (state.squareFilter == null) return null;
  for (final square in state.squares) {
    if (square.id == state.squareFilter) return square.name;
  }
  return 'مربع #${state.squareFilter}';
}

Future<void> _openFilterSheet(
  BuildContext context,
  MetersState state,
  MetersCubit cubit,
) {
  return AppFilterSheet.show<void>(
    context: context,
    builder: (_) => Directionality(
      textDirection: TextDirection.rtl,
      child: _MetersFilterSheet(state: state, cubit: cubit),
    ),
  );
}

class _MetersFilterSheet extends StatefulWidget {
  const _MetersFilterSheet({required this.state, required this.cubit});

  final MetersState state;
  final MetersCubit cubit;

  @override
  State<_MetersFilterSheet> createState() => _MetersFilterSheetState();
}

class _MetersFilterSheetState extends State<_MetersFilterSheet> {
  late int? _square = widget.state.squareFilter;
  late String? _status = widget.state.statusFilter;

  void _apply() {
    if (_square != widget.state.squareFilter) {
      widget.cubit.setSquareFilter(_square);
    }
    if (_status != widget.state.statusFilter) {
      widget.cubit.setStatusFilter(_status);
    }
    Navigator.of(context).maybePop();
  }

  void _reset() => setState(() {
    _square = null;
    _status = null;
  });

  @override
  Widget build(BuildContext context) {
    return AppFilterSheet(
      title: 'تصفية العدادات',
      resultCountLabel: '${widget.state.meters.length} عداد في القائمة',
      onApply: _apply,
      onReset: _reset,
      resetEnabled: _square != null || _status != null,
      sections: <Widget>[
        AppFilterSection(
          title: 'حالة العداد',
          child: Column(
            children: <Widget>[
              AppFilterOptionTile(
                label: 'كل العدادات',
                icon: Icons.apps_rounded,
                selected: _status == null,
                onTap: () => setState(() => _status = null),
              ),
              const SizedBox(height: 6),
              AppFilterOptionTile(
                label: 'نشط',
                icon: Icons.check_circle_outline_rounded,
                selected: _status == 'active',
                onTap: () => setState(() => _status = 'active'),
              ),
              const SizedBox(height: 6),
              AppFilterOptionTile(
                label: 'غير نشط',
                icon: Icons.pause_circle_outline_rounded,
                selected: _status == 'inactive',
                onTap: () => setState(() => _status = 'inactive'),
              ),
            ],
          ),
        ),
        AppFilterSection(
          title: 'المربع السكني',
          subtitle: 'اختر مربعاً واحداً لعرض عداداته فقط',
          child: AppFilterDropdown<int?>(
            label: 'المربع',
            icon: Icons.grid_view_rounded,
            hint: 'كل المربعات',
            value: widget.state.squares.any((s) => s.id == _square)
                ? _square
                : null,
            items: <DropdownMenuItem<int?>>[
              const DropdownMenuItem<int?>(
                value: null,
                child: Text('كل المربعات'),
              ),
              ...widget.state.squares.map(
                (square) => DropdownMenuItem<int?>(
                  value: square.id,
                  child: Text(square.name, overflow: TextOverflow.ellipsis),
                ),
              ),
            ],
            onChanged: (value) => setState(() => _square = value),
          ),
        ),
        const SizedBox(height: 16),
      ],
    );
  }
}
