part of '../pages/subscriber_360_screen.dart';

/// تبويبات ملف المشترك 360° — جزء من مكتبة الشاشة.

/// Headers scroll with their list even when a large profile summary leaves
/// only a short viewport. Keeping fixed controls outside the list overflows
/// before the outer NestedScrollView can collapse its header.
Map<String, dynamic> _profileRecord(
  BuildContext context,
  Map<String, dynamic> raw,
) {
  final detail = context.read<Subscriber360Cubit>().state.detail!;
  final subscriber = detail.subscriber;
  final meterId = JsonUtils.asIntOrNull(raw['meter_id']);
  final meters = detail.summary.meterBalances.where(
    (meter) => meter.meterId == meterId,
  );
  return {
    'name': subscriber.name,
    'square_id': subscriber.squareId,
    'square_name': subscriber.squareName,
    'path_numbers': subscriber.pathNumbers,
    if (meterId != null && meters.isNotEmpty)
      'path_number': meters.first.pathNumber,
    ...raw,
  };
}

Map<
  String,
  ({String label, Comparable<dynamic>? Function(Map<String, dynamic>) value})
>
get _profileSortValues => {
  'id': (
    label: ListOptionsStrings.id,
    value: (row) => JsonUtils.asIntOrNull(row['id']),
  ),
  'reference': (
    label: ListOptionsStrings.reference,
    value: (row) => [
      row['name'],
      row['bill_number'],
      row['receipt_number'],
      row['description'],
      row['notes'],
      row['unique_code'],
    ].whereType<Object>().join(' '),
  ),
  'created_at': (
    label: ListOptionsStrings.createdAt,
    value: (row) => JsonUtils.asDate(row['created_at']),
  ),
  'amount': (
    label: ListOptionsStrings.amount,
    value: (row) =>
        JsonUtils.asDoubleOrNull(row['amount'] ?? row['total_amount']),
  ),
  'status': (
    label: ListOptionsStrings.status,
    value: (row) => row['status']?.toString(),
  ),
  'square_id': (
    label: ListOptionsStrings.square,
    value: (row) => RecordLocation.fromJson(row).squareId,
  ),
  'square_name': (
    label: ListOptionsStrings.square,
    value: (row) => RecordLocation.fromJson(row).squareLabel,
  ),
  'path_number': (
    label: ListOptionsStrings.path,
    value: (row) => RecordLocation.fromJson(row).firstPath,
  ),
};

class _SubscriberTabScrollView extends StatelessWidget {
  const _SubscriberTabScrollView({
    required this.header,
    required this.empty,
    required this.records,
    required this.itemBuilder,
  });
  final List<Widget> header;
  final Widget empty;
  final List<Map<String, dynamic>> records;
  final IndexedWidgetBuilder itemBuilder;

  @override
  Widget build(BuildContext context) {
    final rows = [
      for (var index = 0; index < records.length; index++)
        {..._profileRecord(context, records[index]), '_index': index},
    ];
    return ListPresentation<Map<String, dynamic>>.slivers(
      items: rows,
      initialSettings: const ListPresentationSettings(sortBy: 'id'),
      sortValues: _profileSortValues,
      pathNumbers: (row) => RecordLocation.fromJson(row).paths,
      sliversBuilder: (context, visible, options) => [
        for (final child in header) SliverToBoxAdapter(child: child),
        SliverPadding(
          padding: const EdgeInsets.only(top: 10, bottom: 24),
          sliver: visible.isEmpty
              ? SliverToBoxAdapter(child: empty)
              : SliverList.builder(
                  itemCount: visible.length,
                  itemBuilder: (context, index) =>
                      itemBuilder(context, visible[index]['_index'] as int),
                ),
        ),
      ],
    );
  }
}

class _MetersTab extends StatelessWidget {
  const _MetersTab({
    required this.detail,
    this.onRecordReading,
    this.onAddMeter,
  });
  final Subscriber360View detail;
  final RecordReadingLauncher? onRecordReading;
  final AddMeterLauncher? onAddMeter;

  Meter _toMeterModel(MeterBalance m) {
    final raw = detail.metersRaw.cast<Map<String, dynamic>?>().firstWhere(
      (item) => (item?['id'] as num?)?.toInt() == m.meterId,
      orElse: () => null,
    );
    if (raw != null) {
      return Meter.fromJson({
        ...raw,
        'subscriber_id': detail.subscriber.id,
        'subscriber_name': detail.subscriber.name,
        'square_id': detail.subscriber.squareId,
        'square_name': detail.subscriber.squareName,
      });
    }
    return Meter(
      id: m.meterId,
      uniqueCode: m.uniqueCode,
      subscriberId: detail.subscriber.id,
      subscriberName: detail.subscriber.name,
      squareId: detail.subscriber.squareId,
      squareName: detail.subscriber.squareName,
      pathNumber: m.pathNumber,
      status: m.status,
      balance: m.credit - m.debit,
      lastReading: 0,
    );
  }

  Future<void> _defaultAddMeter(BuildContext context, Subscriber sub) async {
    if (onAddMeter != null) {
      onAddMeter!(context, sub);
      return;
    }
    if (GetIt.I.isRegistered<MetersRepository>()) {
      await Navigator.of(context).push<void>(
        MaterialPageRoute<void>(
          builder: (_) => MeterFormScreen(
            repository: GetIt.I<MetersRepository>(),
            initialSubscriberId: sub.id,
          ),
        ),
      );
      if (context.mounted) {
        await context.read<Subscriber360Cubit>().load(sub.id);
      }
      return;
    }
    final router = GoRouter.maybeOf(context);
    if (router != null) {
      try {
        await context.push<Object?>('/meters/new?subscriber_id=${sub.id}');
        if (context.mounted) {
          await context.read<Subscriber360Cubit>().load(sub.id);
        }
      } catch (_) {}
    }
  }

  Future<void> _editMeter(BuildContext context, MeterBalance m) async {
    if (!GetIt.I.isRegistered<MetersRepository>()) return;
    await Navigator.of(context).push<void>(
      MaterialPageRoute<void>(
        builder: (_) => MeterFormScreen(
          repository: GetIt.I<MetersRepository>(),
          meter: _toMeterModel(m),
        ),
      ),
    );
    if (context.mounted) {
      await context.read<Subscriber360Cubit>().load(detail.subscriber.id);
    }
  }

  Future<void> _defaultRecordReading(
    BuildContext context,
    MeterBalance m,
  ) async {
    if (onRecordReading != null) {
      onRecordReading!(context, m);
      return;
    }
    if (GetIt.I.isRegistered<ReadingsRepository>()) {
      await RecordReadingSheet.show(
        context,
        meter: _toMeterModel(m),
        readingsRepository: GetIt.I<ReadingsRepository>(),
      );
      if (context.mounted) {
        await context.read<Subscriber360Cubit>().load(detail.subscriber.id);
      }
      return;
    }
    final router = GoRouter.maybeOf(context);
    if (router != null) {
      try {
        await context.push('/readings/submit?meter_id=${m.meterId}');
      } catch (_) {}
    }
  }

  @override
  Widget build(BuildContext context) {
    // The API retains historical meters for ledgers. Display only the current
    // association (removed_at == null) in the operational subscriber tab.
    final meters = detail.summary.meterBalances.where((meter) {
      final raw = detail.metersRaw
          .where((row) => row['id'] == meter.meterId)
          .firstOrNull;
      return raw == null || raw['removed_at'] == null;
    }).toList();
    final hasMetersRepo = GetIt.I.isRegistered<MetersRepository>();
    final canAddMeter =
        meters.isEmpty &&
        (onAddMeter != null ||
            hasMetersRepo ||
            GoRouter.maybeOf(context) != null);
    final canRecordReading =
        onRecordReading != null ||
        GetIt.I.isRegistered<ReadingsRepository>() ||
        GoRouter.maybeOf(context) != null;
    final rows = [
      for (var index = 0; index < meters.length; index++)
        {
          ..._profileRecord(context, {
            'id': meters[index].meterId,
            'meter_id': meters[index].meterId,
            'unique_code': meters[index].uniqueCode,
            'path_number': meters[index].pathNumber,
            'created_at': detail.metersRaw
                .where((raw) => raw['id'] == meters[index].meterId)
                .firstOrNull?['created_at'],
          }),
          '_index': index,
        },
    ];
    return ListPresentation<Map<String, dynamic>>.slivers(
      items: rows,
      initialSettings: const ListPresentationSettings(
        sortBy: 'path_number',
        descending: false,
      ),
      sortValues: _profileSortValues,
      pathNumbers: (row) => RecordLocation.fromJson(row).paths,
      sliversBuilder: (context, visible, options) => [
        if (meters.isEmpty)
          SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 12, 16, 8),
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: AppPrimaryButton(
                label: 'ربط العداد بالمشترك',
                icon: Icons.add_rounded,
                expand: false,
                onPressed: canAddMeter
                    ? () => _defaultAddMeter(context, detail.subscriber)
                    : null,
              ),
            ),
          ),
        ),
        if (visible.isEmpty)
          const SliverFillRemaining(
            hasScrollBody: false,
            child: _SubscriberEmptyState(
              title: 'لا يوجد عداد حالي مرتبط بهذا المشترك',
              icon: Icons.speed,
            ),
          )
        else
          SliverList.builder(
            itemCount: visible.length,
            itemBuilder: (_, i) {
              final m = meters[visible[i]['_index'] as int];
              final isActive = m.status == 'active';
              return Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
                child: AppCard(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const AppGlyph(icon: Icons.speed_rounded, size: 38),
                          const Gap(10),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(
                                  SubscriberExperienceStrings.meterTitle(
                                    m.meterId,
                                  ),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w600,
                                    fontSize: 14.5,
                                    height: 1.3,
                                  ),
                                ),
                                const Gap(2),
                                Directionality(
                                  textDirection: TextDirection.ltr,
                                  child: Text(
                                    m.uniqueCode,
                                    style: Theme.of(
                                      context,
                                    ).textTheme.labelMedium,
                                  ),
                                ),
                                Text(
                                  'مسار: ${m.pathNumber ?? '-'} • قراءات: ${m.readingsCount}',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: Theme.of(context).textTheme.labelSmall,
                                ),
                              ],
                            ),
                          ),
                          StatusBadge(
                            label: isActive ? 'نشط' : 'غير نشط',
                            color: isActive
                                ? AppColors.success
                                : AppColors.warning,
                          ),
                          if (hasMetersRepo)
                            PopupMenuButton<String>(
                              tooltip: 'إجراءات العداد',
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(
                                  AppRadius.lg,
                                ),
                              ),
                              onSelected: (value) {
                                if (value == 'edit') {
                                  _editMeter(context, m);
                                }
                              },
                              itemBuilder: (context) => const [
                                PopupMenuItem(
                                  value: 'edit',
                                  child: Row(
                                    children: [
                                      Icon(Icons.edit_outlined, size: 18),
                                      SizedBox(width: 10),
                                      Text('تعديل العداد'),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                        ],
                      ),
                      const Gap(10),
                      Row(
                        children: [
                          Expanded(
                            child: _TabMoneyStat(
                              label: 'دائن',
                              value: m.credit,
                              color: AppColors.success,
                            ),
                          ),
                          const Gap(8),
                          Expanded(
                            child: _TabMoneyStat(
                              label: 'مدين',
                              value: m.debit,
                              color: AppColors.danger,
                            ),
                          ),
                          if (canRecordReading) ...[
                            const Gap(8),
                            AppIconButton(
                              icon: Icons.edit_note_rounded,
                              tooltip: 'تسجيل قراءة سريعة',
                              size: 38,
                              onPressed: () =>
                                  _defaultRecordReading(context, m),
                            ),
                          ],
                        ],
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        const SliverToBoxAdapter(child: Gap(24)),
      ],
    );
  }
}

// ─── التبويب 2: الفواتير (إجراءات كاملة مثل InvoicesRelationManager) ───
class _InvoicesTab extends StatelessWidget {
  const _InvoicesTab({
    required this.subscriber,
    required this.invoices,
    this.onCollect,
  });

  final Subscriber subscriber;
  final List<Map<String, dynamic>> invoices;
  final VoidCallback? onCollect;

  InvoiceModel _toInvoiceModel(Map<String, dynamic> raw) {
    return InvoiceModel.fromJson({
      'subscriber_id': subscriber.id,
      'subscriber': {
        'id': subscriber.id,
        'name': subscriber.name,
        'phone': subscriber.phone,
        'square_id': subscriber.squareId,
        if (subscriber.squareName != null)
          'square': {'id': subscriber.squareId, 'name': subscriber.squareName},
      },
      ...raw,
    });
  }

  Future<void> _openDetails(BuildContext context, InvoiceModel model) async {
    final router = GoRouter.maybeOf(context);
    if (router == null) return;
    try {
      await context.push('/invoices/${model.id}', extra: model);
      if (context.mounted) {
        await context.read<Subscriber360Cubit>().load(subscriber.id);
      }
    } catch (_) {}
  }

  Future<void> _editInvoice(BuildContext context, InvoiceModel model) async {
    final router = GoRouter.maybeOf(context);
    if (router == null) return;
    try {
      await context.push('/invoices/${model.id}/edit', extra: model);
      if (context.mounted) {
        await context.read<Subscriber360Cubit>().load(subscriber.id);
      }
    } catch (_) {}
  }

  Future<void> _deleteInvoice(BuildContext context, InvoiceModel model) async {
    if (!GetIt.I.isRegistered<InvoicesRepository>()) return;
    final confirmed = await confirmAction(
      context,
      'حذف الفاتورة',
      'حذف الفاتورة ${model.billNumber} وإرجاع القراءات والرسوم غير مفوترة وإعادة احتساب الدفعات؟',
    );
    if (!confirmed || !context.mounted) return;
    try {
      await GetIt.I<InvoicesRepository>().delete(model.id);
      if (context.mounted) {
        await context.read<Subscriber360Cubit>().load(subscriber.id);
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('تم حذف الفاتورة بنجاح')),
          );
        }
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(friendlyError(e)),
            backgroundColor: AppColors.danger,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (invoices.isEmpty) {
      return const _SubscriberEmptyState(
        title: 'لا توجد فواتير',
        icon: Icons.receipt_long,
      );
    }
    final hasFinanceServices = GetIt.I.isRegistered<InvoicesRepository>();
    final rows = [for (final row in invoices) _profileRecord(context, row)];
    return ListPresentation<Map<String, dynamic>>.slivers(
      items: rows,
      initialSettings: const ListPresentationSettings(sortBy: 'id'),
      sortValues: _profileSortValues,
      pathNumbers: (row) => RecordLocation.fromJson(row).paths,
      sliversBuilder: (context, visible, options) => [
        SliverPadding(
          padding: const EdgeInsets.only(top: 8, bottom: 24),
          sliver: SliverList.builder(
            itemCount: visible.length,
            itemBuilder: (_, i) {
              final inv = visible[i];
              final model = _toInvoiceModel(inv);
              final total =
                  JsonUtils.asDoubleOrNull(inv['total_amount']) ??
                  model.totalAmount;
              final paid =
                  JsonUtils.asDoubleOrNull(inv['amount_paid']) ??
                  model.amountPaid;
              final isPaid = (inv['status'] ?? model.status.wire) == 'paid';
              return Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
                child: AppCard(
                  padding: const EdgeInsets.all(12),
                  onTap: model.id > 0
                      ? () => _openDetails(context, model)
                      : null,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const AppGlyph(
                            icon: Icons.receipt_long_rounded,
                            size: 38,
                          ),
                          const Gap(10),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(
                                  model.billNumber,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w600,
                                    fontSize: 14.5,
                                    height: 1.3,
                                  ),
                                ),
                                const Gap(2),
                                Text(
                                  '${inv['bill_date'] ?? ''}',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: Theme.of(context).textTheme.labelSmall,
                                ),
                              ],
                            ),
                          ),
                          StatusBadge(
                            label: model.status.label,
                            color: isPaid
                                ? AppColors.success
                                : AppColors.warning,
                          ),
                          if (hasFinanceServices && model.id > 0)
                            PopupMenuButton<String>(
                              tooltip: 'إجراءات الفاتورة',
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(
                                  AppRadius.lg,
                                ),
                              ),
                              onSelected: (action) async {
                                switch (action) {
                                  case 'open':
                                    await _openDetails(context, model);
                                  case 'pdf':
                                    await openInvoicePdfPreview(context, model);
                                  case 'thermal':
                                    await printInvoiceThermal(context, model);
                                  case 'whatsapp':
                                    await sendInvoiceWhatsapp(context, model);
                                  case 'edit':
                                    await _editInvoice(context, model);
                                  case 'delete':
                                    await _deleteInvoice(context, model);
                                }
                              },
                              itemBuilder: (_) => const [
                                PopupMenuItem(
                                  value: 'open',
                                  child: Row(
                                    children: [
                                      Icon(Icons.open_in_new, size: 18),
                                      SizedBox(width: 10),
                                      Text('عرض التفاصيل'),
                                    ],
                                  ),
                                ),
                                PopupMenuItem(
                                  value: 'pdf',
                                  child: Row(
                                    children: [
                                      Icon(
                                        Icons.picture_as_pdf_outlined,
                                        size: 18,
                                      ),
                                      SizedBox(width: 10),
                                      Text('طباعة / معاينة PDF'),
                                    ],
                                  ),
                                ),
                                PopupMenuItem(
                                  value: 'thermal',
                                  child: Row(
                                    children: [
                                      Icon(Icons.print_outlined, size: 18),
                                      SizedBox(width: 10),
                                      Text('طباعة حرارية'),
                                    ],
                                  ),
                                ),
                                PopupMenuItem(
                                  value: 'whatsapp',
                                  child: Row(
                                    children: [
                                      Icon(
                                        Icons.chat_outlined,
                                        size: 18,
                                        color: AppColors.whatsapp,
                                      ),
                                      SizedBox(width: 10),
                                      Text('إرسال واتساب'),
                                    ],
                                  ),
                                ),
                                PopupMenuItem(
                                  value: 'edit',
                                  child: Row(
                                    children: [
                                      Icon(Icons.edit_outlined, size: 18),
                                      SizedBox(width: 10),
                                      Text('تعديل الفاتورة'),
                                    ],
                                  ),
                                ),
                                PopupMenuItem(
                                  value: 'delete',
                                  child: Row(
                                    children: [
                                      Icon(
                                        Icons.delete_outline,
                                        size: 18,
                                        color: AppColors.danger,
                                      ),
                                      SizedBox(width: 10),
                                      Text(
                                        'حذف الفاتورة',
                                        style: TextStyle(
                                          color: AppColors.danger,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                        ],
                      ),
                      const Gap(10),
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 8,
                        ),
                        decoration: BoxDecoration(
                          color: Theme.of(context)
                              .colorScheme
                              .surfaceContainerHighest
                              .withValues(alpha: .45),
                          borderRadius: BorderRadius.circular(AppRadius.lg),
                        ),
                        child: Row(
                          children: [
                            _TabMoneyStat(
                              label: 'الإجمالي',
                              value: total,
                              color: Theme.of(context).colorScheme.onSurface,
                            ),
                            const Gap(16),
                            _TabMoneyStat(
                              label: 'المسدد',
                              value: paid,
                              color: AppColors.success,
                            ),
                            const Spacer(),
                            if (!isPaid)
                              AppIconButton(
                                icon: Icons.payments_outlined,
                                tooltip: 'تحصيل الفاتورة',
                                size: 38,
                                onPressed: () async {
                                  if (hasFinanceServices && model.id > 0) {
                                    final saved = await collectInvoicePayment(
                                      context,
                                      model,
                                    );
                                    if (saved && context.mounted) {
                                      await context
                                          .read<Subscriber360Cubit>()
                                          .load(subscriber.id);
                                    }
                                  } else if (onCollect != null) {
                                    onCollect!();
                                  }
                                },
                              ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        ),
      ],
    );
  }
}

// ─── التبويب 3: المدفوعات (إجراءات كاملة مثل PaymentsRelationManager) ───
class _PaymentsTab extends StatelessWidget {
  const _PaymentsTab({required this.subscriber, required this.payments});

  final Subscriber subscriber;
  final List<Map<String, dynamic>> payments;

  PaymentModel _toPaymentModel(Map<String, dynamic> raw) {
    return PaymentModel.fromJson({
      'subscriber_id': subscriber.id,
      'subscriber': {
        'id': subscriber.id,
        'name': subscriber.name,
        'phone': subscriber.phone,
        'square_id': subscriber.squareId,
        if (subscriber.squareName != null)
          'square': {'id': subscriber.squareId, 'name': subscriber.squareName},
      },
      ...raw,
    });
  }

  Future<void> _editPayment(BuildContext context, PaymentModel model) async {
    final router = GoRouter.maybeOf(context);
    if (router == null) return;
    try {
      await context.push('/payments/${model.id}/edit', extra: model);
      if (context.mounted) {
        await context.read<Subscriber360Cubit>().load(subscriber.id);
      }
    } catch (_) {}
  }

  Future<void> _deletePayment(BuildContext context, PaymentModel model) async {
    if (!GetIt.I.isRegistered<PaymentsRepository>()) return;
    final confirmed = await confirmAction(
      context,
      'حذف سند القبض',
      'حذف السند ${model.receiptNumber} وإعادة احتساب الفواتير والأرصدة المرتبطة؟',
    );
    if (!confirmed || !context.mounted) return;
    try {
      await GetIt.I<PaymentsRepository>().delete(model.id);
      if (context.mounted) {
        await context.read<Subscriber360Cubit>().load(subscriber.id);
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('تم حذف سند القبض بنجاح')),
          );
        }
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(friendlyError(e)),
            backgroundColor: AppColors.danger,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (payments.isEmpty) {
      return const _SubscriberEmptyState(
        title: 'لا توجد مدفوعات',
        icon: Icons.payments,
      );
    }
    final hasPaymentsRepo = GetIt.I.isRegistered<PaymentsRepository>();
    final rows = [for (final row in payments) _profileRecord(context, row)];
    return ListPresentation<Map<String, dynamic>>.slivers(
      items: rows,
      initialSettings: const ListPresentationSettings(sortBy: 'id'),
      sortValues: _profileSortValues,
      pathNumbers: (row) => RecordLocation.fromJson(row).paths,
      sliversBuilder: (context, visible, options) => [
        SliverPadding(
          padding: const EdgeInsets.only(top: 8, bottom: 24),
          sliver: SliverList.builder(
            itemCount: visible.length,
            itemBuilder: (_, i) {
              final p = visible[i];
              final model = _toPaymentModel(p);
              return Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
                child: AppCard(
                  padding: const EdgeInsets.all(12),
                  onTap: hasPaymentsRepo && model.id > 0
                      ? () => showPaymentActionSheet(
                          context,
                          payment: model,
                          onDeleted: () => _deletePayment(context, model),
                        )
                      : null,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const AppGlyph(
                            icon: Icons.receipt_rounded,
                            tone: AppColors.success,
                            size: 38,
                          ),
                          const Gap(10),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(
                                  model.receiptNumber,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w600,
                                    fontSize: 14.5,
                                    height: 1.3,
                                  ),
                                ),
                                const Gap(2),
                                Text(
                                  '${p['payment_date'] ?? ''} • ${model.method.label}',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: Theme.of(context).textTheme.labelSmall,
                                ),
                              ],
                            ),
                          ),
                          if (hasPaymentsRepo && model.id > 0)
                            PopupMenuButton<String>(
                              tooltip: 'إجراءات السند',
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(
                                  AppRadius.lg,
                                ),
                              ),
                              onSelected: (action) async {
                                switch (action) {
                                  case 'pdf':
                                    await openPaymentPdfPreview(context, model);
                                  case 'thermal':
                                    await printPaymentThermal(context, model);
                                  case 'whatsapp':
                                    await sendPaymentWhatsapp(context, model);
                                  case 'edit':
                                    await _editPayment(context, model);
                                  case 'delete':
                                    await _deletePayment(context, model);
                                }
                              },
                              itemBuilder: (_) => const [
                                PopupMenuItem(
                                  value: 'pdf',
                                  child: Row(
                                    children: [
                                      Icon(
                                        Icons.picture_as_pdf_outlined,
                                        size: 18,
                                      ),
                                      SizedBox(width: 10),
                                      Text('طباعة / معاينة PDF'),
                                    ],
                                  ),
                                ),
                                PopupMenuItem(
                                  value: 'thermal',
                                  child: Row(
                                    children: [
                                      Icon(Icons.print_outlined, size: 18),
                                      SizedBox(width: 10),
                                      Text('طباعة حرارية'),
                                    ],
                                  ),
                                ),
                                PopupMenuItem(
                                  value: 'whatsapp',
                                  child: Row(
                                    children: [
                                      Icon(
                                        Icons.chat_outlined,
                                        size: 18,
                                        color: AppColors.whatsapp,
                                      ),
                                      SizedBox(width: 10),
                                      Text('إرسال واتساب'),
                                    ],
                                  ),
                                ),
                                PopupMenuItem(
                                  value: 'edit',
                                  child: Row(
                                    children: [
                                      Icon(Icons.edit_outlined, size: 18),
                                      SizedBox(width: 10),
                                      Text('تعديل السند'),
                                    ],
                                  ),
                                ),
                                PopupMenuItem(
                                  value: 'delete',
                                  child: Row(
                                    children: [
                                      Icon(
                                        Icons.delete_outline,
                                        size: 18,
                                        color: AppColors.danger,
                                      ),
                                      SizedBox(width: 10),
                                      Text(
                                        'حذف السند',
                                        style: TextStyle(
                                          color: AppColors.danger,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                        ],
                      ),
                      const Gap(10),
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 8,
                        ),
                        decoration: BoxDecoration(
                          color: Theme.of(context)
                              .colorScheme
                              .surfaceContainerHighest
                              .withValues(alpha: .45),
                          borderRadius: BorderRadius.circular(AppRadius.lg),
                        ),
                        child: Row(
                          children: [
                            Text(
                              'مبلغ السند',
                              style: Theme.of(context).textTheme.labelSmall,
                            ),
                            const Gap(8),
                            Directionality(
                              textDirection: TextDirection.ltr,
                              child: Text(
                                CurrencyFormatter.format(model.amount),
                                style: TextStyle(
                                  color: AppSemantic.resolve(
                                    context,
                                    AppColors.success,
                                  ),
                                  fontWeight: FontWeight.w600,
                                  fontSize: 15,
                                  fontFeatures: const <FontFeature>[
                                    FontFeature.tabularFigures(),
                                  ],
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        ),
      ],
    );
  }
}


// ─── التبويب 4: كشف الحساب ───
class _StatementTab extends StatelessWidget {
  const _StatementTab({required this.subscriberName});
  final String subscriberName;

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<Subscriber360Cubit, Subscriber360State>(
      builder: (context, state) {
        final detail = state.detail!;
        final parties = <PartyFilter>[
          const PartyAll(),
          const PartyGeneral(),
          for (final m in detail.summary.meterBalances) PartyMeter(m),
        ];
        return _SubscriberTabScrollView(
          header: [
            const SizedBox(height: 10),
            Row(
              children: <Widget>[
                Expanded(
                  child: AppFilterChips(
                    padding: const EdgeInsetsDirectional.only(start: 16),
                    children: <Widget>[
                      for (final party in parties)
                        AppFilterChip(
                          label: party.label,
                          selected: state.party == party,
                          onTap: () => context
                              .read<Subscriber360Cubit>()
                              .filterParty(party),
                        ),
                    ],
                  ),
                ),
                Padding(
                  padding: const EdgeInsetsDirectional.only(start: 4, end: 16),
                  child: AppIconButton(
                    icon: Icons.picture_as_pdf_outlined,
                    tooltip: 'تصدير كشف الحساب PDF',
                    size: 38,
                    onPressed: () async {
                      try {
                        await StatementPdfService.shareStatement(
                          subscriberName: subscriberName,
                          summary: detail.summary,
                          entries: state.statement,
                        );
                      } catch (_) {
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(
                              content: Text(
                                SubscriberExperienceStrings.exportFailed,
                              ),
                            ),
                          );
                        }
                      }
                    },
                  ),
                ),
              ],
            ),
            if (state.statementLoading) const LinearProgressIndicator(),
          ],
          empty: const _SubscriberEmptyState(
            title: 'لا توجد حركات مالية ضمن هذا الفلتر',
            icon: Icons.account_balance_outlined,
          ),
          records: [
            for (final entry in state.statement)
              {
                'id': entry.id,
                'meter_id': entry.meterId,
                'created_at': entry.createdAt,
                'amount': entry.amount,
                'description': entry.description,
                'status': entry.type,
              },
          ],
          itemBuilder: (_, i) {
            final e = state.statement[i];
            return Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
              child: AppCard(
                padding: const EdgeInsets.all(12),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    AppGlyph(
                      icon: e.isCredit
                          ? Icons.arrow_downward_rounded
                          : Icons.arrow_upward_rounded,
                      tone: e.isCredit ? AppColors.success : AppColors.danger,
                      size: 34,
                    ),
                    const Gap(10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            e.description ?? '',
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: Theme.of(context).textTheme.bodyMedium
                                ?.copyWith(fontWeight: FontWeight.w500),
                          ),
                          const Gap(3),
                          Text(
                            '${e.createdAt ?? ''} • ${e.party} • رصيد تراكمي: ${e.runningBalance}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: Theme.of(context).textTheme.labelSmall,
                          ),
                        ],
                      ),
                    ),
                    const Gap(8),
                    BalanceText(e.amount),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }
}

/// رقم مساند داخل لوحة نقود هادئة: تسمية صغيرة + قيمة بأرقام جدولية.
class _TabMoneyStat extends StatelessWidget {
  const _TabMoneyStat({
    required this.label,
    required this.value,
    required this.color,
  });
  final String label;
  final double value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(label, style: theme.textTheme.labelSmall),
        const Gap(2),
        AppAmount(
          value: CurrencyFormatter.format(value),
          style: TextStyle(
            color: AppSemantic.resolve(context, color),
            fontWeight: FontWeight.w600,
            fontSize: 13,
            height: 1.2,
            fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
          ),
        ),
      ],
    );
  }
}


/// اختصار نوعي لبيانات 360 داخل التبويبات.
