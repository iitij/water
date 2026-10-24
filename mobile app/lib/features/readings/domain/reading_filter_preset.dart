import 'package:equatable/equatable.dart';

/// فلتر قراءات محفوظ باسم (خطة 2.0 — الفصل 6.3).
///
/// القارئ يعمل بالمربع والمسار نفسه كل يوم؛ إعادة تركيب الفلاتر في كل جلسة
/// هدر حقيقي. نحفظ المجموعة باسم قصير («مربع 3 — غير مفوترة») ويُستعاد بضغطة.
///
/// التخزين محلي بحت (SharedPreferences): الفلاتر تخصّ جهاز القارئ وجولته،
/// ولا معنى لمزامنتها بين الأجهزة.
class ReadingFilterPreset extends Equatable {
  const ReadingFilterPreset({
    required this.id,
    required this.name,
    this.squareId,
    this.squareName,
    this.invoiceFilterCode = 'all',
    this.fromDate,
    this.toDate,
    this.query = '',
  });

  /// معرّف مقروء فريد (زمن بالمللي ثانية) — يكفي لجهاز واحد.
  final String id;

  /// الاسم كما يراه القارئ.
  final String name;
  final int? squareId;

  /// اسم المربع محفوظ مع المعرّف حتى يظهر في الشريحة بلا شبكة.
  final String? squareName;

  /// `all` / `invoiced` / `notInvoiced` — نصاً حتى لا يعتمد التخزين على ترتيب enum.
  final String invoiceFilterCode;
  final DateTime? fromDate;
  final DateTime? toDate;
  final String query;

  /// هل هذا الفلتر «فارغ» (لا شيء يُستعاد)؟
  bool get isEmpty =>
      squareId == null &&
      fromDate == null &&
      toDate == null &&
      query.trim().isEmpty &&
      invoiceFilterCode == 'all';

  /// عدد الأوجه المطبّقة — يظهر في الشريحة.
  int get facetCount =>
      (squareId == null ? 0 : 1) +
      (fromDate != null || toDate != null ? 1 : 0) +
      (invoiceFilterCode == 'all' ? 0 : 1) +
      (query.trim().isEmpty ? 0 : 1);

  ReadingFilterPreset copyWith({String? name}) => ReadingFilterPreset(
        id: id,
        name: name ?? this.name,
        squareId: squareId,
        squareName: squareName,
        invoiceFilterCode: invoiceFilterCode,
        fromDate: fromDate,
        toDate: toDate,
        query: query,
      );

  Map<String, dynamic> toJson() => <String, dynamic>{
        'id': id,
        'name': name,
        'square_id': squareId,
        'square_name': squareName,
        'invoice_filter': invoiceFilterCode,
        'from_date': fromDate?.toIso8601String(),
        'to_date': toDate?.toIso8601String(),
        'query': query,
      };

  factory ReadingFilterPreset.fromJson(Map<String, dynamic> json) => ReadingFilterPreset(
        id: json['id']?.toString() ?? '',
        name: json['name']?.toString() ?? 'فلتر',
        squareId: (json['square_id'] as num?)?.toInt(),
        squareName: json['square_name']?.toString(),
        invoiceFilterCode: json['invoice_filter']?.toString() ?? 'all',
        fromDate: DateTime.tryParse(json['from_date']?.toString() ?? ''),
        toDate: DateTime.tryParse(json['to_date']?.toString() ?? ''),
        query: json['query']?.toString() ?? '',
      );

  /// اقتراح اسم تلقائي يُعرض في مربع الحفظ — يوفّر الكتابة على القارئ.
  static String suggestName({
    int? squareId,
    String? squareName,
    String invoiceFilterCode = 'all',
    DateTime? fromDate,
    DateTime? toDate,
    String query = '',
  }) {
    final parts = <String>[];
    if (squareName != null && squareName.trim().isNotEmpty) {
      parts.add(squareName.trim());
    } else if (squareId != null) {
      parts.add('مربع #$squareId');
    }
    if (invoiceFilterCode == 'invoiced') {
      parts.add('مفوترة');
    } else if (invoiceFilterCode == 'notInvoiced') {
      parts.add('غير مفوترة');
    }
    if (fromDate != null || toDate != null) {
      parts.add('بفترة');
    }
    if (query.trim().isNotEmpty) {
      parts.add('«${query.trim()}»');
    }
    return parts.isEmpty ? 'فلتر عام' : parts.join(' • ');
  }

  @override
  List<Object?> get props =>
      <Object?>[id, name, squareId, invoiceFilterCode, fromDate, toDate, query];
}
