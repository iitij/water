import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../utils/compound_search_parser.dart';

/// شريط البحث الذكي الموحّد (اسم/رقم مستند/كود عداد/مسار مركّب `4/1`)
/// مع رقائق فلاتر سريعة أسفله. المرجع: الخطة §3.1 (`core/widgets/compound_search_bar.dart`).
class CompoundSearchBar extends StatefulWidget {
  const CompoundSearchBar({
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
  State<CompoundSearchBar> createState() => _CompoundSearchBarState();
}

class _CompoundSearchBarState extends State<CompoundSearchBar> {
  bool _isCompound = false;

  @override
  void initState() {
    super.initState();
    _recompute(widget.controller.text);
  }

  void _recompute(String value) {
    final compound = CompoundSearchQuery.parse(value).isCompoundPathSearch;
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
