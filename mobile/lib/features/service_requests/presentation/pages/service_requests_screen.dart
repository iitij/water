import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/widgets/common_widgets.dart';
import '../../domain/entities/service_request.dart';
import '../cubit/service_requests_cubit.dart';

/// SCR-20 — طلبات الوايت: Sum Summarizer + فلتر حالة/تاريخ (created_from..until)
/// + شارة 📝 + زر إنجاز (mark_completed) + إصدار فاتورة وايت.
class ServiceRequestsScreen extends StatelessWidget {
  const ServiceRequestsScreen({super.key, this.onAddRequest});
  final VoidCallback? onAddRequest;

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<ServiceRequestsCubit, ServiceRequestsState>(
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(title: const Text('SCR-20 • طلبات الوايت')),
          floatingActionButton: onAddRequest == null
              ? null
              : FloatingActionButton.extended(
                  onPressed: onAddRequest,
                  icon: const Icon(Icons.add),
                  label: const Text('طلب وايت جديد'),
                ),
          body: Column(children: [
            Padding(
              padding: const EdgeInsets.all(12),
              child: SumBar(items: [
                (label: 'إجمالي مبالغ الطلبات المعروضة', value: state.totalSum, color: Colors.white),
              ]),
            ),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Row(children: [
                ChoiceChip(label: const Text('الكل'), selected: state.status == null, onSelected: (_) => context.read<ServiceRequestsCubit>().filterStatus(null)),
                for (final s in TankerStatus.values)
                  Padding(
                    padding: const EdgeInsetsDirectional.only(start: 8),
                    child: ChoiceChip(label: Text(s.label), selected: state.status == s, onSelected: (_) => context.read<ServiceRequestsCubit>().filterStatus(s)),
                  ),
                const SizedBox(width: 8),
                OutlinedButton.icon(
                  icon: const Icon(Icons.date_range),
                  label: Text(state.from == null ? 'نطاق التاريخ' : '${state.from} ← ${state.until}'),
                  onPressed: () => _pickRange(context, state),
                ),
                if (state.from != null)
                  IconButton(icon: const Icon(Icons.clear), onPressed: () => context.read<ServiceRequestsCubit>().filterRange(null, null)),
              ]),
            ),
            if (state.loading) const LinearProgressIndicator(),
            Expanded(
              child: state.requests.isEmpty && !state.loading
                  ? const EmptyState(message: 'لا توجد طلبات وايت مطابقة', icon: Icons.local_shipping)
                  : ListView.builder(
                      itemCount: state.requests.length,
                      itemBuilder: (_, i) {
                        final r = state.requests[i];
                        return Card(
                          margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                          child: ListTile(
                            leading: const Icon(Icons.local_shipping, color: AppColors.info),
                            title: Text('${r.subscriberName ?? 'مشترك #${r.subscriberId}'}${r.hasNote ? '  📝 يوجد ملاحظة' : ''}'),
                            subtitle: Text('${r.serviceDate} • ${CurrencyFormatter.format(r.amount)}${r.invoiceNumber != null ? ' • فاتورة: ${r.invoiceNumber}' : ''}${r.hasNote ? '\n${r.notes}' : ''}'),
                            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                              StatusBadge(
                                label: r.status.label,
                                color: switch (r.status) {
                                  TankerStatus.pending => AppColors.warning,
                                  TankerStatus.completed => AppColors.info,
                                  TankerStatus.invoiced => AppColors.primary,
                                  TankerStatus.paid => AppColors.success,
                                },
                              ),
                              if (r.canComplete)
                                IconButton(
                                  icon: const Icon(Icons.check_circle, color: AppColors.success),
                                  tooltip: 'إنجاز (mark_completed)',
                                  onPressed: () async {
                                    if (await confirmAction(context, 'إنجاز الطلب', 'تأكيد إنجاز طلب الوايت بقيمة ${r.amount}؟')) {
                                      context.read<ServiceRequestsCubit>().markCompleted(r.id);
                                    }
                                  },
                                ),
                              if (r.canInvoice)
                                IconButton(
                                  icon: const Icon(Icons.receipt_long, color: AppColors.primary),
                                  tooltip: 'إصدار فاتورة وايت',
                                  onPressed: () async {
                                    final bill = await context.read<ServiceRequestsCubit>().invoice(r.id);
                                    if (context.mounted) {
                                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('صدرت فاتورة الوايت: $bill')));
                                    }
                                  },
                                ),
                              IconButton(
                                icon: const Icon(Icons.delete_outline, color: AppColors.danger),
                                onPressed: () async {
                                  if (await confirmAction(context, 'حذف طلب', 'حذف طلب الوايت هذا؟')) {
                                    context.read<ServiceRequestsCubit>().delete(r.id);
                                  }
                                },
                              ),
                            ]),
                          ),
                        );
                      },
                    ),
            ),
          ]),
        );
      },
    );
  }

  Future<void> _pickRange(BuildContext context, ServiceRequestsState state) async {
    final range = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2020),
      lastDate: DateTime(2100),
    );
    if (range != null && context.mounted) {
      context.read<ServiceRequestsCubit>().filterRange(
            range.start.toIso8601String().substring(0, 10),
            range.end.toIso8601String().substring(0, 10),
          );
    }
  }
}
