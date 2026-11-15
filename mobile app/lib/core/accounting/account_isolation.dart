/// أدوات العزل المالي — تنفيذاً للقاعدة 1 في §3 من أمر Dev 3 و§1.3 القاعدة 1
/// في الخطة، المطابقة لـ `TransactionService::updateMeterBalance/updateSubscriberBalance`.
///
/// مصدر الحقيقة في Laravel:
///  - `Invoice::getTrueRemainingAmount()` group by: `subscriber_id + invoice_type (+ meter_id للمتر)`.
///  - `PaymentService::distributePayment()` group by: `subscriber_id + (meter_id ?? NULL)`.
/// لذلك يوجد مفتاحان مختلفان — الخلط بينهما يُنتج مضاعفة المتأخرات أو تسديداً لحساب خاطئ.
class AccountIsolation {
  AccountIsolation._();

  /// مفتاح مجموعة المتأخرات (تجميع الفواتير لنفس الدفتر التراكمي).
  static String arrearsGroupKey({
    required int subscriberId,
    required String invoiceType,
    int? meterId,
  }) =>
      invoiceType == 'meter' && meterId != null
          ? 's$subscriberId:$invoiceType:m$meterId'
          : 's$subscriberId:$invoiceType';

  /// مفتاح مجموعة توزيع الدفعات (FIFO): العداد إن وُجد، وإلا الحساب العام للمشترك.
  /// ملاحظة: لا دخل لـ `invoice_type` هنا — فواتير الاشتراك تتشارك الحساب
  /// العام للمشترك (`meter_id == null`) تماماً كما في `distributePayment`.
  static String allocationGroupKey({
    required int subscriberId,
    int? meterId,
  }) =>
      's$subscriberId:${meterId == null ? 'general' : 'm$meterId'}';

  /// هل تنتمي هذه الفاتورة إلى نفس حساب توزيع الدفعة؟
  static bool sameAllocationAccount({
    required int? paymentMeterId,
    required int? invoiceMeterId,
  }) =>
      paymentMeterId == invoiceMeterId;

  /// الرصيد الذي تُحدّثه هذه الفاتورة/السند: رصيد العداد أو الرصيد العام للمشترك.
  static AccountKind kindFor({required int? meterId}) =>
      meterId == null ? AccountKind.subscriberGeneral : AccountKind.meter;
}

enum AccountKind {
  /// `meters.balance` — فواتير/سندات `meter_id != null`.
  meter,

  /// `subscribers.balance` — فواتير/سندات `meter_id == null` (اشتراك).
  subscriberGeneral;

  String get label => switch (this) {
        AccountKind.meter => 'رصيد العداد',
        AccountKind.subscriberGeneral => 'الرصيد العام للمشترك',
      };
}
