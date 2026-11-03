import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/database/offline_pack_downloader.dart';
import '../../../../core/l10n/app_strings.dart';
import '../../../../core/l10n/field_experience_strings.dart';
import '../../../../core/l10n/field_list_options_strings.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/models/record_location.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/list_presentation_controls.dart';
import '../../../../core/ui/record_location_label.dart';
import '../../../../core/utils/compound_search_parser.dart';
import '../../../../core/widgets/empty_state_widget.dart';
import '../../../../core/widgets/status_badge.dart';
import '../../../meters/data/models/meter.dart';
import '../../../meters/domain/meters_repository.dart';
import '../../data/models/reading.dart';
import '../../data/services/reading_invoice_actions_service.dart';
import '../../domain/readings_repository.dart';
import '../cubit/readings_cubit.dart';
import '../widgets/offline_pack_dialog.dart';
import '../widgets/record_reading_sheet.dart';

part '../widgets/reading_card.dart';
part '../widgets/readings_filters.dart';

class ReadingsListScreen extends StatelessWidget {
  const ReadingsListScreen({
    required this.readingsRepository,
    required this.metersRepository,
    this.invoiceActions,
    this.offlinePackDownloader,
    super.key,
  });

  final ReadingsRepository readingsRepository;
  final MetersRepository metersRepository;
  final ReadingInvoiceActionsService? invoiceActions;
  final OfflinePackDownloader? offlinePackDownloader;

  @override
  Widget build(BuildContext context) => BlocProvider(
    create: (_) =>
        ReadingsCubit(
            readingsRepository: readingsRepository,
            metersRepository: metersRepository,
          )
          ..load()
          ..loadPresets(),
    child: _ReadingsListView(
      readingsRepository: readingsRepository,
      metersRepository: metersRepository,
      invoiceActions:
          invoiceActions ??
          (GetIt.I.isRegistered<ReadingInvoiceActionsService>()
              ? GetIt.I<ReadingInvoiceActionsService>()
              : null),
      offlinePackDownloader: offlinePackDownloader,
    ),
  );
}

class _ReadingsListView extends StatelessWidget {
  const _ReadingsListView({
    required this.readingsRepository,
    required this.metersRepository,
    this.invoiceActions,
    this.offlinePackDownloader,
  });

  final ReadingsRepository readingsRepository;
  final MetersRepository metersRepository;
  final ReadingInvoiceActionsService? invoiceActions;
  final OfflinePackDownloader? offlinePackDownloader;

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<ReadingsCubit>();
    return Directionality(
      textDirection: TextDirection.rtl,
      child: SubPageScaffold(
        title: 'سجل القراءات',
        subtitle: 'أرشيف القراءات المسجلة للعدادات',
        actions: [
          BlocBuilder<ReadingsCubit, ReadingsState>(
            buildWhen: (previous, current) =>
                previous.selectedKeys.length != current.selectedKeys.length,
            builder: (context, state) => state.isSelecting
                ? Padding(
                    padding: const EdgeInsetsDirectional.only(end: 4),
                    child: Text(
                      '${state.selectedKeys.length}',
                      style: Theme.of(context).textTheme.labelMedium,
                    ),
                  )
                : const SizedBox.shrink(),
          ),
          BlocBuilder<ReadingsCubit, ReadingsState>(
            buildWhen: (previous, current) =>
                previous.selectedKeys != current.selectedKeys ||
                previous.isMutating != current.isMutating ||
                previous.squares != current.squares ||
                previous.squareFilter != current.squareFilter,
            builder: (context, state) {
              if (!state.isSelecting) {
                return AppIconButton(
                  icon: Icons.cloud_download_outlined,
                  size: 38,
                  tooltip: 'تنزيل حزمة العمل أوفلاين',
                  onPressed: () async {
                    final res = await OfflinePackDialog.show(
                      context,
                      downloader: offlinePackDownloader,
                      squares: state.squares,
                      initialSquareId: state.squareFilter,
                    );
                    if (res != null && context.mounted) {
                      await cubit.load();
                    }
                  },
                );
              }
              final selected = cubit.selectedReadings;
              final canIssue =
                  selected.isNotEmpty &&
                  selected.every(
                    (reading) => !reading.isInvoiced && reading.id != null,
                  );
              return Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  AppIconButton(
                    size: 38,
                    icon: Icons.select_all_rounded,
                    tooltip: 'تحديد كل الظاهر',
                    onPressed: state.isMutating ? null : cubit.selectAllVisible,
                  ),
                  PopupMenuButton<String>(
                    tooltip: 'إجراءات جماعية',
                    onSelected: (action) {
                      switch (action) {
                        case 'invoice':
                          if (canIssue) {
                            unawaited(_confirmBulkInvoice(context, cubit));
                          } else {
                            _showIssueUnavailable(context);
                          }
                          break;
                        case 'delete':
                          unawaited(_confirmBulkDelete(context, cubit));
                          break;
                        case 'clear':
                          cubit.clearSelection();
                          break;
                      }
                    },
                    itemBuilder: (context) => [
                      PopupMenuItem<String>(
                        value: 'invoice',
                        enabled: canIssue && !state.isMutating,
                        child: const ListTile(
                          dense: true,
                          leading: Icon(Icons.receipt_long_outlined),
                          title: Text('إصدار الفواتير المحددة'),
                        ),
                      ),
                      PopupMenuItem<String>(
                        value: 'delete',
                        enabled: !state.isMutating,
                        child: const ListTile(
                          dense: true,
                          leading: Icon(Icons.delete_outline),
                          title: Text('حذف المحدد بذكاء'),
                        ),
                      ),
                      const PopupMenuItem<String>(
                        value: 'clear',
                        child: ListTile(
                          dense: true,
                          leading: Icon(Icons.close),
                          title: Text('إلغاء التحديد'),
                        ),
                      ),
                    ],
                  ),
                ],
              );
            },
          ),
          AppIconButton(
            icon: Icons.add_chart_outlined,
            tooltip: 'إضافة قراءة',
            size: 38,
            onPressed: () => _openAddReadingPicker(context, cubit),
          ),
        ],
        child: BlocConsumer<ReadingsCubit, ReadingsState>(
          listenWhen: (previous, current) =>
              previous.errorMessage != current.errorMessage &&
                  current.errorMessage != null ||
              previous.actionMessage != current.actionMessage &&
                  current.actionMessage != null,
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
              for (var index = 0; index < state.readings.length; index++)
                state.readings[index]: index,
            };
            return ListPresentation<Reading>(
              items: state.readings,
              initialSettings: const ListPresentationSettings(
                sortBy: 'default',
                descending: false,
              ),
              sortValues: {
                'created_at': (
                  label: ListOptionsStrings.createdAt,
                  value: (reading) => reading.createdAt,
                ),
                'square_id': (
                  label: ListOptionsStrings.square,
                  value: (reading) => reading.squareId,
                ),
                'default': (
                  label: ListOptionsStrings.reset,
                  value: (reading) => defaultPositions[reading],
                ),
                'id': (
                  label: ListOptionsStrings.id,
                  value: (reading) => reading.id,
                ),
                'meter_code': (
                  label: ListOptionsStrings.meter,
                  value: (reading) => reading.meterCode,
                ),
                'subscriber_name': (
                  label: ListOptionsStrings.subscriber,
                  value: (reading) => reading.subscriberName,
                ),
                'path_number': (
                  label: FieldListOptionsStrings.pathNumber,
                  value: (reading) => reading.pathNumber,
                ),
                'square_name': (
                  label: ListOptionsStrings.square,
                  value: (reading) => reading.squareName,
                ),
                'tenant_name': (
                  label: FieldExperienceStrings.tenant,
                  value: (reading) => reading.tenantName,
                ),
                'is_invoiced': (
                  label: FieldListOptionsStrings.invoicingStatus,
                  value: (reading) => reading.isInvoiced ? 1 : 0,
                ),
                'reading_date': (
                  label: ListOptionsStrings.readingDate,
                  value: (reading) => reading.readingDate,
                ),
                'actual_reading_date': (
                  label: ListOptionsStrings.actualReadingDate,
                  value: (reading) => reading.actualReadingDate,
                ),
                'previous_reading': (
                  label: ListOptionsStrings.previousReading,
                  value: (reading) => reading.previousReading,
                ),
                'current_reading': (
                  label: ListOptionsStrings.currentReading,
                  value: (reading) => reading.currentReading,
                ),
                'consumption_units': (
                  label: ListOptionsStrings.consumption,
                  value: (reading) => reading.consumptionUnits,
                ),
              },
              fields: const {
                'subscriber': ListOptionsStrings.subscriber,
                'status': ListOptionsStrings.status,
                'previous_reading': ListOptionsStrings.previousReading,
                'current_reading': ListOptionsStrings.currentReading,
                'consumption': ListOptionsStrings.consumption,
                'reading_date': ListOptionsStrings.readingDate,
                'actual_reading_date': ListOptionsStrings.actualReadingDate,
                'period': ListOptionsStrings.period,
                'photo': ListOptionsStrings.photo,
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
                              6,
                              16,
                              10,
                            ),
                            child: AppStatStrip(
                              items: [
                                AppStatItem(
                                  label: 'القراءات الظاهرة',
                                  value: '${state.readings.length}',
                                  icon: Icons.speed_rounded,
                                  color: Theme.of(context).colorScheme.primary,
                                ),
                                AppStatItem(
                                  label: 'مفوترة',
                                  value:
                                      '${state.readings.where((r) => r.isInvoiced).length}',
                                  icon: Icons.receipt_long_outlined,
                                  color: Theme.of(
                                    context,
                                  ).colorScheme.onSurfaceVariant,
                                ),
                                AppStatItem(
                                  label: 'غير مفوترة',
                                  value:
                                      '${state.readings.where((r) => !r.isInvoiced).length}',
                                  icon: Icons.pending_actions_rounded,
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
                    if (state.status == ReadingsLoadStatus.loading &&
                        state.readings.isEmpty)
                      const SliverFillRemaining(
                        hasScrollBody: false,
                        child: Center(child: CircularProgressIndicator()),
                      )
                    else if (state.status == ReadingsLoadStatus.failure &&
                        state.readings.isEmpty)
                      SliverFillRemaining(
                        hasScrollBody: false,
                        child: EmptyStateWidget(
                          icon: Icons.cloud_off_rounded,
                          title: 'تعذر تحميل القراءات',
                          message:
                              state.errorMessage ??
                              'تحقق من الاتصال ثم أعد المحاولة.',
                          action: TextButton.icon(
                            onPressed: cubit.load,
                            icon: const Icon(Icons.refresh_rounded, size: 18),
                            label: const Text('إعادة المحاولة'),
                          ),
                        ),
                      )
                    else if (state.readings.isEmpty)
                      SliverFillRemaining(
                        hasScrollBody: false,
                        child: EmptyStateWidget(
                          icon: Icons.speed_rounded,
                          title: !state.hasFilters
                              ? 'لا توجد قراءات بعد'
                              : 'لا نتائج مطابقة',
                          message: !state.hasFilters
                              ? 'سجّل أول قراءة لعداد من زر الإضافة.'
                              : 'جرّب تعديل البحث أو إزالة بعض الفلاتر.',
                          action: !state.hasFilters
                              ? null
                              : TextButton(
                                  onPressed: cubit.clearAllFilters,
                                  child: const Text('مسح الفلاتر'),
                                ),
                        ),
                      )
                    else
                      SliverList.builder(
                        itemCount: visible.length,
                        itemBuilder: (context, index) => _ReadingCard(
                          reading: visible[index],
                          presentation: settings,
                          selected: state.selectedKeys.contains(
                            visible[index].selectionKey,
                          ),
                          selectionMode: state.isSelecting,
                          onTap: () => cubit.toggleSelection(visible[index]),
                          onLongPress: () =>
                              cubit.toggleSelection(visible[index]),
                          onPreviewPhoto: visible[index].hasPhoto
                              ? () => _previewReadingPhoto(
                                  context,
                                  visible[index],
                                )
                              : null,
                          onEdit: visible[index].isInvoiced
                              ? null
                              : () => _editReading(
                                  context,
                                  visible[index],
                                  cubit,
                                ),
                          onDelete: visible[index].isInvoiced
                              ? null
                              : () => _confirmDelete(
                                  context,
                                  visible[index],
                                  cubit,
                                ),
                        ),
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

  Future<void> _openAddReadingPicker(
    BuildContext context,
    ReadingsCubit cubit,
  ) async {
    List<Meter> meters = const [];
    bool loading = true;
    String? loadErr;
    String searchQuery = '';

    final picked = await showModalBottomSheet<Meter>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (sheetCtx) {
        var didStartLoad = false;
        return StatefulBuilder(
          builder: (sheetCtx, setSheetState) {
            if (!didStartLoad) {
              didStartLoad = true;
              metersRepository
                  .getMeters(
                    squareId: cubit.state.squareFilter,
                    status: 'active',
                  )
                  .then((list) {
                    if (sheetCtx.mounted) {
                      setSheetState(() {
                        meters = list;
                        loading = false;
                      });
                    }
                  })
                  .catchError((_) {
                    if (sheetCtx.mounted) {
                      setSheetState(() {
                        loadErr = 'تعذر تحميل قائمة العدادات.';
                        loading = false;
                      });
                    }
                  });
            }

            final filtered = meters
                .where((m) {
                  final q = searchQuery.trim().toLowerCase();
                  if (q.isEmpty) return true;
                  return m.uniqueCode.toLowerCase().contains(q) ||
                      (m.subscriberName ?? '').toLowerCase().contains(q) ||
                      (m.tenantName ?? '').toLowerCase().contains(q) ||
                      (m.pathNumber?.toString() ?? '').contains(q);
                })
                .toList(growable: false);

            return Directionality(
              textDirection: TextDirection.rtl,
              child: SafeArea(
                child: FractionallySizedBox(
                  heightFactor: 0.8,
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text(
                          'اختر العداد لتسجيل قراءة جديدة',
                          style: Theme.of(sheetCtx).textTheme.titleMedium
                              ?.copyWith(fontWeight: FontWeight.bold),
                        ),
                        const SizedBox(height: 10),
                        TextField(
                          decoration: const InputDecoration(
                            prefixIcon: Icon(Icons.search),
                            hintText:
                                'ابحث بكود العداد أو المشترك أو رقم المسار…',
                          ),
                          onChanged: (val) =>
                              setSheetState(() => searchQuery = val),
                        ),
                        const SizedBox(height: 10),
                        Expanded(
                          child: loading
                              ? const Center(child: CircularProgressIndicator())
                              : loadErr != null
                              ? Center(child: Text(loadErr!))
                              : filtered.isEmpty
                              ? const Center(
                                  child: Text('لا توجد عدادات نشطة مطابقة.'),
                                )
                              : ListView.separated(
                                  itemCount: filtered.length,
                                  separatorBuilder: (_, __) =>
                                      const Divider(height: 1),
                                  itemBuilder: (_, i) {
                                    final m = filtered[i];
                                    return ListTile(
                                      leading: const Icon(Icons.speed_outlined),
                                      title: Text(
                                        '${m.uniqueCode} — ${m.subscriberName ?? 'مشترك'}',
                                      ),
                                      subtitle: Text(
                                        '${m.squareName ?? ''}'
                                        '${m.pathNumber != null ? ' • المسار #${m.pathNumber}' : ''}'
                                        ' • آخر قراءة: ${_number(m.lastReading)}',
                                      ),
                                      onTap: () => Navigator.pop(sheetCtx, m),
                                    );
                                  },
                                ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            );
          },
        );
      },
    );

    if (picked == null || !context.mounted) return;
    await RecordReadingSheet.show(
      context,
      meter: picked,
      readingsRepository: readingsRepository,
      invoiceActions: invoiceActions,
      onNextPath: (current) async {
        final squareId = current.squareId ?? cubit.state.squareFilter;
        if (squareId == null || current.pathNumber == null) return null;
        final all = await metersRepository.getMeters(
          squareId: squareId,
          status: 'active',
        );
        final next =
            all
                .where(
                  (m) =>
                      m.pathNumber != null &&
                      m.pathNumber! > current.pathNumber!,
                )
                .toList()
              ..sort((a, b) => a.pathNumber!.compareTo(b.pathNumber!));
        return next.isEmpty ? null : next.first;
      },
    );
    if (context.mounted) {
      await cubit.load();
    }
  }

  void _previewReadingPhoto(BuildContext context, Reading reading) {
    final localPath = reading.imagePath;
    final hasLocalFile =
        localPath != null &&
        localPath.trim().isNotEmpty &&
        File(localPath).existsSync();
    final remoteUrl = reading.imageUrl;

    showDialog<void>(
      context: context,
      builder: (dialogCtx) => Directionality(
        textDirection: TextDirection.rtl,
        child: Dialog(
          clipBehavior: Clip.antiAlias,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 640, maxHeight: 680),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                AppBar(
                  automaticallyImplyLeading: false,
                  title: Text(
                    'صورة قراءة العداد ${reading.meterCode ?? reading.meterId}',
                  ),
                  actions: [
                    IconButton(
                      icon: const Icon(Icons.close),
                      onPressed: () => Navigator.pop(dialogCtx),
                    ),
                  ],
                ),
                Flexible(
                  child: InteractiveViewer(
                    minScale: 0.5,
                    maxScale: 4.0,
                    child: hasLocalFile
                        ? Image.file(File(localPath), fit: BoxFit.contain)
                        : (remoteUrl != null && remoteUrl.trim().isNotEmpty)
                        ? Image.network(
                            remoteUrl,
                            fit: BoxFit.contain,
                            loadingBuilder: (ctx, child, progress) =>
                                progress == null
                                ? child
                                : const Padding(
                                    padding: EdgeInsets.all(48),
                                    child: CircularProgressIndicator(),
                                  ),
                            errorBuilder: (_, __, ___) => const Padding(
                              padding: EdgeInsets.all(32),
                              child: Text('تعذر تحميل صورة العداد من الخادم.'),
                            ),
                          )
                        : const Padding(
                            padding: EdgeInsets.all(32),
                            child: Text('لا تتوفر معاينة للصورة.'),
                          ),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.all(12),
                  child: Text(
                    'القراءة: ${_number(reading.currentReading)} • التاريخ الفعلي: ${_date(reading.actualReadingDate ?? reading.readingDate)}',
                    style: Theme.of(dialogCtx).textTheme.bodySmall,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _filters(
    BuildContext context,
    ReadingsState state,
    ReadingsCubit cubit,
  ) => _ReadingsFilters(state: state, cubit: cubit);

  Future<void> _confirmBulkInvoice(
    BuildContext context,
    ReadingsCubit cubit,
  ) async {
    final accepted = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('إصدار فواتير جماعي'),
        content: Text(
          'سيتم إصدار فواتير للقراءات المحددة (${cubit.selectedReadings.length}). هل تريد المتابعة؟',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('إصدار الفواتير'),
          ),
        ],
      ),
    );
    if (accepted == true) await cubit.issueSelectedInvoices();
  }

  Future<void> _confirmBulkDelete(
    BuildContext context,
    ReadingsCubit cubit,
  ) async {
    final selected = cubit.selectedReadings;
    final invoicedCount = selected
        .where((reading) => reading.isInvoiced)
        .length;
    final accepted = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('حذف القراءات المحددة'),
        content: Text(
          'سيُحذف غير المفوتر فقط، وستُتخطى $invoicedCount قراءة مفوترة. هل تريد المتابعة؟',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('إلغاء'),
          ),
          FilledButton.tonal(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('حذف غير المفوتر'),
          ),
        ],
      ),
    );
    if (accepted == true) await cubit.deleteSelectedReadings();
  }

  Future<void> _confirmDelete(
    BuildContext context,
    Reading reading,
    ReadingsCubit cubit,
  ) async {
    final accepted = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('حذف القراءة'),
        content: const Text('هل أنت متأكد من حذف هذه القراءة؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('إلغاء'),
          ),
          FilledButton.tonal(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('حذف'),
          ),
        ],
      ),
    );
    if (accepted == true) await cubit.deleteReading(reading);
  }

  Future<void> _editReading(
    BuildContext context,
    Reading reading,
    ReadingsCubit cubit,
  ) async {
    final controller = TextEditingController(
      text: _number(reading.currentReading),
    );
    final formKey = GlobalKey<FormState>();
    DateTime editedDate = reading.readingDate;

    final updated =
        await showDialog<({double currentReading, DateTime readingDate})>(
          context: context,
          builder: (dialogCtx) => StatefulBuilder(
            builder: (dialogCtx, setDialogState) => Directionality(
              textDirection: TextDirection.rtl,
              child: AlertDialog(
                title: const Text('تعديل قراءة غير مفوترة'),
                content: Form(
                  key: formKey,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      TextFormField(
                        controller: controller,
                        autofocus: true,
                        textDirection: TextDirection.ltr,
                        keyboardType: const TextInputType.numberWithOptions(
                          decimal: true,
                        ),
                        decoration: InputDecoration(
                          labelText: 'القراءة الحالية',
                          helperText:
                              'القراءة السابقة: ${_number(reading.previousReading)}',
                        ),
                        validator: (value) {
                          final parsed = double.tryParse(
                            CompoundSearchQuery.normalizeDigits(
                              value ?? '',
                            ).replaceAll(',', '.'),
                          );
                          if (parsed == null) return 'أدخل رقماً صحيحاً.';
                          if (parsed < reading.previousReading) {
                            return 'لا يمكن أن تقل عن القراءة السابقة.';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: 12),
                      InkWell(
                        onTap: () async {
                          final picked = await showDatePicker(
                            context: dialogCtx,
                            initialDate: editedDate,
                            firstDate: DateTime(2000),
                            lastDate: DateTime.now().add(
                              const Duration(days: 1),
                            ),
                            helpText: 'تعديل تاريخ القراءة',
                          );
                          if (picked != null) {
                            setDialogState(() => editedDate = picked);
                          }
                        },
                        borderRadius: BorderRadius.circular(AppRadius.xs),
                        child: InputDecorator(
                          decoration: const InputDecoration(
                            labelText: 'تاريخ القراءة',
                            prefixIcon: Icon(Icons.calendar_month_outlined),
                          ),
                          child: Text(_date(editedDate)),
                        ),
                      ),
                    ],
                  ),
                ),
                actions: [
                  TextButton(
                    onPressed: () => Navigator.pop(dialogCtx),
                    child: const Text('إلغاء'),
                  ),
                  FilledButton(
                    onPressed: () {
                      if (formKey.currentState!.validate()) {
                        Navigator.pop(dialogCtx, (
                          currentReading: double.parse(
                            CompoundSearchQuery.normalizeDigits(
                              controller.text,
                            ).replaceAll(',', '.'),
                          ),
                          readingDate: editedDate,
                        ));
                      }
                    },
                    child: const Text('حفظ'),
                  ),
                ],
              ),
            ),
          ),
        );
    controller.dispose();
    if (updated != null) {
      await cubit.updateReading(
        reading,
        updated.currentReading,
        newReadingDate: updated.readingDate,
      );
    }
  }

  void _showIssueUnavailable(BuildContext context) {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('يجب أن تكون القراءات المحددة غير مفوترة ومزامنة أولاً.'),
      ),
    );
  }
}
