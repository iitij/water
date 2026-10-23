import 'package:flutter/material.dart';

import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';

class SettingsSaveBar extends StatelessWidget {
  const SettingsSaveBar({
    required this.isSaving,
    required this.onSave,
    this.enabled = true,
    super.key,
  });

  final bool isSaving;
  final bool enabled;
  final VoidCallback onSave;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      minimum: const EdgeInsets.fromLTRB(16, 8, 16, 12),
      child: FilledButton.icon(
        onPressed: enabled && !isSaving ? onSave : null,
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          backgroundColor: AdminPalette.primary,
        ),
        icon: isSaving
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
              )
            : const Icon(Icons.save_outlined),
        label: Text(isSaving ? 'جارٍ الحفظ...' : 'حفظ الإعدادات وتحديث التطبيق'),
      ),
    );
  }
}

class SettingsTextField extends StatelessWidget {
  const SettingsTextField({
    required this.controller,
    required this.label,
    required this.onChanged,
    this.helperText,
    this.required = false,
    this.forceLtr = false,
    this.maxLines = 1,
    this.obscureText = false,
    this.suffixIcon,
    super.key,
  });

  final TextEditingController controller;
  final String label;
  final String? helperText;
  final ValueChanged<String> onChanged;
  final bool required;
  final bool forceLtr;
  final int maxLines;
  final bool obscureText;
  final Widget? suffixIcon;

  @override
  Widget build(BuildContext context) {
    final field = TextFormField(
      controller: controller,
      onChanged: onChanged,
      maxLines: maxLines,
      obscureText: obscureText,
      textDirection: forceLtr ? TextDirection.ltr : null,
      textAlign: forceLtr ? TextAlign.left : TextAlign.start,
      validator: required
          ? (value) => value == null || value.trim().isEmpty ? 'هذا الحقل مطلوب' : null
          : null,
      decoration: InputDecoration(
        labelText: label,
        helperText: helperText,
        border: const OutlineInputBorder(),
        suffixIcon: suffixIcon,
      ),
    );
    return Padding(padding: const EdgeInsets.symmetric(vertical: 6), child: field);
  }
}

class SettingsFormGrid extends StatelessWidget {
  const SettingsFormGrid({required this.children, this.minItemWidth = 330, super.key});

  final List<Widget> children;
  final double minItemWidth;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = constraints.maxWidth >= minItemWidth * 2 ? 2 : 1;
        final width = columns == 2 ? (constraints.maxWidth - 14) / 2 : constraints.maxWidth;
        return Wrap(
          spacing: 14,
          runSpacing: 4,
          children: children
              .map((child) => SizedBox(width: width, child: child))
              .toList(growable: false),
        );
      },
    );
  }
}

class SettingsSectionCard extends StatelessWidget {
  const SettingsSectionCard({
    required this.title,
    required this.children,
    this.subtitle,
    super.key,
  });

  final String title;
  final String? subtitle;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return AdminCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AdminSectionHeader(title: title, subtitle: subtitle),
          const SizedBox(height: 12),
          ...children,
        ],
      ),
    );
  }
}

class SettingsLoadingBody extends StatelessWidget {
  const SettingsLoadingBody({super.key});

  @override
  Widget build(BuildContext context) => const SingleChildScrollView(
        padding: EdgeInsets.all(16),
        child: AdminLoadingView(itemCount: 4),
      );
}

class SettingsErrorBody extends StatelessWidget {
  const SettingsErrorBody({required this.message, required this.onRetry, super.key});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => AdminErrorView(message: message, onRetry: onRetry);
}
