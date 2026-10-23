import 'package:flutter/material.dart';

import '../../../../core/ui/app_surfaces.dart';
import '../../../admin_shared/presentation/theme/admin_palette.dart';

/// Unified show/hide + alternate-label editor matching Filament's
/// buildFieldConfig() for invoice and receipt fields.
class FieldConfigTile extends StatelessWidget {
  const FieldConfigTile({
    required this.title,
    required this.showKey,
    required this.labelKey,
    required this.visible,
    required this.labelController,
    required this.onVisibilityChanged,
    required this.onLabelChanged,
    this.documentLabel = 'الفاتورة',
    super.key,
  });

  final String title;
  final String showKey;
  final String labelKey;
  final bool visible;
  final TextEditingController labelController;
  final ValueChanged<bool> onVisibilityChanged;
  final ValueChanged<String> onLabelChanged;
  final String documentLabel;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: AppCard(
        padding: EdgeInsets.zero,
        child: ExpansionTile(
        key: ValueKey('field-config-$showKey'),
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text(
          visible ? 'ظاهر في $documentLabel' : 'مخفي من $documentLabel',
          style: TextStyle(color: visible ? AdminPalette.success : AdminPalette.neutral),
        ),
        childrenPadding: const EdgeInsets.fromLTRB(14, 0, 14, 14),
        children: [
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: Text('عرض الحقل في $documentLabel'),
            value: visible,
            onChanged: onVisibilityChanged,
          ),
          TextFormField(
            key: ValueKey('field-label-$labelKey'),
            controller: labelController,
            onChanged: onLabelChanged,
            validator: (value) =>
                value == null || value.trim().isEmpty ? 'المسمى البديل مطلوب' : null,
            decoration: const InputDecoration(
              labelText: 'النص البديل (المسمى)',
            ),
          ),
        ],
        ),
      ),
    );
  }
}
