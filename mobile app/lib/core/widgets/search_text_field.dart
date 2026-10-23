import 'package:flutter/material.dart';

/// Search input whose visible text stays in sync with external filter changes
/// (for example, a barcode scan or a clear-filter action).
class SearchTextField extends StatefulWidget {
  const SearchTextField({
    required this.query,
    required this.hintText,
    required this.onChanged,
    this.prefixIcon = Icons.search,
    super.key,
  });

  final String query;
  final String hintText;
  final ValueChanged<String> onChanged;
  final IconData prefixIcon;

  @override
  State<SearchTextField> createState() => _SearchTextFieldState();
}

class _SearchTextFieldState extends State<SearchTextField> {
  late final TextEditingController _controller = TextEditingController(text: widget.query);

  @override
  void didUpdateWidget(covariant SearchTextField oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.query != widget.query && _controller.text != widget.query) {
      _controller.value = TextEditingValue(
        text: widget.query,
        selection: TextSelection.collapsed(offset: widget.query.length),
      );
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
        controller: _controller,
        onChanged: widget.onChanged,
        textInputAction: TextInputAction.search,
        decoration: InputDecoration(
          hintText: widget.hintText,
          prefixIcon: Icon(widget.prefixIcon, size: 19),
          suffixIcon: _controller.text.isEmpty
              ? null
              : IconButton(
                  tooltip: 'مسح البحث',
                  visualDensity: VisualDensity.compact,
                  onPressed: () {
                    _controller.clear();
                    widget.onChanged('');
                  },
                  icon: const Icon(Icons.close_rounded, size: 18),
                ),
          isDense: true,
        ),
      );
}
