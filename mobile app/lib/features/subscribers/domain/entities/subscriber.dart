import 'package:equatable/equatable.dart';

enum SubscriberStatus { active, inactive, suspended }

extension SubscriberStatusX on SubscriberStatus {
  String get apiValue => name;
  String get label => switch (this) {
    SubscriberStatus.active => 'نشط',
    SubscriberStatus.inactive => 'غير نشط',
    SubscriberStatus.suspended => 'موقوف',
  };
  static SubscriberStatus fromApi(String? v) => switch (v) {
    'inactive' => SubscriberStatus.inactive,
    'suspended' => SubscriberStatus.suspended,
    _ => SubscriberStatus.active,
  };
}

class Subscriber extends Equatable {
  const Subscriber({
    required this.id,
    required this.name,
    this.phone,
    this.address,
    this.squareId,
    this.squareName,
    this.status = SubscriberStatus.active,
    this.subscriptionStartDate,
    this.metersCount = 0,
    this.creditBalance = 0,
    this.debitBalance = 0,
    this.generalBalance = 0,
    this.meterNumber,
    this.pathNumbers = const [],
    this.createdAt,
  });

  final int id;
  final String name;
  final String? phone;
  final String? address;
  final int? squareId;
  final String? squareName;
  final SubscriberStatus status;
  final String? subscriptionStartDate;
  final int metersCount;

  /// رصيد عام + عدادات: موجب = دائن (له)، سالب = مدين (عليه). قاعدة العزل (الخطة 1.3/1).
  final double creditBalance;
  final double debitBalance;
  final double generalBalance;
  final String? meterNumber;
  final List<int> pathNumbers;
  final DateTime? createdAt;

  /// Multiple meters can belong to one subscriber; use the first numeric path.
  int? get firstPathNumber =>
      pathNumbers.isEmpty ? null : pathNumbers.reduce((a, b) => a < b ? a : b);

  /// الرصيد الصافي الملون: أخضر إن كان دائناً، أحمر إن كان مديناً.
  double get netBalance => creditBalance - debitBalance;

  @override
  List<Object?> get props => [
    id,
    name,
    phone,
    squareId,
    status,
    creditBalance,
    debitBalance,
    pathNumbers,
    createdAt,
  ];
}

/// رصيد عداد معزول داخل ملف 360°.
class MeterBalance extends Equatable {
  const MeterBalance({
    required this.meterId,
    required this.uniqueCode,
    this.tenantName,
    this.credit = 0,
    this.debit = 0,
    this.readingsCount = 0,
    this.status = 'active',
    this.pathNumber,
  });
  final int meterId;
  final String uniqueCode;
  final String? tenantName;
  final double credit;
  final double debit;
  final int readingsCount;
  final String status;
  final int? pathNumber;
  String get title =>
      'عداد رقم: $meterId ($uniqueCode) - ${tenantName ?? 'بدون مستأجر'}';
  @override
  List<Object?> get props => [meterId, credit, debit];
}

/// الملخص المالي المعزول لملف 360° (عام المشترك منفصل عن كل عداد).
class FinancialSummary extends Equatable {
  const FinancialSummary({
    this.generalBalance = 0,
    this.totalCredit = 0,
    this.totalDebit = 0,
    this.remainingSubscriptionFee = 0,
    this.subscriptionFeeStatus = 'paid',
    this.meterBalances = const [],
  });
  final double generalBalance;
  final double totalCredit;
  final double totalDebit;
  final double remainingSubscriptionFee;
  final String subscriptionFeeStatus;
  final List<MeterBalance> meterBalances;
  @override
  List<Object?> get props => [
    generalBalance,
    totalCredit,
    totalDebit,
    meterBalances,
  ];
}

/// حركة دفتر أستاذ خاصة بمشترك (تبويب كشف الحساب SCR-07/6).
class LedgerEntry extends Equatable {
  const LedgerEntry({
    required this.id,
    required this.type,
    required this.amount,
    this.meterId,
    this.party = 'الحساب العام للمشترك',
    this.description,
    this.createdAt,
    this.runningBalance = 0,
  });
  final int id;

  /// payment=دائن(+)/invoice=مدين(-)/adjustment=تسوية (أنواع قاعدة البيانات الفعلية).
  final String type;
  final double amount;
  final int? meterId;
  final String party;
  final String? description;
  final String? createdAt;
  final double runningBalance;

  bool get isCredit => amount > 0;
  String get typeLabel => switch (type) {
    'payment' || 'credit' => 'دائن',
    'invoice' || 'debit' => 'مدين',
    'adjustment' => 'تسوية',
    _ => type,
  };
  @override
  List<Object?> get props => [id, amount, runningBalance, meterId];
}

/// فلتر جهة كشف الحساب: الكل / الحساب العام / عداد محدد (مطابق SubscriberTransactionsTable).
sealed class PartyFilter {
  const PartyFilter();
  String? get apiValue;
  String get label;
}

class PartyAll extends PartyFilter {
  const PartyAll();
  @override
  String? get apiValue => null;
  @override
  String get label => 'الكل';
}

class PartyGeneral extends PartyFilter {
  const PartyGeneral();
  @override
  String? get apiValue => 'general';
  @override
  String get label => 'الحساب العام للمشترك';
}

class PartyMeter extends PartyFilter {
  const PartyMeter(this.meter);
  final MeterBalance meter;
  @override
  bool operator ==(Object other) =>
      other is PartyMeter && other.meter.meterId == meter.meterId;
  @override
  int get hashCode => meter.meterId.hashCode;
  @override
  String? get apiValue => '${meter.meterId}';
  @override
  String get label => meter.title;
}
