import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/database/offline_pack_downloader.dart';
import '../../../../core/utils/compound_search_parser.dart';
import '../../../../core/widgets/search_text_field.dart';
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
                      child: _EmptyReadings(
                        title: 'تعذر تحميل القراءات',
                        message: state.errorMessage ?? 'تحقق من الاتصال ثم أعد المحاولة.',
                        action: TextButton.icon(
                          onPressed: cubit.load,
                          icon: const Icon(Icons.refresh),
                          label: const Text('إعادة المحاولة'),
                        ),
                      ),
                    )
                  else if (state.readings.isEmpty)
                    const SliverFillRemaining(
                      hasScrollBody: false,
                      child: _EmptyReadings(
                        title: 'لا توجد قراءات',
                        message: 'لم نجد قراءات مطابقة للفلاتر الحالية.',
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
                            border: OutlineInputBorder(),
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

  Widget _filters(BuildContext context, ReadingsState state, ReadingsCubit cubit) {
    final fromDate = state.fromDate;
    final toDate = state.toDate;
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SearchTextField(
            query: state.query,
            hintText: 'ابحث بكود العداد أو المشترك أو المسار',
            onChanged: cubit.setQuery,
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 6,
            children: [
              _invoiceFilterChip(
                context,
                'الكل',
                ReadingInvoiceFilter.all,
                state.invoiceFilter,
                cubit,
              ),
              _invoiceFilterChip(
                context,
                'مفوترة',
                ReadingInvoiceFilter.invoiced,
                state.invoiceFilter,
                cubit,
              ),
              _invoiceFilterChip(
                context,
                'غير مفوترة',
                ReadingInvoiceFilter.notInvoiced,
                state.invoiceFilter,
                cubit,
              ),
            ],
          ),
          const SizedBox(height: 10),
          LayoutBuilder(
            builder: (context, constraints) {
              final squareDropdown = DropdownButtonFormField<int?>(
                value: state.squareFilter,
                isExpanded: true,
                decoration: const InputDecoration(
                  labelText: 'المربع السكني',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
                items: [
                  const DropdownMenuItem<int?>(value: null, child: Text('كل المربعات')),
                  ...state.squares.map((square) => DropdownMenuItem<int?>(
                        value: square.id,
                        child: Text(square.name, overflow: TextOverflow.ellipsis),
                      )),
                ],
                onChanged: cubit.setSquareFilter,
              );
              final dates = Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () => _pickDate(context, cubit, from: true),
                      icon: const Icon(Icons.calendar_today_outlined, size: 18),
                      label: Text(fromDate == null ? 'من تاريخ' : _date(fromDate)),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () => _pickDate(context, cubit, from: false),
                      icon: const Icon(Icons.event_outlined, size: 18),
                      label: Text(toDate == null ? 'إلى تاريخ' : _date(toDate)),
                    ),
                  ),
                  if (fromDate != null || toDate != null)
                    IconButton(
                      tooltip: 'مسح التاريخ',
                      onPressed: cubit.clearDateFilters,
                      icon: const Icon(Icons.clear),
                    ),
                ],
              );
              if (constraints.maxWidth < 680) {
                return Column(
                  children: [squareDropdown, const SizedBox(height: 8), dates],
                );
              }
              return Row(
                children: [
                  Expanded(flex: 2, child: squareDropdown),
                  const SizedBox(width: 10),
                  Expanded(flex: 3, child: dates),
                ],
              );
            },
          ),
          if (state.status == ReadingsLoadStatus.loading && state.readings.isNotEmpty)
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: LinearProgressIndicator(minHeight: 2),
            ),
          if (state.isMutating)
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: LinearProgressIndicator(minHeight: 2),
            ),
        ],
      ),
    );
  }

  Widget _invoiceFilterChip(
    BuildContext context,
    String label,
    ReadingInvoiceFilter value,
    ReadingInvoiceFilter selected,
    ReadingsCubit cubit,
  ) =>
      ChoiceChip(
        label: Text(label),
        selected: selected == value,
        onSelected: (_) => cubit.setInvoiceFilter(value),
      );

  Future<void> _pickDate(
    BuildContext context,
    ReadingsCubit cubit, {
    required bool from,
  }) async {
    final state = cubit.state;
    final now = DateTime.now();
    final tomorrow = now.add(const Duration(days: 1));
    final firstDate = from ? DateTime(2000) : state.fromDate ?? DateTime(2000);
    final lastDate = from ? state.toDate ?? tomorrow : tomorrow;
    final requestedInitial = (from ? state.fromDate : state.toDate) ?? now;
    final initialDate = requestedInitial.isBefore(firstDate)
        ? firstDate
        : requestedInitial.isAfter(lastDate)
            ? lastDate
            : requestedInitial;
    final selected = await showDatePicker(
      context: context,
      initialDate: initialDate,
      firstDate: firstDate,
      lastDate: lastDate,
    );
    if (selected == null) return;
    if (from) {
      cubit.setFromDate(selected);
    } else {
      cubit.setToDate(selected);
    }
  }

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
                      border: const OutlineInputBorder(),
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
                    borderRadius: BorderRadius.circular(8),
                    child: InputDecorator(
                      decoration: const InputDecoration(
                        labelText: 'تاريخ القراءة',
                        prefixIcon: Icon(Icons.calendar_month_outlined),
                        border: OutlineInputBorder(),
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
    final color = reading.isInvoiced ? Colors.green : const Color(0xFFF59E0B);
    final actualDate = reading.actualReadingDate;
    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: selectionMode ? onTap : null,
        onLongPress: onLongPress,
        child: IntrinsicHeight(
          child: Row(
            children: [
              Container(width: 5, color: color),
              if (selectionMode)
                Checkbox(value: selected, onChanged: (_) => onTap()),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.all(13),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Wrap(
                        alignment: WrapAlignment.spaceBetween,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        spacing: 8,
                        runSpacing: 6,
                        children: [
                          Wrap(
                            spacing: 8,
                            children: [
                              _Tag(text: reading.isInvoiced ? 'مفوترة' : 'غير مفوترة', color: color),
                              if (!reading.isSynced)
                                const _Tag(text: 'بانتظار المزامنة', color: Color(0xFFB77900)),
                              if (reading.hasPhoto)
                                InkWell(
                                  onTap: onPreviewPhoto,
                                  borderRadius: BorderRadius.circular(20),
                                  child: const _Tag(
                                    text: 'صورة مرفقة',
                                    color: Color(0xFF0284C7),
                                  ),
                                ),
                            ],
                          ),
                          Directionality(
                            textDirection: TextDirection.ltr,
                            child: Text(
                              reading.meterCode ?? 'عداد ${reading.meterId}',
                              style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      Text(
                        '${reading.subscriberName ?? 'مشترك'}${reading.tenantName == null ? '' : ' — ${reading.tenantName}'}',
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
                      ),
                      const SizedBox(height: 7),
                      Wrap(
                        spacing: 16,
                        runSpacing: 5,
                        children: [
                          _ReadingValue(label: 'تاريخ القراءة', value: _date(reading.readingDate)),
                          if (actualDate != null)
                            _ReadingValue(label: 'التاريخ الفعلي', value: _date(actualDate)),
                          _ReadingValue(
                            label: 'الفترة',
                            value: '${_date(reading.periodStart)} — ${_date(reading.periodEnd)}',
                            ltr: true,
                          ),
                          _ReadingValue(label: 'السابقة', value: _number(reading.previousReading), ltr: true),
                          _ReadingValue(label: 'الحالية', value: _number(reading.currentReading), ltr: true),
                          _ReadingValue(label: 'الاستهلاك', value: '${_number(reading.consumptionUnits)} وحدة', ltr: true),
                        ],
                      ),
                      if (reading.syncError?.isNotEmpty == true) ...[
                        const SizedBox(height: 6),
                        Text(reading.syncError!, style: const TextStyle(color: Colors.red, fontSize: 12)),
                      ],
                      if (!selectionMode &&
                          (onPreviewPhoto != null || onEdit != null || onDelete != null)) ...[
                        const SizedBox(height: 6),
                        const Divider(height: 1),
                        Wrap(
                          alignment: WrapAlignment.end,
                          spacing: 4,
                          children: [
                            if (onPreviewPhoto != null)
                              TextButton.icon(
                                onPressed: onPreviewPhoto,
                                icon: const Icon(Icons.photo_outlined, size: 18),
                                label: const Text('صورة العداد'),
                              ),
                            if (onEdit != null)
                              TextButton.icon(
                                onPressed: onEdit,
                                icon: const Icon(Icons.edit_outlined, size: 18),
                                label: const Text('تعديل'),
                              ),
                            if (onDelete != null)
                              TextButton.icon(
                                onPressed: onDelete,
                                style: TextButton.styleFrom(foregroundColor: Colors.red),
                                icon: const Icon(Icons.delete_outline, size: 18),
                                label: const Text('حذف'),
                              ),
                          ],
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  static String _date(DateTime value) =>
      '${value.year.toString().padLeft(4, '0')}-'
      '${value.month.toString().padLeft(2, '0')}-'
      '${value.day.toString().padLeft(2, '0')}';

  static String _number(num value) => value.toStringAsFixed(value % 1 == 0 ? 0 : 2);
}

class _ReadingValue extends StatelessWidget {
  const _ReadingValue({required this.label, required this.value, this.ltr = false});

  final String label;
  final String value;
  final bool ltr;

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('$label: ', style: Theme.of(context).textTheme.bodySmall),
          if (ltr)
            Directionality(
              textDirection: TextDirection.ltr,
              child: Text(value, style: Theme.of(context).textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w700)),
            )
          else
            Text(value, style: Theme.of(context).textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w700)),
        ],
      );
}

class _Tag extends StatelessWidget {
  const _Tag({required this.text, required this.color});

  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
        decoration: BoxDecoration(color: color.withAlpha(35), borderRadius: BorderRadius.circular(20)),
        child: Text(text, style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 12)),
      );
}

class _EmptyReadings extends StatelessWidget {
  const _EmptyReadings({required this.title, required this.message, this.action});

  final String title;
  final String message;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.receipt_long_outlined, size: 48),
              const SizedBox(height: 12),
              Text(title, style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 6),
              Text(message, textAlign: TextAlign.center),
              if (action != null) ...[const SizedBox(height: 12), action!],
            ],
          ),
        ),
      );
}

String _date(DateTime value) =>
    '${value.year.toString().padLeft(4, '0')}-'
    '${value.month.toString().padLeft(2, '0')}-'
    '${value.day.toString().padLeft(2, '0')}';

String _number(num value) => value.toStringAsFixed(value % 1 == 0 ? 0 : 2);
