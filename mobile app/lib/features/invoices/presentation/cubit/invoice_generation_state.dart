import 'package:equatable/equatable.dart';

import '../../../../core/utils/currency_formatter.dart';
import '../../data/datasources/references_remote_datasource.dart';
import '../../data/models/invoice_model.dart';

/// حالة نوافذ إصدار الفواتير الثلاث (`SCR-16`) — تعرض الحساب الحي قبل الإرسال
/// وتطابق قواعد `InvoiceService::generateInvoice`.
class InvoiceGenerationState extends Equatable {
  const InvoiceGenerationState({
    this.loadingReferences = false,
    this.squares = const <SquareOption>[],
    this.subscribers = const <SubscriberOption>[],
    this.selectedSubscriber,
    this.selectedMeter,
    this.includeReadings = true,
    this.hasUnpaidSubscriptionFee = false,
    this.hasSubscriptionFee = false,
    this.subscriptionInstallment = 0,
    this.notes,
    this.uninvoicedUnits = 0,
    this.unitPrice = 0,
    this.availableCredit = 0,
    this.previousBalance = 0,
    this.submitting = false,
    this.createdInvoice,
    this.errorMessage,
  });

  final bool loadingReferences;
  final List<SquareOption> squares;
  final List<SubscriberOption> subscribers;

  final SubscriberOption? selectedSubscriber;
  final MeterOption? selectedMeter;

  final bool includeReadings;
  final bool hasUnpaidSubscriptionFee;
  final bool hasSubscriptionFee;
  final double subscriptionInstallment;
  final String? notes;

  /// قراءات غير مفوترة للعداد المحدد (للمعاينة الفورية).
  final double uninvoicedUnits;
  final double unitPrice;

  /// الرصيد الدائن المتاح في الحساب المعزول المستهدف.
  final double availableCredit;

  /// إجمالي متأخرات الفواتير المفتوحة لنفس الحساب (سيُخزَّن في `previous_balance`).
  final double previousBalance;

  final bool submitting;
  final InvoiceModel? createdInvoice;
  final String? errorMessage;

  // ─── الحساب الحي (يطابق `InvoiceService` سطر 92-108) ────────────────────
  double get consumptionFee => uninvoicedUnits * unitPrice;

  double get subtotal =>
      (includeReadings ? consumptionFee : 0) +
      (hasSubscriptionFee ? subscriptionInstallment : 0);

  double get creditApplied {
    if (availableCredit <= 0 || subtotal <= 0) return 0;
    return availableCredit < subtotal ? availableCredit : subtotal;
  }

  double get netTotal => subtotal - creditApplied;

  double get totalDue => netTotal + previousBalance;

  bool get canSubmit =>
      !submitting &&
      selectedSubscriber != null &&
      (selectedMeter != null ||
          hasSubscriptionFee ||
          subscriptionInstallment > 0);

  /// رسالة المنع نفسها التي يرميها Laravel عند عدم وجود ما يُفوتر.
  String? get blockingReason {
    if (selectedSubscriber == null) return 'اختر المشترك أولاً.';
    if (subtotal <= 0) {
      return 'لا توجد قراءات جديدة أو أقساط اشتراك مستحقة لإصدار فاتورة جديدة.';
    }
    return null;
  }

  String get summaryLabel => 'صافي الفاتورة ${CurrencyFormatter.withLabel(netTotal)}';

  InvoiceGenerationState copyWith({
    bool? loadingReferences,
    List<SquareOption>? squares,
    List<SubscriberOption>? subscribers,
    SubscriberOption? selectedSubscriber,
    bool clearSubscriber = false,
    MeterOption? selectedMeter,
    bool clearMeter = false,
    bool? includeReadings,
    bool? hasUnpaidSubscriptionFee,
    bool? hasSubscriptionFee,
    double? subscriptionInstallment,
    String? notes,
    double? uninvoicedUnits,
    double? unitPrice,
    double? availableCredit,
    double? previousBalance,
    bool? submitting,
    InvoiceModel? createdInvoice,
    String? errorMessage,
    bool clearError = false,
  }) =>
      InvoiceGenerationState(
        loadingReferences: loadingReferences ?? this.loadingReferences,
        squares: squares ?? this.squares,
        subscribers: subscribers ?? this.subscribers,
        selectedSubscriber: clearSubscriber
            ? null
            : (selectedSubscriber ?? this.selectedSubscriber),
        selectedMeter: clearMeter ? null : (selectedMeter ?? this.selectedMeter),
        includeReadings: includeReadings ?? this.includeReadings,
        hasUnpaidSubscriptionFee:
            hasUnpaidSubscriptionFee ?? this.hasUnpaidSubscriptionFee,
        hasSubscriptionFee: hasSubscriptionFee ?? this.hasSubscriptionFee,
        subscriptionInstallment:
            subscriptionInstallment ?? this.subscriptionInstallment,
        notes: notes ?? this.notes,
        uninvoicedUnits: uninvoicedUnits ?? this.uninvoicedUnits,
        unitPrice: unitPrice ?? this.unitPrice,
        availableCredit: availableCredit ?? this.availableCredit,
        previousBalance: previousBalance ?? this.previousBalance,
        submitting: submitting ?? this.submitting,
        createdInvoice: createdInvoice ?? this.createdInvoice,
        errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
      );

  @override
  List<Object?> get props => <Object?>[
        loadingReferences,
        squares,
        subscribers,
        selectedSubscriber,
        selectedMeter,
        includeReadings,
        hasSubscriptionFee,
        subscriptionInstallment,
        notes,
        uninvoicedUnits,
        unitPrice,
        availableCredit,
        previousBalance,
        submitting,
        createdInvoice,
        errorMessage,
      ];
}
