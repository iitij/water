/// محلل ومسار البحث الموحّد (`4/1`، رقم مشترك، رقم فاتورة/سند، «مربع»/«وايت»).
///
/// نسخة Dev 3 الأكثر اتساعاً من `core/utils/compound_search_parser.dart` عند Dev 1
/// (تضيف `isEmpty`/`hasSplitParams` وتوحيد الأرقام العربية) مع شريط البحث الذي تستخدمه
/// شاشات SCR-13/14/17/21.
library;
import 'package:flutter/material.dart';

import '../theme/app_colors.dart';

class FinanceSearchQuery {
  const FinanceSearchQuery({
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

  factory FinanceSearchQuery.parse(String input) {
    final normalized = normalizeDigits(input.trim());
    final match = RegExp(r'^(\d+)[/\-\\_](\d+)$').firstMatch(normalized);

    if (match != null) {
      return FinanceSearchQuery(
        rawQuery: normalized,
        squareId: int.tryParse(match.group(1)!),
        pathNumber: int.tryParse(match.group(2)!),
        isCompoundPathSearch: true,
      );
    }

    return FinanceSearchQuery(rawQuery: normalized);
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

class FinanceSearchBar extends StatefulWidget {
  const FinanceSearchBar({
    super.key,
    required this.controller,
    this.hint = 'ابحث بالاسم أو رقم الفاتورة أو 4/1',
    this.onChanged,
    this.onSubmitted,
    this.filters = const <Widget>[],
    this.trailing,
    this.autofocus = false,
  });

  final TextEditingController controller;
  final String hint;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final List<Widget> filters;
  final Widget? trailing;
  final bool autofocus;

  @override
  State<FinanceSearchBar> createState() => _FinanceSearchBarState();
}

class _FinanceSearchBarState extends State<FinanceSearchBar> {
  bool _isCompound = false;

  @override
  void initState() {
    super.initState();
    _recompute(widget.controller.text);
  }

  void _recompute(String value) {
    final compound = FinanceSearchQuery.parse(value).isCompoundPathSearch;
    if (compound != _isCompound && mounted) setState(() => _isCompound = compound);
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          TextField(
            controller: widget.controller,
            autofocus: widget.autofocus,
            textInputAction: TextInputAction.search,
            onChanged: (value) {
              _recompute(value);
              widget.onChanged?.call(value);
            },
            onSubmitted: widget.onSubmitted,
            decoration: InputDecoration(
              hintText: widget.hint,
              prefixIcon: const Icon(Icons.search),
              suffixIcon: Row(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (_isCompound)
                    const Padding(
                      padding: EdgeInsets.only(left: 8),
                      child: Icon(
                        Icons.route_outlined,
                        size: 18,
                        color: AppColors.primary,
                      ),
                    ),
                  if (widget.controller.text.isNotEmpty)
                    IconButton(
                      tooltip: 'مسح',
                      visualDensity: VisualDensity.compact,
                      icon: const Icon(Icons.close, size: 18),
                      onPressed: () {
                        widget.controller.clear();
                        _recompute('');
                        widget.onChanged?.call('');
                      },
                    ),
                  if (widget.trailing != null) widget.trailing!,
                ],
              ),
              isDense: true,
            ),
          ),
          if (widget.filters.isNotEmpty) ...<Widget>[
            const SizedBox(height: 8),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: widget.filters
                    .map((f) => Padding(
                          padding: const EdgeInsets.only(left: 8),
                          child: f,
                        ))
                    .toList(),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// رقاقة فلتر سريعة (المربع السكني/الحالة/طريقة الدفع).
class FilterChipButton extends StatelessWidget {
  const FilterChipButton({
    super.key,
    required this.label,
    this.value,
    this.icon,
    this.onPressed,
    this.selected = false,
  });

  final String label;
  final String? value;
  final IconData? icon;
  final VoidCallback? onPressed;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final text = value == null || value!.isEmpty ? label : '$label: $value';
    return ActionChip(
      onPressed: onPressed,
      backgroundColor: selected ? AppColors.primary.withValues(alpha: 0.10) : null,
      side: BorderSide(
        color: selected ? AppColors.primary : AppColors.lightBorder,
      ),
      avatar: Icon(
        selected ? Icons.filter_alt : (icon ?? Icons.filter_alt_outlined),
        size: 18,
        color: selected ? AppColors.primary : AppColors.neutral,
      ),
      label: Text(text, style: const TextStyle(fontSize: 12)),
    );
  }
}
