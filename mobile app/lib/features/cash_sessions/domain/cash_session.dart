import 'package:equatable/equatable.dart';

/// حالة صندوق المحصّل كما يعرّفها الخادم.
enum CashSessionState {
  open,
  closed,
  approved,
  rejected;

  static CashSessionState parse(Object? raw) {
    switch (raw?.toString()) {
      case 'open':
        return CashSessionState.open;
      case 'approved':
        return CashSessionState.approved;
      case 'rejected':
        return CashSessionState.rejected;
      case 'closed':
      default:
        return CashSessionState.closed;
    }
  }

  String get arabicLabel => switch (this) {
    CashSessionState.open => 'مفتوح',
    CashSessionState.closed => 'مغلق — بانتظار الاعتماد',
    CashSessionState.approved => 'معتمد',
    CashSessionState.rejected => 'مرفوض',
  };
}

/// صندوق المحصّل اليومي: مبالغ متوقعة مقابل معدودة وفرق مُفسَّر.
///
/// كل المبالغ تصل من الخادم كنصوص منسّقة (`"1250.00"`) لأنها تُحسب هناك،
/// ويُمنع إعادة حسابها على الجهاز حتى لا يختلف رقم الاعتماد عن رقم الميدان.
class CashSession extends Equatable {
  const CashSession({
    required this.id,
    required this.state,
    required this.sessionDate,
    required this.openingAmount,
    required this.expectedAmount,
    required this.receipts,
    this.countedAmount,
    this.varianceAmount,
    this.collector,
    this.square,
    this.voidedReceipts = 0,
    this.byMethod = const <CashSessionMethodTotal>[],
    this.openedAt,
    this.closedAt,
    this.approvedAt,
    this.approvedBy,
    this.notes,
    this.closingNotes,
    this.approvalNotes,
  });

  final int id;
  final CashSessionState state;
  final DateTime sessionDate;
  final double openingAmount;
  final double expectedAmount;
  final double? countedAmount;
  final double? varianceAmount;
  final int receipts;
  final int voidedReceipts;
  final List<CashSessionMethodTotal> byMethod;
  final String? collector;
  final String? square;
  final DateTime? openedAt;
  final DateTime? closedAt;
  final DateTime? approvedAt;
  final String? approvedBy;
  final String? notes;
  final String? closingNotes;
  final String? approvalNotes;

  bool get isOpen => state == CashSessionState.open;

  /// هل الفرق يستوجب تفسيراً مكتوباً؟ (عجز أو زيادة تتجاوز 10 ريالات)
  bool get needsExplanation =>
      (varianceAmount ?? 0).abs() >= 10 && (closingNotes ?? '').trim().isEmpty;

  /// العجز بالسالب، والزيادة بالموجب.
  bool get isShort => (varianceAmount ?? 0) < -0.009;
  bool get isOver => (varianceAmount ?? 0) > 0.009;

  static CashSession? tryParse(Object? raw) {
    if (raw is! Map) return null;
    final map = Map<String, dynamic>.from(raw);
    final id = _toInt(map['id']);
    if (id == null) return null;

    return CashSession(
      id: id,
      state: CashSessionState.parse(map['state']),
      sessionDate:
          DateTime.tryParse(map['session_date']?.toString() ?? '') ??
          DateTime.now(),
      openingAmount: _toDouble(map['opening_amount']),
      expectedAmount: _toDouble(map['expected_amount']),
      countedAmount: _toNullableDouble(map['counted_amount']),
      varianceAmount: _toNullableDouble(map['variance_amount']),
      receipts: _toInt(map['receipts']) ?? 0,
      voidedReceipts: _toInt(map['voided_receipts']) ?? 0,
      byMethod: (map['by_method'] is List)
          ? (map['by_method'] as List)
                .whereType<Map>()
                .map(
                  (row) => CashSessionMethodTotal.fromMap(
                    Map<String, dynamic>.from(row),
                  ),
                )
                .toList(growable: false)
          : const <CashSessionMethodTotal>[],
      collector: _nullableString(map['collector']),
      square: _nullableString(map['square']),
      openedAt: DateTime.tryParse(map['opened_at']?.toString() ?? ''),
      closedAt: DateTime.tryParse(map['closed_at']?.toString() ?? ''),
      approvedAt: DateTime.tryParse(map['approved_at']?.toString() ?? ''),
      approvedBy: _nullableString(map['approved_by']),
      notes: _nullableString(map['notes']),
      closingNotes: _nullableString(map['closing_notes']),
      approvalNotes: _nullableString(map['approval_notes']),
    );
  }

  /// ملخّص عربي جاهز للعرض في شاشة الصندوق.
  String get arabicSummary {
    if (isOpen) {
      return 'الصندوق مفتوح — $receipts سنداً، النقد المتوقع ${expectedAmount.toStringAsFixed(2)}';
    }
    final variance = varianceAmount ?? 0;
    final verdict = variance.abs() < 0.01
        ? 'مطابق'
        : variance < 0
        ? 'عجز ${variance.abs().toStringAsFixed(2)}'
        : 'زيادة ${variance.toStringAsFixed(2)}';
    return '${state.arabicLabel} — $verdict';
  }

  static int? _toInt(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  static double _toDouble(Object? value) => _toNullableDouble(value) ?? 0;

  static double? _toNullableDouble(Object? value) {
    if (value == null) return null;
    if (value is num) return value.toDouble();
    return double.tryParse(value.toString().replaceAll(',', ''));
  }

  static String? _nullableString(Object? value) {
    final text = value?.toString().trim();
    return text == null || text.isEmpty ? null : text;
  }

  @override
  List<Object?> get props => <Object?>[
    id,
    state,
    sessionDate,
    openingAmount,
    expectedAmount,
    countedAmount,
    varianceAmount,
    receipts,
    voidedReceipts,
    collector,
    square,
    closedAt,
    approvedAt,
  ];
}

class CashSessionMethodTotal extends Equatable {
  const CashSessionMethodTotal({
    required this.method,
    required this.receipts,
    required this.total,
  });

  final String method;
  final int receipts;
  final double total;

  factory CashSessionMethodTotal.fromMap(Map<String, dynamic> map) =>
      CashSessionMethodTotal(
        method:
            map['payment_method']?.toString() ??
            map['method']?.toString() ??
            '',
        receipts: CashSession._toInt(map['receipts']) ?? 0,
        total: CashSession._toDouble(map['total']),
      );

  String get arabicLabel => switch (method) {
    'cash' => 'نقداً',
    'bank_transfer' => 'حوالة بنكية',
    'check' => 'شيك',
    'mobile_wallet' => 'محفظة إلكترونية',
    'card' => 'بطاقة',
    _ => method.isEmpty ? 'غير محدد' : method,
  };

  @override
  List<Object?> get props => <Object?>[method, receipts, total];
}
