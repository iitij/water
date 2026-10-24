import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/finance/finance_locator.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../cash_sessions/services/cash_session_service.dart';
import '../../../invoices/data/datasources/references_remote_datasource.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../domain/repositories/payments_repository.dart';
import '../cubit/collect_payment_cubit.dart';
import '../widgets/collect_payment_form.dart';

/// `CollectPaymentSheet` — ودجة التحصيل المشتركة (تسليم Dev 3 → Dev 4/Dev 5).
///
/// ```dart
/// final saved = await CollectPaymentSheet.show(context, invoice: invoice);
/// ```
///
/// تعيد `true` عند نجاح التوزيع على الخادم أو عند إدراج العملية في طابور المزامنة
/// (وضع الأوفلاين). الشاشة الكاملة `SCR-18` تستعمل نفس [CollectPaymentForm] مع
/// نفس الـ Cubit، فتتطابق الحقول المشروطة في المكانين:
///  - `reference_number` يظهر ويُلزم فقط مع `bank_transfer`/`check`؛
///  - `reason` يُرسل كما هو (يظهر في السند خلف «وذلك مقابل»)؛
///  - معاينة توزيع FIFO على الحساب المعزول والفائض كرصيد دائن.
class CollectPaymentSheet extends StatelessWidget {
  const CollectPaymentSheet({super.key, this.invoice, this.subscriber, this.meterId});

  final InvoiceModel? invoice;
  final SubscriberOption? subscriber;
  final int? meterId;

  static Future<bool> show(
    BuildContext context, {
    InvoiceModel? invoice,
    SubscriberOption? subscriber,
    int? meterId,
  }) async {
    if (invoice == null && subscriber == null) {
      throw ArgumentError('يجب تمرير invoice أو subscriber لفتح نافذة التحصيل.');
    }

    final cubit = CollectPaymentCubit(
      paymentsRepository: sl<PaymentsRepository>(),
      references: sl<ReferencesRemoteDataSource>(),
      meterId: meterId ?? invoice?.meterId,
      cashSessionService: trySl<CashSessionService>(),
    );

    if (invoice != null) {
      unawaited(cubit.openForInvoice(invoice));
    } else {
      unawaited(cubit.openForSubscriber(subscriber!.id, name: subscriber.name));
    }

    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (sheetContext) => BlocProvider<CollectPaymentCubit>.value(
        value: cubit,
        child: CollectPaymentSheet(invoice: invoice, subscriber: subscriber),
      ),
    ).whenComplete(cubit.close);

    return saved ?? false;
  }

  @override
  Widget build(BuildContext context) {
    final invoice = this.invoice;

    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.9,
      builder: (sheetContext, controller) => ColoredBox(
        color: Theme.of(sheetContext).scaffoldBackgroundColor,
        child: Column(
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 8, 6),
              child: Row(
                children: <Widget>[
                  const Icon(Icons.payments_outlined, color: AppColors.success),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      invoice == null ? 'تحصيل دفعة' : 'تحصيل فاتورة ${invoice.billNumber}',
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
                    ),
                  ),
                  IconButton(
                    onPressed: () => Navigator.of(sheetContext).pop(false),
                    icon: const Icon(Icons.close),
                  ),
                ],
              ),
            ),
            const Divider(height: 1),
            Expanded(
              child: CollectPaymentForm(
                invoice: invoice,
                compact: true,
                scrollController: controller,
                onSubmitted: (saved) {
                  if (!sheetContext.mounted) return;
                  Navigator.of(sheetContext).pop(saved);
                },
              ),
            ),
          ],
        ),
      ),
    );
  }
}
