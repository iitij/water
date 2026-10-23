import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/network/dio_client.dart';
import '../../../../core/widgets/app_numeric_field.dart';
import '../../../../core/widgets/app_text_field.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../../invoices/domain/repositories/invoices_repository.dart';
import '../../../invoices/presentation/sheets/generate_invoice_sheets.dart';
import '../../../meters/data/models/meter.dart';
import '../../../meters/domain/meters_repository.dart';
import '../../../meters/presentation/pages/meter_form_screen.dart';
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

/// الملف المالي الشامل 360°: ملخص معزول + 6 تبويبات.
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
                ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
                : detail == null
                    ? EmptyState(message: state.error ?? 'تعذر تحميل الملف')
                    : Column(children: [
                        _FinancialSummaryHeader(summary: detail.summary),
                        Expanded(
                          child: TabBarView(children: [
                            _MetersTab(detail: detail, onRecordReading: onRecordReading, onAddMeter: onAddMeter),
                            _InvoicesTab(
                              invoices: detail.invoices,
                              onCollect: () => _collect(context, activeSubscriber),
                            ),
                            _PaymentsTab(payments: detail.payments),
                            const _FeesTab(),
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
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AppColors.primaryDark, AppColors.primary, AppColors.secondary],
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
        ),
        borderRadius: BorderRadius.circular(22),
        boxShadow: <BoxShadow>[
          BoxShadow(
            color: AppColors.primaryDark.withValues(alpha: .2),
            blurRadius: 24,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Wrap(spacing: 16, runSpacing: 10, children: [
          _SummaryChip(label: 'إجمالي دائن (عام + عدادات)', value: summary.totalCredit, color: AppColors.success),
          _SummaryChip(label: 'إجمالي مدين / متأخرات', value: -summary.totalDebit, color: AppColors.danger),
          _SummaryChip(label: 'الرصيد العام للمشترك (اشتراك/وايت)', value: summary.generalBalance, color: AppColors.secondary),
          _SummaryChip(label: 'متبقي رسوم اشتراك غير مفوترة', value: -summary.remainingSubscriptionFee, color: AppColors.brandAmber),
        ]),
        if (summary.meterBalances.isNotEmpty) ...[
          const SizedBox(height: 12),
          const Text('أرصدة العدادات المعزولة:', style: TextStyle(color: Colors.white70)),
          const SizedBox(height: 6),
          Wrap(spacing: 8, runSpacing: 6, children: [
            for (final m in summary.meterBalances)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: .14),
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(color: Colors.white.withValues(alpha: .2)),
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  const Icon(Icons.speed, size: 14, color: Colors.white),
                  const SizedBox(width: 5),
                  Flexible(
                    child: Text('${m.title} | دائن ${m.credit.toStringAsFixed(0)} / مدين ${m.debit.toStringAsFixed(0)}',
                        style: const TextStyle(color: Colors.white, fontSize: 11)),
                  ),
                ]),
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
              style: TextStyle(color: color, fontSize: 16, fontWeight: FontWeight.w800)),
        ),
      ]);
}

// ─── التبويب 1: العدادات ───
class _MetersTab extends StatelessWidget {
  const _MetersTab({required this.detail, this.onRecordReading, this.onAddMeter});
  final Subscriber360View detail;
  final RecordReadingLauncher? onRecordReading;
  final AddMeterLauncher? onAddMeter;

  Future<void> _defaultAddMeter(BuildContext context, Subscriber sub) async {
    if (onAddMeter != null) {
      onAddMeter!(context, sub);
      return;
    }
    if (GetIt.I.isRegistered<MetersRepository>()) {
      await Navigator.of(context).push<void>(
        MaterialPageRoute<void>(
          builder: (_) => MeterFormScreen(repository: GetIt.I<MetersRepository>()),
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
        await context.push('/meters/new?subscriber_id=${sub.id}');
        if (context.mounted) {
          await context.read<Subscriber360Cubit>().load(sub.id);
        }
      } catch (_) {}
    }
  }

  Future<void> _defaultRecordReading(BuildContext context, MeterBalance m) async {
    if (onRecordReading != null) {
      onRecordReading!(context, m);
      return;
    }
    if (GetIt.I.isRegistered<ReadingsRepository>()) {
      final meterModel = Meter(
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
      await RecordReadingSheet.show(
        context,
        meter: meterModel,
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
    final canAddMeter = onAddMeter != null ||
        GetIt.I.isRegistered<MetersRepository>() ||
        GoRouter.maybeOf(context) != null;
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
            icon: const Icon(Icons.add_rounded),
            label: const Text('إضافة عداد للمشترك'),
          ),
        ),
      ),
      Expanded(
        child: meters.isEmpty
            ? const EmptyState(message: 'لا توجد عدادات لهذا المشترك', icon: Icons.speed)
            : ListView.builder(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                itemCount: meters.length,
                itemBuilder: (_, i) {
                  final m = meters[i];
                  return AdminCard(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Container(
                          width: 42,
                          height: 42,
                          decoration: BoxDecoration(
                            color: AppColors.primary.withValues(alpha: .12),
                            borderRadius: BorderRadius.circular(13),
                          ),
                          child: const Icon(Icons.speed, color: AppColors.primary, size: 21),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(m.title),
                              const SizedBox(height: 3),
                              Text('مسار: ${m.pathNumber ?? '-'} • قراءات: ${m.readingsCount} • ${m.status == 'active' ? 'نشط' : 'غير نشط'}',
                                  style: TextStyle(fontSize: 12, color: AppColors.neutral)),
                            ],
                          ),
                        ),
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.end,
                          children: [
                            Text('دائن ${m.credit.toStringAsFixed(0)}', style: const TextStyle(color: AppColors.success, fontSize: 12)),
                            Text('مدين ${m.debit.toStringAsFixed(0)}', style: const TextStyle(color: AppColors.danger, fontSize: 12)),
                          ],
                        ),
                        IconButton(
                          icon: const Icon(Icons.edit_note_rounded, color: AppColors.info),
                          tooltip: 'تسجيل قراءة سريعة',
                          onPressed: canRecordReading ? () => _defaultRecordReading(context, m) : null,
                        ),
                      ],
                    ),
                  );
                },
              ),
      ),
    ]);
  }
}

// ─── التبويب 2: الفواتير ───
class _InvoicesTab extends StatelessWidget {
  const _InvoicesTab({required this.invoices, this.onCollect});
  final List<Map<String, dynamic>> invoices;
  final VoidCallback? onCollect;
  @override
  Widget build(BuildContext context) {
    if (invoices.isEmpty) return const EmptyState(message: 'لا توجد فواتير', icon: Icons.receipt_long);
    return ListView.builder(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      itemCount: invoices.length,
      itemBuilder: (_, i) {
        final inv = invoices[i];
        final total = (inv['total_amount'] as num?)?.toDouble() ?? 0;
        final paid = (inv['amount_paid'] as num?)?.toDouble() ?? 0;
        final isPaid = inv['status'] == 'paid';
        return AdminCard(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: (isPaid ? AppColors.success : AppColors.warning).withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(13),
                ),
                child: Icon(Icons.receipt_long_rounded, color: isPaid ? AppColors.success : AppColors.warning, size: 21),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${inv['bill_number'] ?? ''} • ${inv['bill_date'] ?? ''}'),
                    const SizedBox(height: 3),
                    Text('الإجمالي: ${CurrencyFormatter.format(total)} • المسدد: ${CurrencyFormatter.format(paid)}',
                        style: TextStyle(fontSize: 12, color: AppColors.neutral)),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  StatusBadge(label: '${inv['status'] ?? ''}', color: isPaid ? AppColors.success : AppColors.warning),
                  if (!isPaid && onCollect != null)
                    IconButton(
                      icon: const Icon(Icons.payments_outlined, color: AppColors.success),
                      tooltip: 'تحصيل الفاتورة',
                      onPressed: onCollect,
                    ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }
}

// ─── التبويب 3: المدفوعات ───
class _PaymentsTab extends StatelessWidget {
  const _PaymentsTab({required this.payments});
  final List<Map<String, dynamic>> payments;
  @override
  Widget build(BuildContext context) {
    if (payments.isEmpty) return const EmptyState(message: 'لا توجد مدفوعات', icon: Icons.payments);
    return ListView.builder(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      itemCount: payments.length,
      itemBuilder: (_, i) {
        final p = payments[i];
        return AdminCard(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: AppColors.success.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(13),
                ),
                child: const Icon(Icons.receipt_rounded, color: AppColors.success, size: 21),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${p['receipt_number'] ?? ''} • ${p['payment_date'] ?? ''}'),
                    const SizedBox(height: 3),
                    Text('${p['payment_method'] ?? 'cash'}', style: TextStyle(fontSize: 12, color: AppColors.neutral)),
                  ],
                ),
              ),
              Text(CurrencyFormatter.format((p['amount'] as num?)?.toDouble() ?? 0),
                  style: const TextStyle(color: AppColors.success, fontWeight: FontWeight.w800)),
            ],
          ),
        );
      },
    );
  }
}

// ─── التبويب 4: رسوم الاشتراك ───
class _FeesTab extends StatelessWidget {
  const _FeesTab();

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
                icon: const Icon(Icons.add_rounded),
                label: const Text('إضافة رسوم اشتراك'),
                onPressed: () => _addFee(context),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
            child: SumBar(items: [
              (label: 'الإجمالي', value: state.totalSum, color: AppColors.primaryDark),
              (label: 'المفوتر', value: state.invoicedSum, color: AppColors.success),
              (label: 'المتبقي', value: state.remainingSum, color: AppColors.brandAmber),
            ]),
          ),
          Expanded(
            child: state.fees.isEmpty
                ? const EmptyState(message: 'لا توجد رسوم اشتراك')
                : ListView.builder(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    itemCount: state.fees.length,
                    itemBuilder: (_, i) {
                      final f = state.fees[i];
                      return AdminCard(
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text('${f.meterCode ?? 'بدون عداد'} • ${CurrencyFormatter.format(f.amount)}'),
                                  const SizedBox(height: 3),
                                  Text(f.invoiceNumber ?? '', style: TextStyle(fontSize: 12, color: AppColors.neutral)),
                                ],
                              ),
                            ),
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: [
                                StatusBadge(label: f.status.label, color: f.status == FeeStatus.paid ? AppColors.success : AppColors.warning),
                                if (f.canInvoice)
                                  IconButton(
                                    icon: const Icon(Icons.receipt_long_rounded, color: AppColors.primary),
                                    tooltip: 'إصدار فاتورة اشتراك',
                                    onPressed: () => _invoiceFee(context, f),
                                  ),
                              ],
                            ),
                          ],
                        ),
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
                icon: const Icon(Icons.add_rounded),
                label: const Text('إضافة طلب وايت'),
                onPressed: () => _addRequest(context),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
            child: SumBar(items: [(label: 'إجمالي طلبات هذا المشترك', value: state.totalSum, color: AppColors.primaryDark)]),
          ),
          Expanded(
            child: state.requests.isEmpty
                ? const EmptyState(message: 'لا توجد طلبات وايت', icon: Icons.local_shipping)
                : ListView.builder(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    itemCount: state.requests.length,
                    itemBuilder: (_, i) {
                      final r = state.requests[i];
                      return AdminCard(
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text('${r.serviceDate} • ${CurrencyFormatter.format(r.amount)}${r.hasNote ? '  📝 يوجد ملاحظة' : ''}'),
                                  const SizedBox(height: 3),
                                  Text(r.invoiceNumber ?? (r.notes ?? ''), style: TextStyle(fontSize: 12, color: AppColors.neutral)),
                                ],
                              ),
                            ),
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: [
                                StatusBadge(label: r.status.label, color: r.status == TankerStatus.paid ? AppColors.success : AppColors.warning),
                                const SizedBox(height: 6),
                                Wrap(
                                  spacing: 4,
                                  runSpacing: 4,
                                  crossAxisAlignment: WrapCrossAlignment.center,
                                  children: [
                                    if (r.canComplete)
                                      IconButton(
                                        icon: const Icon(Icons.check_circle_rounded, size: 20, color: AppColors.success),
                                        tooltip: 'إنجاز',
                                        onPressed: () => context.read<ServiceRequestsCubit>().markCompleted(r.id),
                                      ),
                                    if (r.canInvoice)
                                      IconButton(
                                        icon: const Icon(Icons.receipt_long_rounded, size: 20, color: AppColors.primary),
                                        tooltip: 'إصدار فاتورة وايت',
                                        onPressed: () => _invoiceRequest(context, r),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ],
                        ),
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
          Padding(
            padding: const EdgeInsets.all(12),
            child: Row(children: [
              Expanded(
                child: DropdownButtonFormField<PartyFilter>(
                  value: state.party,
                  isDense: true,
                  decoration: InputDecoration(
                    filled: true,
                    contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(16)),
                    enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16)),
                    focusedBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(16),
                      borderSide: const BorderSide(color: AppColors.primary, width: 1.7),
                    ),
                    labelText: 'فلتر الجهة (الحساب)',
                    labelStyle: const TextStyle(color: AppColors.neutral),
                  ),
                  items: [for (final p in parties) DropdownMenuItem(value: p, child: Text(p.label))],
                  onChanged: (p) { if (p != null) context.read<Subscriber360Cubit>().filterParty(p); },
                ),
              ),
              const SizedBox(width: 8),
              IconButton.filled(
                tooltip: 'تصدير / مشاركة كشف الحساب PDF',
                icon: const Icon(Icons.picture_as_pdf_rounded),
                onPressed: () => StatementPdfService.shareStatement(
                  subscriberName: subscriberName,
                  summary: detail.summary,
                  entries: state.statement,
                ),
              ),
            ]),
          ),
          Expanded(
            child: state.statementLoading
                ? ListView(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    children: List.generate(8, (_) => ShimmerSkeleton(height: 84)),
                  )
                : state.statement.isEmpty
                    ? const EmptyState(message: 'لا توجد حركات مالية ضمن هذا الفلتر', icon: Icons.account_balance)
                    : ListView.builder(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        itemCount: state.statement.length,
                        itemBuilder: (_, i) {
                          final e = state.statement[i];
                          return AdminCard(
                            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Container(
                                  width: 42,
                                  height: 42,
                                  decoration: BoxDecoration(
                                    color: (e.isCredit ? AppColors.success : AppColors.danger).withValues(alpha: .12),
                                    borderRadius: BorderRadius.circular(13),
                                  ),
                                  child: Icon(e.isCredit ? Icons.arrow_downward_rounded : Icons.arrow_upward_rounded,
                                      color: e.isCredit ? AppColors.success : AppColors.danger, size: 21),
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(e.description ?? ''),
                                      const SizedBox(height: 3),
                                      Text('${e.createdAt ?? ''} • ${e.party} • رصيد تراكمي: ${e.runningBalance}',
                                          style: TextStyle(fontSize: 12, color: AppColors.neutral)),
                                    ],
                                  ),
                                ),
                                BalanceText(e.amount),
                              ],
                            ),
                          );
                        },
                      ),
          ),
        ]);
      },
    );
  }
}

Future<void> _addFee(BuildContext context) async {
  final subscriberId = context.read<Subscriber360Cubit>().state.detail?.subscriber.id;
  if (subscriberId == null) return;
  final amountCtrl = TextEditingController();
  final cubit = context.read<SubscriptionFeesCubit>();
  await showDialog<void>(
    context: context,
    builder: (ctx) => AlertDialog(
      icon: const Icon(Icons.card_membership_rounded, size: 32, color: AppColors.primary),
      title: const Text('إضافة رسوم اشتراك', style: TextStyle(fontWeight: FontWeight.w800)),
      content: AppNumericField(controller: amountCtrl, label: 'المبلغ (ريال)', suffixText: 'ريال', autofocus: true),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        FilledButton(
          onPressed: () {
            final amount = double.tryParse(normalizeDigits(amountCtrl.text));
            if (amount == null || amount <= 0) return;
            cubit.create(subscriberId: subscriberId, amount: amount);
            Navigator.pop(ctx);
          },
          child: const Text('إضافة'),
        ),
      ],
    ),
  );
}

Future<void> _addRequest(BuildContext context) async {
  final subscriberId = context.read<Subscriber360Cubit>().state.detail?.subscriber.id;
  if (subscriberId == null) return;
  final cubit = context.read<ServiceRequestsCubit>();
  final defaultPrice = await cubitDefaultTankerPrice(context, subscriberId);
  if (!context.mounted) return;
  final amountCtrl = TextEditingController(text: defaultPrice.toStringAsFixed(0));
  await showDialog<void>(
    context: context,
    builder: (ctx) => AlertDialog(
      icon: const Icon(Icons.local_shipping_rounded, size: 32, color: AppColors.info),
      title: const Text('إضافة طلب وايت', style: TextStyle(fontWeight: FontWeight.w800)),
      content: AppNumericField(controller: amountCtrl, label: 'سعر الوايت (ريال)', suffixText: 'ريال', autofocus: true),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        FilledButton(
          onPressed: () {
            final amount = double.tryParse(normalizeDigits(amountCtrl.text));
            if (amount == null || amount <= 0) return;
            cubit.create(subscriberId: subscriberId, amount: amount);
            Navigator.pop(ctx);
          },
          child: const Text('إضافة'),
        ),
      ],
    ),
  );
}

/// السعر الافتراضي لطلب الوايت داخل 360: آخر طلب للمشترك أو 5000.
Future<double> cubitDefaultTankerPrice(BuildContext context, int subscriberId) async {
  // يُحسب من قائمة طلبات المشترك الحالية في الكيوبت (الأحدث أولاً كما يرتبها الخادم).
  final requests = context.read<ServiceRequestsCubit>().state.requests;
  return requests.isEmpty ? 5000 : requests.first.amount;
}

/// اختصار نوعي لبيانات 360 داخل التبويبات.
typedef Subscriber360View = Subscriber360;
