import 'package:equatable/equatable.dart';

import '../../../../core/utils/json_utils.dart';

/// نموذج شرائح التعرفة — يعكس `App\Models\Tariff`
/// (`name`, `unit_price`, `is_active`, `start_date`, `end_date`, soft deletes).
///
/// ⚠️ ملاحظة مطابقة: أمر Dev 3 يذكر `min_usage/max_usage/price_per_unit` (شرائح
/// متدرجة)، لكن مخطط جدول `tariffs` الفعلي (`2026_06_11_000353_create_tariffs_table.php`)
/// والموديل والفilament `TariffsTable` لا تحتويها — السعر خطّي واحد.
/// بنينا النموذج على المخطط الفعلي وأضفنا حقول الشرائح الاختيارية حتى لا ينكسر
/// الإصدار القادم من الـ API عند إضافتها. انظر قسم «فروقات موثّقة» في ملف التسليم.
class TariffModel extends Equatable {
  const TariffModel({
    required this.id,
    required this.name,
    required this.unitPrice,
    this.isActive = true,
    this.startDate,
    this.endDate,
    this.minUsage,
    this.maxUsage,
    this.createdAt,
    this.deletedAt,
  });

  final int id;
  final String name;
  final double unitPrice;
  final bool isActive;
  final DateTime? startDate;
  final DateTime? endDate;

  /// حقول اختيارية للمستقبل (شرائح متدرجة) — تُرسل فقط عند تعبئتها.
  final double? minUsage;
  final double? maxUsage;
  final DateTime? createdAt;
  final DateTime? deletedAt;

  bool get isTrashed => deletedAt != null;

  /// يطابق `Tariff::getActivePrice()`: نشط + ضمن نافذة التاريخ.
  bool isEffectiveOn(DateTime date) {
    if (!isActive) return false;
    if (startDate != null && date.isBefore(startDate!)) return false;
    if (endDate != null && date.isAfter(endDate)) return false;
    return true;
  }

  String get periodLabel {
    if (startDate == null && endDate == null) return 'بدون تحديد';
    return '${DateFormat.day(startDate)} ← ${DateFormat.day(endDate)}';
  }

  factory TariffModel.fromJson(Map<String, dynamic> json) => TariffModel(
        id: JsonUtils.asInt(json['id']),
        name: JsonUtils.asString(json['name'], fallback: '-'),
        unitPrice: JsonUtils.asDouble(json['unit_price'] ?? json['price_per_unit']),
        isActive: JsonUtils.asBool(json['is_active'] ?? true),
        startDate: JsonUtils.asDate(json['start_date']),
        endDate: JsonUtils.asDate(json['end_date']),
        minUsage: JsonUtils.asDoubleOrNull(json['min_usage']),
        maxUsage: JsonUtils.asDoubleOrNull(json['max_usage']),
        createdAt: JsonUtils.asDate(json['created_at']),
        deletedAt: JsonUtils.asDate(json['deleted_at']),
      );

  Map<String, dynamic> toPayload() => <String, dynamic>{
        'name': name,
        'unit_price': unitPrice,
        'is_active': isActive,
        'start_date': startDate == null ? null : DateFormat.day(startDate),
        'end_date': endDate == null ? null : DateFormat.day(endDate),
        if (minUsage != null) 'min_usage': minUsage,
        if (maxUsage != null) 'max_usage': maxUsage,
      };

  TariffModel copyWith({
    String? name,
    double? unitPrice,
    bool? isActive,
    DateTime? startDate,
    DateTime? endDate,
    DateTime? deletedAt,
  }) =>
      TariffModel(
        id: id,
        name: name ?? this.name,
        unitPrice: unitPrice ?? this.unitPrice,
        isActive: isActive ?? this.isActive,
        startDate: startDate ?? this.startDate,
        endDate: endDate ?? this.endDate,
        minUsage: minUsage,
        maxUsage: maxUsage,
        createdAt: createdAt,
        deletedAt: deletedAt ?? this.deletedAt,
      );

  @override
  List<Object?> get props => <Object?>[
        id,
        name,
        unitPrice,
        isActive,
        startDate,
        endDate,
        deletedAt,
      ];
}

/// حالة فلتر المحذوفات في `SCR-21` (يطابق `TrashedFilter` في Filament).
enum TariffsTrashFilter {
  without('without', 'الحية فقط'),
  with('with', 'الكل (مع المحذوفة)'),
  only('only', 'المحذوفة فقط');

  const TariffsTrashFilter(this.wire, this.label);

  final String wire;
  final String label;

  static TariffsTrashFilter parse(String? value) => TariffsTrashFilter.values.firstWhere(
        (f) => f.wire == value,
        orElse: () => TariffsTrashFilter.without,
      );
}
