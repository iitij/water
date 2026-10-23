import 'package:flutter/material.dart';

import '../utils/compound_search_query.dart';
import 'app_numeric_field.dart';

class CompoundSearchBar extends StatelessWidget {
  const CompoundSearchBar({
    super.key,
    required this.controller,
    required this.onChanged,
    this.hintText = 'ابحث بالاسم أو الهاتف أو المربع/المسار مثل ٤/١',
    this.onFilterPressed,
    this.autofocus = false,
  });

  final TextEditingController controller;
  final ValueChanged<CompoundSearchQuery> onChanged;
  final VoidCallback? onFilterPressed;
  final String hintText;
  final bool autofocus;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        Expanded(
          child: AppNumericField(
            controller: controller,
            label: 'بحث',
            hint: hintText,
            prefixIcon: Icons.search_rounded,
            allowCompoundSearch: true,
            autofocus: autofocus,
            onChanged: (value) => onChanged(CompoundSearchQuery.parse(value)),
          ),
        ),
        if (onFilterPressed != null) ...<Widget>[
          const SizedBox(width: 10),
          IconButton.filledTonal(
            onPressed: onFilterPressed,
            icon: const Icon(Icons.tune_rounded),
            tooltip: 'خيارات التصفية',
          ),
        ],
      ],
    );
  }
}
