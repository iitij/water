import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/database/offline_pack_downloader.dart';
import '../../../../core/utils/compound_search_parser.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
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
        create: (_) => ReadingsCubit(
          readingsRepository: readingsRepository,
          metersRepository: metersRepository,
        )..load(),
        child: _ReadingsListView(
          readingsRepository: readingsRepository,
          metersRepository: metersRepository,
          invoiceActions: invoiceActions ??
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
      child: Scaffold(
        appBar: AppBar(
          title: BlocBuilder<ReadingsCubit, ReadingsState>(
            buildWhen: (previous, current) =>
                previous.selectedKeys.length != current.selectedKeys.length,
            builder: (context, state) => Text(
              state.isSelecting ? 'تم تحديد ${state.selectedKeys.length}' : 'سجل القراءات',
            ),
          ),
          actions: [
            BlocBuilder<ReadingsCubit, ReadingsState>(
              buildWhen: (previous, current) =>
                  previous.selectedKeys != current.selectedKeys ||
                  previous.isMutating != current.isMutating ||
                  previous.squares != current.squares ||
                  previous.squareFilter != current.squareFilter,
              builder: (context, state) {
                if (!state.isSelecting) {
                  return IconButton(
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
                    icon: const Icon(Icons.cloud_download_outlined),
                  );
                }
                final selected = cubit.selectedReadings;
                final canIssue = selected.isNotEmpty &&
                    selected.every((reading) => !reading.isInvoiced && reading.id != null);
                return Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    IconButton(
                      tooltip: 'تحديد كل الظاهر',
                      onPressed: state.isMutating ? null : cubit.selectAllVisible,
                      icon: const Icon(Icons.select_all),
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
          ],
        ),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () => _openAddReadingPicker(context, cubit),
          icon: const Icon(Icons.add_chart_outlined),
          label: const Text('إضافة قراءة'),
        ),
        body: BlocConsumer<ReadingsCubit, ReadingsState>(
          listenWhen: (previous, current) =>
              previous.errorMessage != current.errorMessage && current.errorMessage != null ||
              previous.actionMessage != current.actionMessage && current.actionMessage != null,
          listener: (context, state) {
            final message = state.errorMessage ?? state.actionMessage;
            if (message != null) {
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
            }
          },
          builder: (context, state) {
            return RefreshIndicator(
              onRefresh: cubit.load,
              child: CustomScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                slivers: [
                  SliverToBoxAdapter(child: _filters(context, state, cubit)),
                  if (state.status == ReadingsLoadStatus.loading && state.readings.isEmpty)
                    const SliverFillRemaining(
                      hasScrollBody: false,
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else if (state.status == ReadingsLoadStatus.failure && state.readings.isEmpty)
                    SliverFillRemaining(
                      hasScrollBody: false,
                      child: EmptyStateWidget(
                        icon: Icons.cloud_off_rounded,
                        title: 'تعذر تحميل القراءات',
                        message: state.errorMessage ?? 'تحقق من الاتصال ثم أعد المحاولة.',
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
                        title: state.query.isEmpty && state.squareFilter == null
                            ? 'لا توجد قراءات بعد'
                            : 'لا نتائج مطابقة',
                        message: state.query.isEmpty && state.squareFilter == null
                            ? 'سجّل أول قراءة لعداد من زر الإضافة.'
                            : 'جرّب تعديل البحث أو إزالة بعض الفلاتر.',
                        action: state.query.isEmpty && state.squareFilter == null
                            ? null
                            : TextButton(
                                onPressed: cubit.clearAllFilters,
                                child: const Text('مسح الفلاتر'),
                              ),
                      ),
                    )
                  else
                    SliverList.builder(
                      itemCount: state.readings.length,
                      itemBuilder: (context, index) => _ReadingCard(
                        reading: state.readings[index],
                        selected: state.selectedKeys.contains(state.readings[index].selectionKey),
                        selectionMode: state.isSelecting,
                        onTap: () => cubit.toggleSelection(state.readings[index]),
                        onLongPress: () => cubit.toggleSelection(state.readings[index]),
                        onPreviewPhoto: state.readings[index].hasPhoto
                            ? () => _previewReadingPhoto(context, state.readings[index])
                            : null,
                        onEdit: state.readings[index].isInvoiced
                            ? null
                            : () => _editReading(context, state.readings[index], cubit),
                        onDelete: state.readings[index].isInvoiced
                            ? null
                            : () => _confirmDelete(context, state.readings[index], cubit),
                      ),
                    ),
                  const SliverToBoxAdapter(child: SizedBox(height: 88)),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Future<void> _openAddReadingPicker(BuildContext context, ReadingsCubit cubit) async {
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
              }).catchError((_) {
                if (sheetCtx.mounted) {
                  setSheetState(() {
                    loadErr = 'تعذر تحميل قائمة العدادات.';
                    loading = false;
                  });
                }
              });
            }

            final filtered = meters.where((m) {
              final q = searchQuery.trim().toLowerCase();
              if (q.isEmpty) return true;
              return m.uniqueCode.toLowerCase().contains(q) ||
                  (m.subscriberName ?? '').toLowerCase().contains(q) ||
                  (m.tenantName ?? '').toLowerCase().contains(q) ||
                  (m.pathNumber?.toString() ?? '').contains(q);
            }).toList(growable: false);

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
                          style: Theme.of(sheetCtx)
                              .textTheme
                              .titleMedium
                              ?.copyWith(fontWeight: FontWeight.bold),
                        ),
                        const SizedBox(height: 10),
                        TextField(
                          decoration: const InputDecoration(
                            prefixIcon: Icon(Icons.search),
                            hintText: 'ابحث بكود العداد أو المشترك أو رقم المسار…',
                          ),
                          onChanged: (val) => setSheetState(() => searchQuery = val),
                        ),
                        const SizedBox(height: 10),
                        Expanded(
                          child: loading
                              ? const Center(child: CircularProgressIndicator())
                              : loadErr != null
                                  ? Center(child: Text(loadErr!))
                                  : filtered.isEmpty
                                      ? const Center(child: Text('لا توجد عدادات نشطة مطابقة.'))
                                      : ListView.separated(
                                          itemCount: filtered.length,
                                          separatorBuilder: (_, __) => const Divider(height: 1),
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
        final all = await metersRepository.getMeters(squareId: squareId, status: 'active');
        final next = all
            .where((m) => m.pathNumber != null && m.pathNumber! > current.pathNumber!)
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
        localPath != null && localPath.trim().isNotEmpty && File(localPath).existsSync();
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
                  title: Text('صورة قراءة العداد ${reading.meterCode ?? reading.meterId}'),
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

  Widget _filters(BuildContext context, ReadingsState state, ReadingsCubit cubit) =>
      _ReadingsFilters(state: state, cubit: cubit);

  Future<void> _confirmBulkInvoice(BuildContext context, ReadingsCubit cubit) async {
    final accepted = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('إصدار فواتير جماعي'),
        content: Text('سيتم إصدار فواتير للقراءات المحددة (${cubit.selectedReadings.length}). هل تريد المتابعة؟'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('إصدار الفواتير')),
        ],
      ),
    );
    if (accepted == true) await cubit.issueSelectedInvoices();
  }

  Future<void> _confirmBulkDelete(BuildContext context, ReadingsCubit cubit) async {
    final selected = cubit.selectedReadings;
    final invoicedCount = selected.where((reading) => reading.isInvoiced).length;
    final accepted = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('حذف القراءات المحددة'),
        content: Text(
          'سيُحذف غير المفوتر فقط، وستُتخطى $invoicedCount قراءة مفوترة. هل تريد المتابعة؟',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('إلغاء')),
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
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('إلغاء')),
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
    final controller = TextEditingController(text: _number(reading.currentReading));
    final formKey = GlobalKey<FormState>();
    DateTime editedDate = reading.readingDate;

    final updated = await showDialog<({double currentReading, DateTime readingDate})>(
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
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: InputDecoration(
                      labelText: 'القراءة الحالية',
                      helperText: 'القراءة السابقة: ${_number(reading.previousReading)}',
                    ),
                    validator: (value) {
                      final parsed = double.tryParse(
                        CompoundSearchQuery.normalizeDigits(value ?? '').replaceAll(',', '.'),
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
                        lastDate: DateTime.now().add(const Duration(days: 1)),
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
                        CompoundSearchQuery.normalizeDigits(controller.text)
                            .replaceAll(',', '.'),
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
      const SnackBar(content: Text('يجب أن تكون القراءات المحددة غير مفوترة ومزامنة أولاً.')),
    );
  }
}

class _ReadingsFilters extends StatefulWidget {
  const _ReadingsFilters({required this.state, required this.cubit});

  final ReadingsState state;
  final ReadingsCubit cubit;

  @override
  State<_ReadingsFilters> createState() => _ReadingsFiltersState();
}

class _ReadingsFiltersState extends State<_ReadingsFilters> {
  late final TextEditingController _search =
      TextEditingController(text: widget.state.query);

  @override
  void didUpdateWidget(covariant _ReadingsFilters oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.state.query != _search.text) {
      _search.value = TextEditingValue(
        text: widget.state.query,
        selection: TextSelection.collapsed(offset: widget.state.query.length),
      );
    }
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  String? get _squareName {
    final id = widget.state.squareFilter;
    if (id == null) return null;
    for (final square in widget.state.squares) {
      if (square.id == id) return square.name;
    }
    return 'مربع #$id';
  }

  bool get _hasDates =>
      widget.state.fromDate != null || widget.state.toDate != null;

  int get _sheetFacets => (_squareName == null ? 0 : 1) + (_hasDates ? 1 : 0);

  String get _dateSummary {
    String fmt(DateTime? value) => value == null ? '…' : _date(value);
    return '${fmt(widget.state.fromDate)} ← ${fmt(widget.state.toDate)}';
  }

  Future<void> _openSheet() async {
    await AppFilterSheet.show<void>(
      context: context,
      builder: (_) => _ReadingsFilterSheet(state: widget.state, cubit: widget.cubit),
    );
  }

  @override
  Widget build(BuildContext context) {
    final state = widget.state;
    final cubit = widget.cubit;
    final active = <Widget>[
      if (_squareName != null)
        AppFilterChip(
          label: _squareName!,
          icon: Icons.grid_view_rounded,
          selected: true,
          onRemove: () => cubit.applyFilters(
            squareId: null,
            fromDate: state.fromDate,
            toDate: state.toDate,
          ),
        ),
      if (_hasDates)
        AppFilterChip(
          label: _dateSummary,
          icon: Icons.event_rounded,
          selected: true,
          onRemove: () => cubit.applyFilters(
            squareId: state.squareFilter,
            fromDate: null,
            toDate: null,
          ),
        ),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        const SizedBox(height: 10),
        AppFilterBar(
          controller: _search,
          hint: 'كود العداد، المشترك، أو المربع/المسار',
          activeCount: _sheetFacets,
          onOpenFilters: _openSheet,
          onChanged: cubit.setQuery,
        ),
        const SizedBox(height: 8),
        AppFilterChips(
          children: <Widget>[
            AppFilterChip(
              label: 'الكل',
              selected: state.invoiceFilter == ReadingInvoiceFilter.all,
              onTap: () => cubit.setInvoiceFilter(ReadingInvoiceFilter.all),
            ),
            AppFilterChip(
              label: 'مفوترة',
              selected: state.invoiceFilter == ReadingInvoiceFilter.invoiced,
              onTap: () => cubit.setInvoiceFilter(ReadingInvoiceFilter.invoiced),
            ),
            AppFilterChip(
              label: 'غير مفوترة',
              selected: state.invoiceFilter == ReadingInvoiceFilter.notInvoiced,
              onTap: () => cubit.setInvoiceFilter(ReadingInvoiceFilter.notInvoiced),
            ),
          ],
        ),
        AppActiveFilters(filters: active, onClearAll: cubit.clearAllFilters),
        if (state.isMutating ||
            (state.status == ReadingsLoadStatus.loading && state.readings.isNotEmpty))
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: LinearProgressIndicator(
              minHeight: 1.5,
              backgroundColor: Colors.transparent,
              color: Theme.of(context).colorScheme.primary.withValues(alpha: .5),
            ),
          ),
        Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 10, 16, 2),
          child: Row(
            children: <Widget>[
              Text(
                state.status == ReadingsLoadStatus.loading
                    ? 'جارٍ التحديث…'
                    : '${state.readings.length} قراءة',
                style: Theme.of(context).textTheme.labelSmall,
              ),
              const Spacer(),
              if (state.isSelecting)
                Text(
                  'وضع التحديد',
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                        color: Theme.of(context).colorScheme.primary,
                      ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

class _ReadingsFilterSheet extends StatefulWidget {
  const _ReadingsFilterSheet({required this.state, required this.cubit});

  final ReadingsState state;
  final ReadingsCubit cubit;

  @override
  State<_ReadingsFilterSheet> createState() => _ReadingsFilterSheetState();
}

class _ReadingsFilterSheetState extends State<_ReadingsFilterSheet> {
  late int? _square = widget.state.squareFilter;
  late DateTime? _from = widget.state.fromDate;
  late DateTime? _to = widget.state.toDate;

  Future<void> _pick({required bool from}) async {
    final now = DateTime.now();
    final tomorrow = now.add(const Duration(days: 1));
    final firstDate = from ? DateTime(2000) : (_from ?? DateTime(2000));
    final lastDate = from ? (_to ?? tomorrow) : tomorrow;
    final requested = (from ? _from : _to) ?? now;
    final initial = requested.isBefore(firstDate)
        ? firstDate
        : requested.isAfter(lastDate)
            ? lastDate
            : requested;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: firstDate,
      lastDate: lastDate,
      helpText: from ? 'من تاريخ' : 'إلى تاريخ',
    );
    if (picked == null) return;
    setState(() {
      if (from) {
        _from = picked;
      } else {
        _to = picked;
      }
    });
  }

  void _apply() {
    widget.cubit.applyFilters(squareId: _square, fromDate: _from, toDate: _to);
    Navigator.of(context).maybePop();
  }

  void _reset() {
    widget.cubit.setInvoiceFilter(ReadingInvoiceFilter.all);
    setState(() {
      _square = null;
      _from = null;
      _to = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final state = widget.state;
    return AppFilterSheet(
      title: 'تصفية سجل القراءات',
      resultCountLabel: state.status == ReadingsLoadStatus.loading
          ? 'جارٍ تحديث النتائج…'
          : '${state.readings.length} قراءة مطابقة',
      onApply: _apply,
      onReset: _reset,
      resetEnabled: _square != null ||
          _from != null ||
          _to != null ||
          state.invoiceFilter != ReadingInvoiceFilter.all,
      sections: <Widget>[
        AppFilterSection(
          title: 'حالة الفوترة',
          child: AppSegmentedFilter<ReadingInvoiceFilter>(
            value: state.invoiceFilter,
            onChanged: widget.cubit.setInvoiceFilter,
            options: const <({ReadingInvoiceFilter value, String label, IconData? icon})>[
              (value: ReadingInvoiceFilter.all, label: 'الكل', icon: null),
              (value: ReadingInvoiceFilter.invoiced, label: 'مفوترة', icon: null),
              (value: ReadingInvoiceFilter.notInvoiced, label: 'غير مفوترة', icon: null),
            ],
          ),
        ),
        AppFilterSection(
          title: 'المربع السكني',
          subtitle: 'اختر مربعاً واحداً أو أبقِ البحث في كل المربعات',
          child: Container(
            constraints: const BoxConstraints(maxHeight: 216),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: theme.colorScheme.outlineVariant),
            ),
            child: ListView(
              padding: const EdgeInsets.all(6),
              shrinkWrap: true,
              children: <Widget>[
                AppFilterOptionTile(
                  label: 'كل المربعات',
                  icon: Icons.apps_rounded,
                  selected: _square == null,
                  onTap: () => setState(() => _square = null),
                ),
                const SizedBox(height: 6),
                for (final square in state.squares) ...<Widget>[
                  AppFilterOptionTile(
                    label: square.name,
                    icon: Icons.grid_view_rounded,
                    selected: _square == square.id,
                    onTap: () => setState(() => _square = square.id),
                  ),
                  const SizedBox(height: 6),
                ],
              ],
            ),
          ),
        ),
        AppFilterSection(
          title: 'الفترة',
          subtitle: 'حدّد نطاق القراءات بالتاريخ',
          child: AppFilterDateRange(
            fromLabel: _from == null ? 'بدون حد' : _date(_from!),
            toLabel: _to == null ? 'بدون حد' : _date(_to!),
            onPickFrom: () => _pick(from: true),
            onPickTo: () => _pick(from: false),
            onClear: _from == null && _to == null
                ? null
                : () => setState(() {
                      _from = null;
                      _to = null;
                    }),
          ),
        ),
        const SizedBox(height: 20),
      ],
    );
  }
}

class _ReadingCard extends StatelessWidget {
  const _ReadingCard({
    required this.reading,
    required this.selected,
    required this.selectionMode,
    required this.onTap,
    required this.onLongPress,
    this.onPreviewPhoto,
    this.onEdit,
    this.onDelete,
  });

  final Reading reading;
  final bool selected;
  final bool selectionMode;
  final VoidCallback onTap;
  final VoidCallback onLongPress;
  final VoidCallback? onPreviewPhoto;
  final VoidCallback? onEdit;
  final VoidCallback? onDelete;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final actualDate = reading.actualReadingDate;
    final hasActions =
        !selectionMode && (onPreviewPhoto != null || onEdit != null || onDelete != null);

    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
      child: AppCard(
        padding: EdgeInsets.zero,
        borderColor: selected ? scheme.primary.withValues(alpha: .45) : null,
        color: selected ? scheme.primary.withValues(alpha: .04) : scheme.surface,
        onTap: selectionMode ? onTap : null,
        child: InkWell(
          onLongPress: onLongPress,
          borderRadius: BorderRadius.circular(AppRadius.xl),
          child: Padding(
            padding: const EdgeInsets.all(13),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    if (selectionMode) ...<Widget>[
                      SizedBox(
                        width: 26,
                        height: 26,
                        child: Checkbox(
                          value: selected,
                          onChanged: (_) => onTap(),
                        ),
                      ),
                      const SizedBox(width: 6),
                    ],
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          LtrNumberText(
                            reading.meterCode ?? 'عداد ${reading.meterId}',
                            style: theme.textTheme.titleSmall?.copyWith(
                              fontSize: 14,
                              fontWeight: FontWeight.w600,
                              letterSpacing: .1,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            '${reading.subscriberName ?? 'مشترك'}'
                            '${reading.tenantName == null ? '' : ' · ${reading.tenantName}'}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: <Widget>[
                        StatusBadge(
                          status: reading.isInvoiced ? 'success' : 'warning',
                          label: reading.isInvoiced ? 'مفوترة' : 'غير مفوترة',
                        ),
                        if (!reading.isSynced)
                          Padding(
                            padding: const EdgeInsets.only(top: 4),
                            child: StatusBadge(
                              status: 'pending',
                              label: 'بانتظار المزامنة',
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
                const SizedBox(height: 11),
                Row(
                  children: <Widget>[
                    Expanded(
                      child: _ReadingStat(
                        label: 'السابقة',
                        value: _number(reading.previousReading),
                        muted: true,
                      ),
                    ),
                    _Arrow(context: context),
                    Expanded(
                      child: _ReadingStat(
                        label: 'الحالية',
                        value: _number(reading.currentReading),
                      ),
                    ),
                    Container(
                      width: 1,
                      height: 30,
                      margin: const EdgeInsets.symmetric(horizontal: 10),
                      color: scheme.outlineVariant,
                    ),
                    Expanded(
                      child: _ReadingStat(
                        label: 'الاستهلاك',
                        value: _number(reading.consumptionUnits),
                        unit: 'وحدة',
                        accent: true,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: <Widget>[
                    _ReadingTag(
                      icon: Icons.event_rounded,
                      label: _date(actualDate ?? reading.readingDate),
                    ),
                    _ReadingTag(
                      icon: Icons.date_range_rounded,
                      label: '${_date(reading.periodStart)} — ${_date(reading.periodEnd)}',
                      ltr: true,
                    ),
                    if (reading.hasPhoto && onPreviewPhoto != null)
                      _ReadingTag(
                        icon: Icons.photo_camera_rounded,
                        label: 'صورة',
                        onTap: onPreviewPhoto,
                        tinted: true,
                      ),
                  ],
                ),
                if (reading.syncError?.isNotEmpty == true) ...<Widget>[
                  const SizedBox(height: 8),
                  Text(
                    reading.syncError!,
                    style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
                  ),
                ],
                if (hasActions) ...<Widget>[
                  const SizedBox(height: 8),
                  Container(height: 1, color: scheme.outlineVariant),
                  Row(
                    children: <Widget>[
                      if (onPreviewPhoto != null)
                        _CardAction(
                          icon: Icons.photo_outlined,
                          label: 'الصورة',
                          onPressed: onPreviewPhoto,
                        ),
                      if (onEdit != null)
                        _CardAction(
                          icon: Icons.edit_outlined,
                          label: 'تعديل',
                          onPressed: onEdit,
                        ),
                      const Spacer(),
                      if (onDelete != null)
                        _CardAction(
                          icon: Icons.delete_outline_rounded,
                          label: 'حذف',
                          onPressed: onDelete,
                          danger: true,
                        ),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Arrow extends StatelessWidget {
  const _Arrow({required this.context});

  final BuildContext context;

  @override
  Widget build(BuildContext _) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4),
        child: Icon(
          Icons.arrow_back_rounded,
          size: 13,
          color: Theme.of(context).colorScheme.outline,
        ),
      );
}

class _ReadingStat extends StatelessWidget {
  const _ReadingStat({
    required this.label,
    required this.value,
    this.unit,
    this.muted = false,
    this.accent = false,
  });

  final String label;
  final String value;
  final String? unit;
  final bool muted;
  final bool accent;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, style: theme.textTheme.labelSmall?.copyWith(fontSize: 10)),
        const SizedBox(height: 2),
        Row(
          textDirection: TextDirection.rtl,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: <Widget>[
            Flexible(
              child: LtrNumberText(
                value,
                style: TextStyle(
                  fontSize: accent ? 17 : 15,
                  height: 1.2,
                  fontWeight: FontWeight.w600,
                  letterSpacing: -.2,
                  color: accent
                      ? scheme.primary
                      : (muted ? scheme.onSurfaceVariant : scheme.onSurface),
                  fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
                ),
              ),
            ),
            if (unit != null)
              Padding(
                padding: const EdgeInsets.only(right: 0, bottom: 1),
                child: Text(' $unit', style: theme.textTheme.labelSmall?.copyWith(fontSize: 9.5)),
              ),
          ],
        ),
      ],
    );
  }
}

class _ReadingTag extends StatelessWidget {
  const _ReadingTag({
    required this.icon,
    required this.label,
    this.onTap,
    this.ltr = false,
    this.tinted = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final bool ltr;
  final bool tinted;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final color = tinted ? scheme.primary : scheme.onSurfaceVariant;
    final content = Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Icon(icon, size: 12, color: color),
        const SizedBox(width: 4),
        if (ltr)
          Directionality(
            textDirection: TextDirection.ltr,
            child: Text(
              label,
              style: TextStyle(fontSize: 10.5, height: 1.2, fontWeight: FontWeight.w500, color: color),
            ),
          )
        else
          Text(
            label,
            style: TextStyle(fontSize: 10.5, height: 1.2, fontWeight: FontWeight.w500, color: color),
          ),
      ],
    );
    final chip = Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(
          color: tinted ? scheme.primary.withValues(alpha: .28) : scheme.outlineVariant,
        ),
      ),
      child: content,
    );
    if (onTap == null) return chip;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.pill),
      child: chip,
    );
  }
}

class _CardAction extends StatelessWidget {
  const _CardAction({
    required this.icon,
    required this.label,
    required this.onPressed,
    this.danger = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onPressed;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return TextButton.icon(
      onPressed: onPressed,
      style: TextButton.styleFrom(
        minimumSize: const Size(0, 32),
        padding: const EdgeInsets.symmetric(horizontal: 8),
        foregroundColor: danger ? scheme.error : scheme.onSurfaceVariant,
        textStyle: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w500),
      ),
      icon: Icon(icon, size: 16),
      label: Text(label),
    );
  }
}


String _date(DateTime value) =>
    '${value.year.toString().padLeft(4, '0')}-'
    '${value.month.toString().padLeft(2, '0')}-'
    '${value.day.toString().padLeft(2, '0')}';

String _number(num value) => value.toStringAsFixed(value % 1 == 0 ? 0 : 2);
