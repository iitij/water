import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/ui/app_tokens.dart';
import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../../admin_shared/presentation/widgets/admin_permission_guard.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../data/services/excel_export_service.dart';
import '../../domain/entities/export_entities.dart';
import '../cubit/export_cubit.dart';

class ExportCenterScreen extends StatelessWidget {
  const ExportCenterScreen({
    required this.service,
    required this.can,
    super.key,
  });

  final ExcelExportService service;
  final AdminPermissionCheck can;

  @override
  Widget build(BuildContext context) {
    if (!can(AdminPermissions.viewExports)) {
      return const Scaffold(
        body: AdminErrorView(message: 'لا تملك صلاحية View:ExportDataPage للوصول إلى التصدير.'),
      );
    }
    return BlocProvider(
      create: (_) => ExportCubit(service)..loadCatalog(),
      child: const _ExportCenterView(),
    );
  }
}

/// Backward-compatible name used by the detailed screen blueprint.
class ExportDataCenterScreen extends StatelessWidget {
  const ExportDataCenterScreen({
    required this.service,
    required this.can,
    super.key,
  });

  final ExcelExportService service;
  final AdminPermissionCheck can;

  @override
  Widget build(BuildContext context) => ExportCenterScreen(service: service, can: can);
}

class _ExportCenterView extends StatelessWidget {
  const _ExportCenterView();

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<ExportCubit, ExportState>(
      listenWhen: (previous, current) =>
          previous.errorMessage != current.errorMessage ||
          previous.successMessage != current.successMessage,
      listener: (context, state) {
        if (state.errorMessage != null) {
          showAdminSnackBar(context, adminErrorMessage(state.errorMessage!), isError: true);
        } else if (state.successMessage != null) {
          showAdminSnackBar(context, state.successMessage!);
        }
      },
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(
            title: const Text('مركز تصدير Excel'),
            actions: [
              IconButton(
                tooltip: 'تحديث الإحصائيات',
                onPressed: () => context.read<ExportCubit>().loadCatalog(),
                icon: const Icon(Icons.refresh_rounded),
              ),
            ],
          ),
          body: RefreshIndicator(
            onRefresh: () => context.read<ExportCubit>().loadCatalog(),
            child: SingleChildScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 1500),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const AdminSectionHeader(
                        title: 'تصدير بيانات المشروع',
                        subtitle: 'تُنشأ الملفات على الخادم باستخدام DataExportService ثم تُحفظ وتُشارك من الجهاز.',
                        icon: Icons.file_download_outlined,
                      ),
                      const SizedBox(height: 16),
                      _ExportStats(state: state),
                      if (state.catalog == null && state.errorMessage != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 12),
                          child: TextButton.icon(
                            onPressed: () => context.read<ExportCubit>().loadCatalog(),
                            icon: const Icon(Icons.cloud_off_outlined),
                            label: const Text('تعذر تحميل الإحصائيات — إعادة المحاولة'),
                          ),
                        ),
                      const SizedBox(height: 20),
                      LayoutBuilder(
                        builder: (context, constraints) {
                          final cards = [
                            for (final kind in ExportKind.values)
                              _ExportCard(kind: kind, state: state),
                          ];
                          if (constraints.maxWidth < 1100) {
                            return Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                for (final card in cards) ...[card, const SizedBox(height: 16)],
                              ],
                            );
                          }
                          return Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              for (final card in cards) ...[
                                Expanded(child: card),
                                if (card != cards.last) const SizedBox(width: 16),
                              ],
                            ],
                          );
                        },
                      ),
                      const SizedBox(height: 32),
                    ],
                  ),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _ExportStats extends StatelessWidget {
  const _ExportStats({required this.state});

  final ExportState state;

  @override
  Widget build(BuildContext context) {
    if (state.isCatalogLoading && state.catalog == null) {
      return const AdminLoadingView(itemCount: 4);
    }
    final summary = state.catalog?.summary;
    final cards = [
      ('إجمالي المشتركين', summary?.totalSubscribers, Icons.groups_outlined, AdminPalette.subscribersExport),
      ('إجمالي الفواتير', summary?.totalInvoices, Icons.receipt_long_outlined, AdminPalette.invoicesExport),
      ('إجمالي القراءات', summary?.totalReadings, Icons.speed_rounded, AdminPalette.readingsExport),
      ('المربعات السكنية', summary?.totalSquares, Icons.grid_view_rounded, AdminPalette.primary),
    ];
    return AdminResponsiveGrid(
      minItemWidth: 190,
      childAspectRatio: 1.85,
      children: [
        for (final card in cards)
          AdminCard(
            child: Row(
              children: [
                Icon(card.$3, color: card.$4, size: 30),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(card.$1, maxLines: 2),
                      const SizedBox(height: 6),
                      FittedBox(
                        fit: BoxFit.scaleDown,
                        alignment: AlignmentDirectional.centerStart,
                        child: Text(
                          card.$2 == null ? '—' : AdminFormat.number(card.$2!),
                          style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                                fontWeight: FontWeight.w600,
                              ),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}

class _ExportCard extends StatelessWidget {
  const _ExportCard({required this.kind, required this.state});

  final ExportKind kind;
  final ExportState state;

  Color get _color => switch (kind) {
        ExportKind.subscribers => AdminPalette.subscribersExport,
        ExportKind.invoices => AdminPalette.invoicesExport,
        ExportKind.readings => AdminPalette.readingsExport,
      };

  String get _title => switch (kind) {
        ExportKind.subscribers => 'تصدير المشتركين',
        ExportKind.invoices => 'تصدير الفواتير',
        ExportKind.readings => 'تصدير قراءات العدادات',
      };

  IconData get _icon => switch (kind) {
        ExportKind.subscribers => Icons.groups_outlined,
        ExportKind.invoices => Icons.receipt_long_outlined,
        ExportKind.readings => Icons.speed_rounded,
      };

  @override
  Widget build(BuildContext context) {
    final filters = state.filters[kind] ?? const ExportFilters();
    final file = state.files[kind];
    final isBusy = state.busyKinds.contains(kind);
    final isSharing = state.sharingKinds.contains(kind);
    final cubit = context.read<ExportCubit>();
    return AdminCard(
      padding: EdgeInsets.zero,
      borderColor: _color.withAlpha(80),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            padding: const EdgeInsets.all(18),
            color: _color,
            child: Row(
              children: [
                Icon(_icon, color: Colors.white, size: 30),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    _title,
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          color: Colors.white,
                          fontWeight: FontWeight.w600,
                        ),
                  ),
                ),
                if (state.catalog != null)
                  AdminStatusBadge(
                    label: '${AdminFormat.number(state.catalog!.summary.forKind(kind))} سجل',
                    color: Colors.white,
                  ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                DropdownButtonFormField<int>(
                  key: ValueKey('square-$kind-${filters.squareId}'),
                  initialValue: filters.squareId,
                  decoration: const InputDecoration(
                    labelText: 'المربع السكني',
                  ),
                  items: [
                    const DropdownMenuItem(child: Text('كل المربعات')),
                    for (final square in state.catalog?.squares ?? const <ExportSquare>[])
                      DropdownMenuItem(value: square.id, child: Text(square.name)),
                  ],
                  onChanged: (value) => cubit.updateFilters(
                    kind,
                    filters.copyWith(squareId: value, clearSquare: value == null),
                  ),
                ),
                const SizedBox(height: 12),
                if (kind != ExportKind.readings)
                  DropdownButtonFormField<String>(
                    key: ValueKey('status-$kind-${filters.status}'),
                    initialValue: filters.status,
                    decoration: InputDecoration(
                      labelText: kind == ExportKind.subscribers ? 'حالة المشترك' : 'حالة الفاتورة',
                    ),
                    items: [
                      const DropdownMenuItem(child: Text('كل الحالات')),
                      for (final status in kind == ExportKind.subscribers
                          ? const {'active': 'نشط', 'inactive': 'غير نشط', 'suspended': 'موقوف'}.entries
                          : const {
                              'draft': 'مسودة',
                              'issued': 'صادرة',
                              'partially_paid': 'مسددة جزئياً',
                              'paid': 'مسددة',
                              'overdue': 'متأخرة',
                            }.entries)
                        DropdownMenuItem(value: status.key, child: Text(status.value)),
                    ],
                    onChanged: (value) => cubit.updateFilters(
                      kind,
                      filters.copyWith(status: value, clearStatus: value == null),
                    ),
                  )
                else
                  DropdownButtonFormField<bool>(
                    key: ValueKey('invoiced-${filters.isInvoiced}'),
                    initialValue: filters.isInvoiced,
                    decoration: const InputDecoration(
                      labelText: 'حالة الفوترة',
                    ),
                    items: const [
                      DropdownMenuItem(child: Text('الكل')),
                      DropdownMenuItem(value: true, child: Text('مفوترة')),
                      DropdownMenuItem(value: false, child: Text('غير مفوترة')),
                    ],
                    onChanged: (value) => cubit.updateFilters(
                      kind,
                      filters.copyWith(isInvoiced: value, clearInvoiced: value == null),
                    ),
                  ),
                if (kind != ExportKind.subscribers) ...[
                  const SizedBox(height: 12),
                  _DateFilters(kind: kind, filters: filters),
                ],
                const SizedBox(height: 16),
                FilledButton.icon(
                  key: ValueKey('export-$kind'),
                  onPressed: isBusy ? null : () => cubit.export(kind),
                  style: FilledButton.styleFrom(
                    backgroundColor: _color,
                    minimumSize: const Size.fromHeight(48),
                  ),
                  icon: isBusy
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                        )
                      : const Icon(Icons.download_rounded),
                  label: Text(isBusy ? 'جارٍ إنشاء الملف...' : 'تصدير وتحميل Excel (.xlsx)'),
                ),
                if (file != null) ...[
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: _color.withAlpha(12),
                      borderRadius: BorderRadius.circular(AppRadius.md),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        LtrText(file.fileName, style: const TextStyle(fontWeight: FontWeight.w600)),
                        Text('${_fileSize(file.sizeInBytes)} • محفوظ على الجهاز'),
                        const SizedBox(height: 8),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            OutlinedButton.icon(
                              key: ValueKey('open-$kind'),
                              onPressed: () => cubit.open(kind),
                              icon: const Icon(Icons.open_in_new_rounded),
                              label: const Text('فتح'),
                            ),
                            FilledButton.tonalIcon(
                              key: ValueKey('share-$kind'),
                              onPressed: isSharing ? null : () => cubit.share(kind),
                              icon: isSharing
                                  ? const SizedBox(
                                      width: 16,
                                      height: 16,
                                      child: CircularProgressIndicator(strokeWidth: 2),
                                    )
                                  : const Icon(Icons.share_outlined),
                              label: const Text('مشاركة'),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _DateFilters extends StatelessWidget {
  const _DateFilters({required this.kind, required this.filters});

  final ExportKind kind;
  final ExportFilters filters;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final width = constraints.maxWidth >= 430
            ? (constraints.maxWidth - 10) / 2
            : constraints.maxWidth;
        return Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            SizedBox(
              width: width,
              child: _DateField(
                label: 'من تاريخ',
                value: filters.fromDate,
                onTap: () => _pickDate(context, isFrom: true),
              ),
            ),
            SizedBox(
              width: width,
              child: _DateField(
                label: 'إلى تاريخ',
                value: filters.toDate,
                onTap: () => _pickDate(context, isFrom: false),
              ),
            ),
          ],
        );
      },
    );
  }

  Future<void> _pickDate(BuildContext context, {required bool isFrom}) async {
    final cubit = context.read<ExportCubit>();
    final current = isFrom ? filters.fromDate : filters.toDate;
    final now = DateTime.now();
    final selected = await showDatePicker(
      context: context,
      initialDate: current ?? now,
      firstDate: DateTime(2020),
      lastDate: DateTime(now.year + 1),
    );
    if (selected != null) {
      cubit.updateFilters(
        kind,
        isFrom ? filters.copyWith(fromDate: selected) : filters.copyWith(toDate: selected),
      );
    }
  }
}

class _DateField extends StatelessWidget {
  const _DateField({required this.label, required this.value, required this.onTap});

  final String label;
  final DateTime? value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: InputDecorator(
        decoration: InputDecoration(labelText: label),
        child: Row(
          children: [
            Expanded(child: LtrText(AdminFormat.date(value))),
            const Icon(Icons.calendar_month_outlined, size: 20),
          ],
        ),
      ),
    );
  }
}

String _fileSize(int bytes) {
  if (bytes >= 1024 * 1024) return '${(bytes / 1024 / 1024).toStringAsFixed(2)} MB';
  if (bytes >= 1024) return '${(bytes / 1024).toStringAsFixed(1)} KB';
  return '$bytes B';
}
