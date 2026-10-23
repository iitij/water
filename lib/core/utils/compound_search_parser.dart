/// محلل البحث المركب (مربع/مسار) — يطابق `preg_match('/^(\d+)[\/\-\\_](\d+)$/', $query)`
/// في `app/Http/Controllers/Api/InvoiceController.php#L45` و`ReadingController.php#L45`.
///
/// المرجع: `FLUTTER_MOBILE_MASTER_PLAN.md` §7.1-1 و§1.3 القاعدة 5.
class CompoundSearchQuery {
  const CompoundSearchQuery({
    required this.rawQuery,
    this.squareId,
    this.pathNumber,
    this.isCompoundPathSearch = false,
  });

  final String rawQuery;
  final int? squareId;
  final int? pathNumber;
  final bool isCompoundPathSearch;

  /// هل البحث فارغ تماماً؟
  bool get isEmpty => rawQuery.isEmpty;

  /// هل يستفيد الخادم من بارامترات `square_id` + `path_number` المنفصلة؟
  /// (بعض مسارات Dev 1 الجديدة تقبلها بدل النص المركّب).
  bool get hasSplitParams => squareId != null && pathNumber != null;

  factory CompoundSearchQuery.parse(String input) {
    final normalized = normalizeDigits(input.trim());
    final match = RegExp(r'^(\d+)[/\-\\_](\d+)$').firstMatch(normalized);

    if (match != null) {
      return CompoundSearchQuery(
        rawQuery: normalized,
        squareId: int.tryParse(match.group(1)!),
        pathNumber: int.tryParse(match.group(2)!),
        isCompoundPathSearch: true,
      );
    }

    return CompoundSearchQuery(rawQuery: normalized);
  }

  /// تحويل الأرقام العربية (`٠-٩`) والفارسية (`۰-۹`) إلى أرقام لاتينية.
  static String normalizeDigits(String input) {
    const arabic = <String>['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    const persian = <String>['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    const english = <String>['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    var result = input;
    for (var i = 0; i < english.length; i++) {
      result = result.replaceAll(arabic[i], english[i]);
      result = result.replaceAll(persian[i], english[i]);
    }
    return result;
  }

  /// الصيغة المعيارية المرسل للسيرفر عند دعم البارامترات المنفصلة.
  Map<String, dynamic> toQueryMap({String key = 'query'}) => <String, dynamic>{
        key: rawQuery,
        if (squareId != null && isCompoundPathSearch) 'square_id': squareId,
        if (pathNumber != null && isCompoundPathSearch) 'path_number': pathNumber,
      };
}
