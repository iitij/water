import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/network/dio_client.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/domain/repositories/invoices_repository.dart';
import '../../../invoices/presentation/sheets/generate_invoice_sheets.dart';
import '../../../invoices/presentation/widgets/invoice_actions.dart';
import '../../../meters/data/models/meter.dart';
import '../../../meters/domain/meters_repository.dart';
import '../../../meters/presentation/pages/meter_form_screen.dart';
import '../../../payments/data/models/payment_model.dart';
import '../../../payments/domain/repositories/payments_repository.dart';
import '../../../payments/presentation/widgets/payment_actions.dart';
import '../../../readings/domain/readings_repository.dart';
import '../../../readings/presentation/widgets/record_reading_sheet.dart';
import '../../../service_requests/domain/entities/service_request.dart';
import '../../../service_requests/presentation/cubit/service_requests_cubit.dart';
import '../../../subscription_fees/domain/entities/subscription_fee.dart';
import '../../../subscription_fees/presentation/cubit/subscription_fees_cubit.dart';
import '../../domain/entities/subscriber.dart';
import '../../domain/repositories/subscribers_repository.dart';
import '../cubit/subscriber360_cubit.dart';
import '../widgets/statement_pdf_service.dart';
import 'subscriber_form_screen.dart';
import 'subscribers_screen.dart'
    show CollectPaymentLauncher, launchDefaultSubscriberPayment;

/// منافذ تكامل: أنور (Dev 2) بطاقة عداد + تسجيل قراءة — أكرم (Dev 3) تحصيل/فواتير/مدفوعات.
typedef RecordReadingLauncher = void Function(BuildContext context, MeterBalance meter);
typedef AddMeterLauncher = void Function(BuildContext context, Subscriber subscriber);

bool _has360Cubits(BuildContext context) {
  try {
    context.read<Subscriber360Cubit>();
    context.read<SubscriptionFeesCubit>();
    context.read<ServiceRequestsCubit>();
    return true;
  } catch (_) {
    return false;
  }
}

/// SCR-07 — الملف المالي الشامل 360°: ملخص معزول + تعديل/حذف المشترك + 6 تبويبات تفاعلية كاملة.
class Subscriber360Screen extends StatelessWidget {
  const Subscriber360Screen({
    super.key,
    required this.subscriber,
    this.onRecordReading,
    this.onAddMeter,
    this.onCollectPayment,
  });

  final Subscriber subscriber;
  final RecordReadingLauncher? onRecordReading;
  final AddMeterLauncher? onAddMeter;
  final CollectPaymentLauncher? onCollectPayment;

  @override
  Widget build(BuildContext context) {
    if (!_has360Cubits(context)) {
      final deps = FouadModule.deps;
      return RepositoryProvider<SubscribersRepository>.value(
        value: deps.subscribers,
        child: MultiBlocProvider(
          providers: [
            BlocProvider<Subscriber360Cubit>(
              create: (_) => Subscriber360Cubit(deps.subscribers)..load(subscriber.id),
            ),
            BlocProvider<SubscriptionFeesCubit>(
              create: (_) => SubscriptionFeesCubit(deps.fees)
                ..subscriberId = subscriber.id
                ..load(),
            ),
            BlocProvider<ServiceRequestsCubit>(
              create: (_) => ServiceRequestsCubit(deps.requests)
                ..subscriberId = subscriber.id
                ..load(),
            ),
          ],
          child: _Subscriber360View(
            subscriber: subscriber,
            onRecordReading: onRecordReading,
            onAddMeter: onAddMeter,
            onCollectPayment: onCollectPayment,
          ),
        ),
      );
    }
    return _Subscriber360View(
      subscriber: subscriber,
      onRecordReading: onRecordReading,
      onAddMeter: onAddMeter,
      onCollectPayment: onCollectPayment,
    );
  }
}

class _Subscriber360View extends StatelessWidget {
  const _Subscriber360View({
    required this.subscriber,
    this.onRecordReading,
    this.onAddMeter,
    this.onCollectPayment,
  });

  final Subscriber subscriber;
  final RecordReadingLauncher? onRecordReading;
  final AddMeterLauncher? onAddMeter;
  final CollectPaymentLauncher? onCollectPayment;

  Future<void> _collect(BuildContext context, Subscriber activeSubscriber) async {
    if (onCollectPayment != null) {
      await onCollectPayment!(context, activeSubscriber);
      if (context.mounted) {
        await context.read<Subscriber360Cubit>().load(activeSubscriber.id);
      }
      return;
    }
    final cubit = context.read<Subscriber360Cubit>();
    final saved = await launchDefaultSubscriberPayment(context, activeSubscriber);
    if (saved && context.mounted) {
      await cubit.load(activeSubscriber.id);
    }
  }

  Future<void> _editSubscriber(BuildContext context, Subscriber activeSubscriber) async {
    final cubit = context.read<Subscriber360Cubit>();
    bool? saved;
    final router = GoRouter.maybeOf(context);
    if (router != null) {
      try {
        saved = await context.push<bool>('/subscriber-form', extra: activeSubscriber);
      } catch (_) {
        if (!context.mounted) return;
        saved = await Navigator.of(context).push<bool>(
          MaterialPageRoute<bool>(
            builder: (_) => RepositoryProvider<SubscribersRepository>.value(
              value: FouadModule.deps.subscribers,
              child: SubscriberFormScreen(existing: activeSubscriber),
            ),
          ),
        );
      }
    } else {
      saved = await Navigator.of(context).push<bool>(
        MaterialPageRoute<bool>(
          builder: (_) => RepositoryProvider<SubscribersRepository>.value(
            value: FouadModule.deps.subscribers,
            child: SubscriberFormScreen(existing: activeSubscriber),
          ),
        ),
      );
    }
    if (saved == true && context.mounted) {
      await cubit.load(activeSubscriber.id);
    }
  }

  Future<void> _deleteSubscriber(BuildContext context, Subscriber activeSubscriber) async {
    final confirmed = await confirmAction(
      context,
      'حذف المشترك',
      'هل تريد حذف المشترك «${activeSubscriber.name}»؟ لا يمكن حذفه إذا كانت له عدادات أو حركات مالية.',
    );
    if (!confirmed || !context.mounted) return;
    try {
      await FouadModule.deps.subscribers.deleteSubscriber(activeSubscriber.id);
      if (!context.mounted) return;
      final router = GoRouter.maybeOf(context);
      if (router != null && router.canPop()) {
        context.pop(true);
      } else {
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(friendlyError(e)), backgroundColor: AppColors.danger),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<Subscriber360Cubit, Subscriber360State>(
      builder: (context, state) {
        final detail = state.detail;
        final activeSubscriber = detail?.subscriber ?? subscriber;
        return DefaultTabController(
          length: 6,
          child: Scaffold(
            appBar: AppBar(
              title: Text(activeSubscriber.name),
              actions: [
                IconButton(
                  icon: const Icon(Icons.payments_outlined),
                  tooltip: 'تحصيل دفعة',
                  onPressed: () => _collect(context, activeSubscriber),
                ),
                IconButton(
                  icon: const Icon(Icons.edit_outlined),
                  tooltip: 'تعديل المشترك',
                  onPressed: () => _editSubscriber(context, activeSubscriber),
                ),
                IconButton(
                  icon: const Icon(Icons.delete_outline),
                  tooltip: 'حذف المشترك',
                  onPressed: () => _deleteSubscriber(context, activeSubscriber),
                ),
              ],
              bottom: const TabBar(
                isScrollable: true,
                tabs: [
                  Tab(icon: Icon(Icons.speed), text: 'العدادات'),
                  Tab(icon: Icon(Icons.receipt_long), text: 'الفواتير'),
                  Tab(icon: Icon(Icons.payments), text: 'المدفوعات'),
                  Tab(icon: Icon(Icons.card_membership), text: 'رسوم الاشتراك'),
                  Tab(icon: Icon(Icons.local_shipping), text: 'طلبات الوايت'),
                  Tab(icon: Icon(Icons.account_balance), text: 'كشف الحساب'),
                ],
              ),
            ),
            body: state.loading
                ? const Center(child: CircularProgressIndicator())
                : detail == null
                    ? EmptyState(message: state.error ?? 'تعذر تحميل الملف')
                    : Column(children: [
                        _FinancialSummaryHeader(summary: detail.summary),
                        Expanded(
                          child: TabBarView(children: [
                            _MetersTab(
                              detail: detail,
                              onRecordReading: onRecordReading,
                              onAddMeter: onAddMeter,
                            ),
                            _InvoicesTab(
                              subscriber: activeSubscriber,
                              invoices: detail.invoices,
                              onCollect: () => _collect(context, activeSubscriber),
                            ),
                            _PaymentsTab(
                              subscriber: activeSubscriber,
                              payments: detail.payments,
                            ),
                            _FeesTab(meters: detail.summary.meterBalances),
                            const _RequestsTab(),
                            _StatementTab(subscriberName: activeSubscriber.name),
                          ]),
                        ),
                      ]),
          ),
        );
      },
    );
  }
}

/// لوحة الملخص المالي: فصل صريح بين الرصيد العام وأرصدة كل عداد (قاعدة العزل).
class _FinancialSummaryHeader extends StatelessWidget {
  const _FinancialSummaryHeader({required this.summary});
  final FinancialSummary summary;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: AppColors.primaryDark,
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Wrap(spacing: 12, runSpacing: 8, children: [
          _SummaryChip(label: 'إجمالي دائن (عام + عدادات)', value: summary.totalCredit, color: AppColors.success),
          _SummaryChip(label: 'إجمالي مدين / متأخرات', value: -summary.totalDebit, color: AppColors.danger),
          _SummaryChip(label: 'الرصيد العام للمشترك (اشتراك/وايت)', value: summary.generalBalance, color: Colors.lightBlueAccent),
          _SummaryChip(label: 'متبقي رسوم اشتراك غير مفوترة', value: -summary.remainingSubscriptionFee, color: AppColors.brandAmber),
        ]),
        if (summary.meterBalances.isNotEmpty) ...[
          const SizedBox(height: 10),
          const Text('أرصدة العدادات المعزولة:', style: TextStyle(color: Colors.white70)),
          const SizedBox(height: 6),
          Wrap(spacing: 8, runSpacing: 6, children: [
            for (final m in summary.meterBalances)
              Chip(
                avatar: const Icon(Icons.speed, size: 16),
                label: Text('${m.title} | دائن ${m.credit.toStringAsFixed(0)} / مدين ${m.debit.toStringAsFixed(0)}'),
              ),
          ]),
        ],
      ]),
    );
  }
}

class _SummaryChip extends StatelessWidget {
  const _SummaryChip({required this.label, required this.value, required this.color});
  final String label;
  final double value;
  final Color color;
  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, style: const TextStyle(color: Colors.white70, fontSize: 11)),
        Directionality(
          textDirection: TextDirection.ltr,
          child: Text(CurrencyFormatter.format(value),
              style: TextStyle(color: color, fontSize: 16, fontWeight: FontWeight.bold)),
        ),
      ]);
}

// ─── التبويب 1: العدادات ───
class _MetersTab extends StatelessWidget {
  const _MetersTab({required this.detail, this.onRecordReading, this.onAddMeter});
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
      tenantName: m.tenantName,
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

  Future<void> _deleteMeter(BuildContext context, MeterBalance m) async {
    if (!GetIt.I.isRegistered<MetersRepository>()) return;
    final confirmed = await confirmAction(
      context,
      'حذف العداد',
      'هل تريد حذف العداد «${m.uniqueCode}»؟ لا يمكن حذف عداد له قراءات أو فواتير أو سندات.',
    );
    if (!confirmed || !context.mounted) return;
    try {
      await GetIt.I<MetersRepository>().deleteMeter(m.meterId);
      if (context.mounted) {
        await context.read<Subscriber360Cubit>().load(detail.subscriber.id);
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('تم حذف العداد بنجاح')),
          );
        }
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(friendlyError(e)), backgroundColor: AppColors.danger),
        );
      }
    }
  }

  Future<void> _defaultRecordReading(BuildContext context, MeterBalance m) async {
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
    final meters = detail.summary.meterBalances;
    final hasMetersRepo = GetIt.I.isRegistered<MetersRepository>();
    final canAddMeter = onAddMeter != null || hasMetersRepo || GoRouter.maybeOf(context) != null;
    final canRecordReading = onRecordReading != null ||
        GetIt.I.isRegistered<ReadingsRepository>() ||
        GoRouter.maybeOf(context) != null;
    return Column(children: [
      Align(
        alignment: AlignmentDirectional.centerStart,
        child: Padding(
          padding: const EdgeInsets.all(8),
          child: FilledButton.icon(
            onPressed: canAddMeter ? () => _defaultAddMeter(context, detail.subscriber) : null,
            icon: const Icon(Icons.add),
            label: const Text('إضافة عداد للمشترك'),
          ),
        ),
      ),
      Expanded(
        child: meters.isEmpty
            ? const EmptyState(message: 'لا توجد عدادات لهذا المشترك', icon: Icons.speed)
            : ListView.builder(
                itemCount: meters.length,
                itemBuilder: (_, i) {
                  final m = meters[i];
                  return Padding(
                    padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
                    child: AppCard(
                      padding: EdgeInsets.zero,
                      child: ListTile(
                      contentPadding: const EdgeInsetsDirectional.fromSTEB(12, 4, 8, 4),
                      leading: const AppGlyph(icon: Icons.speed_rounded, size: 38),
                      title: Text(m.title),
                      subtitle: Text('مسار: ${m.pathNumber ?? '-'} • قراءات: ${m.readingsCount} • ${m.status == 'active' ? 'نشط' : 'غير نشط'}'),
                      trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                        Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                          Text('دائن ${m.credit.toStringAsFixed(0)}', style: const TextStyle(color: AppColors.success, fontSize: 12)),
                          Text('مدين ${m.debit.toStringAsFixed(0)}', style: const TextStyle(color: AppColors.danger, fontSize: 12)),
                        ]),
                        if (canRecordReading)
                          IconButton(
                            icon: const Icon(Icons.edit_note, color: AppColors.info),
                            tooltip: 'تسجيل قراءة سريعة',
                            onPressed: () => _defaultRecordReading(context, m),
                          ),
                        if (hasMetersRepo) ...[
                          IconButton(
                            icon: const Icon(Icons.edit_outlined, size: 20),
                            tooltip: 'تعديل العداد',
                            onPressed: () => _editMeter(context, m),
                          ),
                          IconButton(
                            icon: const Icon(Icons.delete_outline, size: 20, color: AppColors.danger),
                            tooltip: 'حذف العداد',
                            onPressed: () => _deleteMeter(context, m),
                          ),
                        ],
                      ]),
                    ),
                    ),
                  );
                },
              ),
      ),
    ]);
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
          SnackBar(content: Text(friendlyError(e)), backgroundColor: AppColors.danger),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (invoices.isEmpty) {
      return const EmptyState(message: 'لا توجد فواتير', icon: Icons.receipt_long);
    }
    final hasFinanceServices = GetIt.I.isRegistered<InvoicesRepository>();
    return ListView.builder(
      itemCount: invoices.length,
      itemBuilder: (_, i) {
        final inv = invoices[i];
        final model = _toInvoiceModel(inv);
        final total = (inv['total_amount'] as num?)?.toDouble() ?? model.totalAmount;
        final paid = (inv['amount_paid'] as num?)?.toDouble() ?? model.amountPaid;
        final isPaid = (inv['status'] ?? model.status.wire) == 'paid';
        return Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
          child: AppCard(
            padding: EdgeInsets.zero,
            child: ListTile(
            contentPadding: const EdgeInsetsDirectional.fromSTEB(12, 4, 8, 4),
            onTap: model.id > 0 ? () => _openDetails(context, model) : null,
            title: Text('${model.billNumber} • ${inv['bill_date'] ?? ''}'),
            subtitle: Text(
              'الإجمالي: ${CurrencyFormatter.format(total)} • المسدد: ${CurrencyFormatter.format(paid)}',
            ),
            trailing: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                StatusBadge(
                  label: model.status.label,
                  color: isPaid ? AppColors.success : AppColors.warning,
                ),
                if (!isPaid)
                  IconButton(
                    icon: const Icon(Icons.payments_outlined, color: AppColors.success),
                    tooltip: 'تحصيل الفاتورة',
                    onPressed: () async {
                      if (hasFinanceServices && model.id > 0) {
                        final saved = await collectInvoicePayment(context, model);
                        if (saved && context.mounted) {
                          await context.read<Subscriber360Cubit>().load(subscriber.id);
                        }
                      } else if (onCollect != null) {
                        onCollect!();
                      }
                    },
                  ),
                if (hasFinanceServices && model.id > 0)
                  PopupMenuButton<String>(
                    tooltip: 'إجراءات الفاتورة',
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
                      PopupMenuItem(value: 'open', child: ListTile(dense: true, leading: Icon(Icons.open_in_new), title: Text('عرض التفاصيل'))),
                      PopupMenuItem(value: 'pdf', child: ListTile(dense: true, leading: Icon(Icons.picture_as_pdf_outlined), title: Text('طباعة / معاينة PDF'))),
                      PopupMenuItem(value: 'thermal', child: ListTile(dense: true, leading: Icon(Icons.print_outlined), title: Text('طباعة حرارية'))),
                      PopupMenuItem(value: 'whatsapp', child: ListTile(dense: true, leading: Icon(Icons.chat_outlined, color: AppColors.whatsapp), title: Text('إرسال واتساب'))),
                      PopupMenuItem(value: 'edit', child: ListTile(dense: true, leading: Icon(Icons.edit_outlined), title: Text('تعديل الفاتورة'))),
                      PopupMenuItem(value: 'delete', child: ListTile(dense: true, leading: Icon(Icons.delete_outline, color: AppColors.danger), title: Text('حذف الفاتورة'))),
                    ],
                  ),
              ],
            ),
          ),
          ),
        );
      },
    );
  }
}

// ─── التبويب 3: المدفوعات (إجراءات كاملة مثل PaymentsRelationManager) ───
class _PaymentsTab extends StatelessWidget {
  const _PaymentsTab({
    required this.subscriber,
    required this.payments,
  });

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
          SnackBar(content: Text(friendlyError(e)), backgroundColor: AppColors.danger),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (payments.isEmpty) {
      return const EmptyState(message: 'لا توجد مدفوعات', icon: Icons.payments);
    }
    final hasPaymentsRepo = GetIt.I.isRegistered<PaymentsRepository>();
    return ListView.builder(
      itemCount: payments.length,
      itemBuilder: (_, i) {
        final p = payments[i];
        final model = _toPaymentModel(p);
        return Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
          child: AppCard(
            padding: EdgeInsets.zero,
            child: ListTile(
            contentPadding: const EdgeInsetsDirectional.fromSTEB(12, 4, 8, 4),
            onTap: hasPaymentsRepo && model.id > 0
                ? () => showPaymentActionSheet(
                      context,
                      payment: model,
                      onDeleted: () => _deletePayment(context, model),
                    )
                : null,
            leading: const AppGlyph(
              icon: Icons.receipt_rounded,
              tone: AppColors.success,
              size: 38,
            ),
            title: Text('${model.receiptNumber} • ${p['payment_date'] ?? ''}'),
            subtitle: Text(model.method.label),
            trailing: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  CurrencyFormatter.format(model.amount),
                  style: TextStyle(
                    color: AppSemantic.resolve(context, AppColors.success),
                    fontWeight: FontWeight.w600,
                    fontSize: 13,
                    fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
                  ),
                ),
                if (hasPaymentsRepo && model.id > 0)
                  PopupMenuButton<String>(
                    tooltip: 'إجراءات السند',
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
                      PopupMenuItem(value: 'pdf', child: ListTile(dense: true, leading: Icon(Icons.picture_as_pdf_outlined), title: Text('طباعة / معاينة PDF'))),
                      PopupMenuItem(value: 'thermal', child: ListTile(dense: true, leading: Icon(Icons.print_outlined), title: Text('طباعة حرارية'))),
                      PopupMenuItem(value: 'whatsapp', child: ListTile(dense: true, leading: Icon(Icons.chat_outlined, color: AppColors.whatsapp), title: Text('إرسال واتساب'))),
                      PopupMenuItem(value: 'edit', child: ListTile(dense: true, leading: Icon(Icons.edit_outlined), title: Text('تعديل السند'))),
                      PopupMenuItem(value: 'delete', child: ListTile(dense: true, leading: Icon(Icons.delete_outline, color: AppColors.danger), title: Text('حذف السند'))),
                    ],
                  ),
              ],
            ),
          ),
          ),
        );
      },
    );
  }
}

// ─── التبويب 4: رسوم الاشتراك ───
class _FeesTab extends StatelessWidget {
  const _FeesTab({required this.meters});
  final List<MeterBalance> meters;

  Future<void> _invoiceFee(BuildContext context, SubscriptionFee f) async {
    final feesCubit = context.read<SubscriptionFeesCubit>();
    final sub360Cubit = context.read<Subscriber360Cubit>();
    final canUseFinanceSheet = GetIt.I.isRegistered<InvoicesRepository>() ||
        GetIt.I.isRegistered<DioClient>();
    if (canUseFinanceSheet) {
      await GenerateSubscriptionInvoiceSheet.show(
        context,
        preselectedSubscriberId: '${f.subscriberId}',
      );
      if (context.mounted) {
        await feesCubit.load();
        await sub360Cubit.load(f.subscriberId);
      }
      return;
    }
    final bill = await feesCubit.invoice(f.id);
    if (context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('صدرت الفاتورة: $bill')));
    }
  }

  Future<void> _openFeeEditor(BuildContext context, {SubscriptionFee? existing}) async {
    final subscriberId = context.read<Subscriber360Cubit>().state.detail?.subscriber.id;
    if (subscriberId == null) return;
    final cubit = context.read<SubscriptionFeesCubit>();
    final sub360Cubit = context.read<Subscriber360Cubit>();
    final amountCtrl = TextEditingController(
      text: existing == null ? '' : existing.amount.toStringAsFixed(0),
    );
    final notesCtrl = TextEditingController(text: existing?.notes ?? '');
    int? selectedMeterId = existing?.meterId;
    var selectedStatus = existing?.status ?? FeeStatus.uninvoiced;

    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'إضافة رسوم اشتراك' : 'تعديل رسوم الاشتراك'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (meters.isNotEmpty) ...[
                  DropdownButtonFormField<int?>(
                    initialValue: meters.any((m) => m.meterId == selectedMeterId) ? selectedMeterId : null,
                    decoration: const InputDecoration(
                      labelText: 'العداد (اختياري)',
                    ),
                    items: [
                      const DropdownMenuItem<int?>(value: null, child: Text('بدون عداد محدد')),
                      for (final m in meters)
                        DropdownMenuItem<int?>(value: m.meterId, child: Text(m.title)),
                    ],
                    onChanged: (v) => setDialogState(() => selectedMeterId = v),
                  ),
                  const SizedBox(height: 10),
                ],
                TextField(
                  controller: amountCtrl,
                  keyboardType: TextInputType.number,
                  enabled: existing?.invoiceId == null,
                  decoration: const InputDecoration(
                    labelText: 'المبلغ (ريال)',
                  ),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<FeeStatus>(
                  initialValue: selectedStatus,
                  decoration: const InputDecoration(
                    labelText: 'الحالة',
                  ),
                  items: [
                    for (final s in FeeStatus.values)
                      DropdownMenuItem(value: s, child: Text(s.label)),
                  ],
                  onChanged: (v) => setDialogState(() => selectedStatus = v ?? selectedStatus),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: notesCtrl,
                  decoration: const InputDecoration(
                    labelText: 'ملاحظات',
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
            FilledButton(
              onPressed: () async {
                final amount = double.tryParse(normalizeDigits(amountCtrl.text));
                if (amount == null || amount <= 0) return;
                final notes = notesCtrl.text.trim().isEmpty ? null : notesCtrl.text.trim();
                if (existing == null) {
                  await cubit.create(
                    subscriberId: subscriberId,
                    meterId: selectedMeterId,
                    amount: amount,
                    status: selectedStatus,
                    notes: notes,
                  );
                } else {
                  await cubit.update(SubscriptionFee(
                    id: existing.id,
                    subscriberId: subscriberId,
                    meterId: selectedMeterId,
                    amount: amount,
                    status: selectedStatus,
                    invoiceId: existing.invoiceId,
                    notes: notes,
                  ));
                }
                if (ctx.mounted) Navigator.pop(ctx);
                await sub360Cubit.load(subscriberId);
              },
              child: const Text('حفظ'),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<SubscriptionFeesCubit, SubscriptionFeesState>(
      builder: (context, state) {
        return Column(children: [
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(8, 8, 8, 0),
              child: FilledButton.icon(
                icon: const Icon(Icons.add),
                label: const Text('إضافة رسوم اشتراك'),
                onPressed: () => _openFeeEditor(context),
              ),
            ),
          ),
          SumBar(items: [
            (label: 'الإجمالي', value: state.totalSum, color: Colors.white),
            (label: 'المفوتر', value: state.invoicedSum, color: AppColors.success),
            (label: 'المتبقي', value: state.remainingSum, color: AppColors.brandAmber),
          ]),
          Expanded(
            child: state.fees.isEmpty
                ? const EmptyState(message: 'لا توجد رسوم اشتراك')
                : ListView.builder(
                    itemCount: state.fees.length,
                    itemBuilder: (_, i) {
                      final f = state.fees[i];
                      return ListTile(
                        title: Text('${f.meterCode ?? 'بدون عداد'} • ${CurrencyFormatter.format(f.amount)}'),
                        subtitle: Text(f.invoiceNumber ?? (f.notes ?? '')),
                        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                          StatusBadge(
                            label: f.status.label,
                            color: f.status == FeeStatus.paid ? AppColors.success : AppColors.warning,
                          ),
                          if (f.canInvoice)
                            IconButton(
                              icon: const Icon(Icons.receipt_long, color: AppColors.primary),
                              tooltip: 'إصدار فاتورة اشتراك',
                              onPressed: () => _invoiceFee(context, f),
                            ),
                          IconButton(
                            icon: const Icon(Icons.edit_outlined, size: 20),
                            tooltip: 'تعديل الرسوم',
                            onPressed: () => _openFeeEditor(context, existing: f),
                          ),
                          if (f.invoiceId == null)
                            IconButton(
                              icon: const Icon(Icons.delete_outline, size: 20, color: AppColors.danger),
                              tooltip: 'حذف الرسوم',
                              onPressed: () async {
                                if (await confirmAction(context, 'حذف رسوم', 'حذف رسوم الاشتراك بقيمة ${f.amount}؟')) {
                                  if (context.mounted) {
                                    await context.read<SubscriptionFeesCubit>().delete(f.id);
                                    if (context.mounted) {
                                      await context.read<Subscriber360Cubit>().load(f.subscriberId);
                                    }
                                  }
                                }
                              },
                            ),
                        ]),
                      );
                    },
                  ),
          ),
        ]);
      },
    );
  }
}

// ─── التبويب 5: طلبات الوايت ───
class _RequestsTab extends StatelessWidget {
  const _RequestsTab();

  Future<void> _invoiceRequest(BuildContext context, ServiceRequest r) async {
    final reqCubit = context.read<ServiceRequestsCubit>();
    final sub360Cubit = context.read<Subscriber360Cubit>();
    final canUseFinanceSheet = GetIt.I.isRegistered<InvoicesRepository>() ||
        GetIt.I.isRegistered<DioClient>();
    if (canUseFinanceSheet) {
      await GenerateTankerInvoiceSheet.show(
        context,
        preselectedSubscriberId: '${r.subscriberId}',
      );
      if (context.mounted) {
        await reqCubit.load();
        await sub360Cubit.load(r.subscriberId);
      }
      return;
    }
    final bill = await reqCubit.invoice(r.id);
    if (context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('صدرت الفاتورة: $bill')));
    }
  }

  Future<void> _openRequestEditor(BuildContext context, {ServiceRequest? existing}) async {
    final subscriberId = context.read<Subscriber360Cubit>().state.detail?.subscriber.id;
    if (subscriberId == null) return;
    final cubit = context.read<ServiceRequestsCubit>();
    final defaultPrice = existing?.amount ?? await cubitDefaultTankerPrice(context, subscriberId);
    if (!context.mounted) return;
    final amountCtrl = TextEditingController(text: defaultPrice.toStringAsFixed(0));
    final notesCtrl = TextEditingController(text: existing?.notes ?? '');
    var selectedDate = DateTime.tryParse(existing?.serviceDate ?? '') ?? DateTime.now();
    var selectedStatus = existing?.status ?? TankerStatus.completed;

    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'إضافة طلب وايت' : 'تعديل طلب الوايت'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: amountCtrl,
                  keyboardType: TextInputType.number,
                  enabled: existing?.invoiceId == null,
                  decoration: const InputDecoration(
                    labelText: 'سعر الوايت (ريال)',
                  ),
                ),
                const SizedBox(height: 10),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('تاريخ الخدمة'),
                  subtitle: Text(selectedDate.toIso8601String().substring(0, 10)),
                  trailing: const Icon(Icons.calendar_today),
                  onTap: () async {
                    final picked = await showDatePicker(
                      context: ctx,
                      initialDate: selectedDate,
                      firstDate: DateTime(2020),
                      lastDate: DateTime(2100),
                    );
                    if (picked != null) setDialogState(() => selectedDate = picked);
                  },
                ),
                DropdownButtonFormField<TankerStatus>(
                  initialValue: selectedStatus,
                  decoration: const InputDecoration(
                    labelText: 'الحالة',
                  ),
                  items: [
                    for (final s in TankerStatus.values)
                      DropdownMenuItem(value: s, child: Text(s.label)),
                  ],
                  onChanged: (v) => setDialogState(() => selectedStatus = v ?? selectedStatus),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: notesCtrl,
                  decoration: const InputDecoration(
                    labelText: 'ملاحظات',
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
            FilledButton(
              onPressed: () async {
                final amount = double.tryParse(normalizeDigits(amountCtrl.text));
                if (amount == null || amount <= 0) return;
                final dateStr = selectedDate.toIso8601String().substring(0, 10);
                final notes = notesCtrl.text.trim().isEmpty ? null : notesCtrl.text.trim();
                if (existing == null) {
                  await cubit.create(
                    subscriberId: subscriberId,
                    amount: amount,
                    serviceDate: dateStr,
                    status: selectedStatus,
                    notes: notes,
                  );
                } else {
                  await cubit.update(ServiceRequest(
                    id: existing.id,
                    subscriberId: subscriberId,
                    amount: amount,
                    serviceDate: dateStr,
                    status: selectedStatus,
                    invoiceId: existing.invoiceId,
                    notes: notes,
                  ));
                }
                if (ctx.mounted) Navigator.pop(ctx);
              },
              child: const Text('حفظ'),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<ServiceRequestsCubit, ServiceRequestsState>(
      builder: (context, state) {
        return Column(children: [
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(8, 8, 8, 0),
              child: FilledButton.icon(
                icon: const Icon(Icons.add),
                label: const Text('إضافة طلب وايت'),
                onPressed: () => _openRequestEditor(context),
              ),
            ),
          ),
          SumBar(items: [(label: 'إجمالي طلبات هذا المشترك', value: state.totalSum, color: Colors.white)]),
          Expanded(
            child: state.requests.isEmpty
                ? const EmptyState(message: 'لا توجد طلبات وايت', icon: Icons.local_shipping)
                : ListView.builder(
                    itemCount: state.requests.length,
                    itemBuilder: (_, i) {
                      final r = state.requests[i];
                      return ListTile(
                        title: Text('${r.serviceDate} • ${CurrencyFormatter.format(r.amount)}${r.hasNote ? '  📝 يوجد ملاحظة' : ''}'),
                        subtitle: Text(r.invoiceNumber ?? (r.notes ?? '')),
                        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                          StatusBadge(
                            label: r.status.label,
                            color: r.status == TankerStatus.paid ? AppColors.success : AppColors.warning,
                          ),
                          if (r.canComplete)
                            IconButton(
                              icon: const Icon(Icons.check_circle, color: AppColors.success),
                              tooltip: 'إنجاز',
                              onPressed: () => context.read<ServiceRequestsCubit>().markCompleted(r.id),
                            ),
                          if (r.canInvoice)
                            IconButton(
                              icon: const Icon(Icons.receipt_long, color: AppColors.primary),
                              tooltip: 'إصدار فاتورة وايت',
                              onPressed: () => _invoiceRequest(context, r),
                            ),
                          IconButton(
                            icon: const Icon(Icons.edit_outlined, size: 20),
                            tooltip: 'تعديل الطلب',
                            onPressed: () => _openRequestEditor(context, existing: r),
                          ),
                          if (r.invoiceId == null)
                            IconButton(
                              icon: const Icon(Icons.delete_outline, size: 20, color: AppColors.danger),
                              tooltip: 'حذف الطلب',
                              onPressed: () async {
                                if (await confirmAction(context, 'حذف طلب', 'حذف طلب الوايت هذا؟')) {
                                  if (context.mounted) {
                                    await context.read<ServiceRequestsCubit>().delete(r.id);
                                  }
                                }
                              },
                            ),
                        ]),
                      );
                    },
                  ),
          ),
        ]);
      },
    );
  }
}

// ─── التبويب 6: كشف الحساب ───
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
        return Column(children: [
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
                        onTap: () =>
                            context.read<Subscriber360Cubit>().filterParty(party),
                      ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsetsDirectional.only(start: 4, end: 16),
                child: IconButton(
                  tooltip: 'تصدير كشف الحساب PDF',
                  onPressed: () => StatementPdfService.shareStatement(
                    subscriberName: subscriberName,
                    summary: detail.summary,
                    entries: state.statement,
                  ),
                  style: IconButton.styleFrom(
                    backgroundColor: Theme.of(context).colorScheme.surfaceAlt,
                    minimumSize: const Size(38, 38),
                  ),
                  icon: const Icon(Icons.picture_as_pdf_outlined, size: 19),
                ),
              ),
            ],
          ),
          if (state.statementLoading) const LinearProgressIndicator(),
          Expanded(
            child: state.statement.isEmpty
                ? const EmptyState(message: 'لا توجد حركات مالية ضمن هذا الفلتر', icon: Icons.account_balance)
                : ListView.builder(
                    itemCount: state.statement.length,
                    itemBuilder: (_, i) {
                      final e = state.statement[i];
                      return ListTile(
                        leading: Icon(
                          e.isCredit ? Icons.arrow_downward : Icons.arrow_upward,
                          color: e.isCredit ? AppColors.success : AppColors.danger,
                        ),
                        title: Text(e.description ?? ''),
                        subtitle: Text('${e.createdAt ?? ''} • ${e.party} • رصيد تراكمي: ${e.runningBalance}'),
                        trailing: BalanceText(e.amount),
                      );
                    },
                  ),
          ),
        ]);
      },
    );
  }
}

/// السعر الافتراضي لطلب الوايت داخل 360: آخر طلب للمشترك أو 5000.
Future<double> cubitDefaultTankerPrice(BuildContext context, int subscriberId) async {
  final requests = context.read<ServiceRequestsCubit>().state.requests;
  return requests.isEmpty ? 5000 : requests.first.amount;
}

/// اختصار نوعي لبيانات 360 داخل التبويبات.
typedef Subscriber360View = Subscriber360;
