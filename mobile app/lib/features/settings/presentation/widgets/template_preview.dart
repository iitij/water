import 'package:flutter/material.dart';

import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../domain/entities/settings_entities.dart';

class TemplatePreview extends StatelessWidget {
  const TemplatePreview({required this.settings, super.key});

  final TemplateSettings settings;

  @override
  Widget build(BuildContext context) {
    return settings.kind == TemplateKind.invoice
        ? _InvoicePreview(settings: settings)
        : _ReceiptPreview(settings: settings);
  }
}

class _PreviewPaper extends StatelessWidget {
  const _PreviewPaper({required this.title, required this.children});

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      padding: const EdgeInsets.all(20),
      radius: AppRadius.lg,
      child: Directionality(
        textDirection: TextDirection.rtl,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              title,
              textAlign: TextAlign.center,
              style: const TextStyle(
                color: AdminPalette.primary,
                fontSize: 20,
                fontWeight: FontWeight.w600,
              ),
            ),
            const Divider(height: 28, color: AdminPalette.primary),
            ...children,
          ],
        ),
      ),
    );
  }
}

class _InvoicePreview extends StatelessWidget {
  const _InvoicePreview({required this.settings});

  final TemplateSettings settings;

  @override
  Widget build(BuildContext context) {
    return _PreviewPaper(
      title: settings.text('inv_title_main'),
      children: [
        Text(
          settings.text('invoice_project_name_ar'),
          textAlign: TextAlign.center,
          style: const TextStyle(color: AdminPalette.primaryDark, fontWeight: FontWeight.bold),
        ),
        Text(
          settings.text('invoice_address_ar'),
          textAlign: TextAlign.center,
          style: TextStyle(
            color: Theme.of(context).colorScheme.onSurfaceVariant,
            fontSize: 11,
          ),
        ),
        const SizedBox(height: 18),
        _previewSection(
          settings.text('inv_title_subscriber'),
          [
            _previewRow(settings, 'bill_number', 'INV-2026-001'),
            _previewRow(settings, 'sub_name', 'أحمد محمد'),
            _previewRow(settings, 'meter_no', 'MTR-1042'),
            _previewRow(settings, 'square', 'المربع الأول'),
            _previewRow(settings, 'tenant', 'اسم المستأجر'),
          ],
        ),
        const SizedBox(height: 16),
        _previewSection(
          settings.text('inv_title_consumption'),
          [
            _previewRow(settings, 'prev_read', '1,240'),
            _previewRow(settings, 'curr_read', '1,265'),
            _previewRow(settings, 'units', '25'),
            _previewRow(settings, 'cons_value', '3,750 ريال'),
            _previewRow(settings, 'sub_fee', '500 ريال'),
            _previewRow(settings, 'arrears', '0 ريال'),
            _previewRow(settings, 'credit_applied', '0 ريال'),
          ],
        ),
        const SizedBox(height: 16),
        Container(
          padding: const EdgeInsets.all(12),
          color: AdminPalette.primary.withAlpha(15),
          child: Row(
            children: [
              Expanded(child: Text(settings.text('inv_title_total'))),
              const Text('4,250 ريال', style: TextStyle(fontWeight: FontWeight.w600)),
            ],
          ),
        ),
        if (settings.boolean('inv_show_thank_you')) ...[
          const SizedBox(height: 16),
          Text(
            settings.text('inv_lbl_thank_you'),
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 11,
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ],
    );
  }
}

class _ReceiptPreview extends StatelessWidget {
  const _ReceiptPreview({required this.settings});

  final TemplateSettings settings;

  @override
  Widget build(BuildContext context) {
    return _PreviewPaper(
      title: settings.text('rect_title_main'),
      children: [
        Text(
          settings.text('invoice_project_name_ar'),
          textAlign: TextAlign.center,
          style: const TextStyle(color: AdminPalette.primaryDark, fontWeight: FontWeight.bold),
        ),
        const SizedBox(height: 18),
        if (settings.boolean('rect_show_title_subscriber'))
          _previewSection(
            settings.text('rect_title_subscriber'),
            [
              _previewRow(settings, 'receipt_number', 'REC-202610-0001'),
              _previewRow(settings, 'receipt_date', '2026-10-23'),
              _previewRow(settings, 'sub_name', 'أحمد محمد'),
              _previewRow(settings, 'square', 'المربع الأول'),
            ],
          ),
        const SizedBox(height: 16),
        if (settings.boolean('rect_show_title_payment'))
          _previewSection(
            settings.text('rect_title_payment'),
            [
              _previewRow(settings, 'payment_method', 'نقدي'),
              _previewRow(settings, 'amount_received', '4,250 ريال'),
              _previewRow(settings, 'remaining_invoice', '0 ريال'),
              _previewRow(settings, 'reason', 'تحصيل فاتورة مياه'),
            ],
          ),
        if (settings.boolean('rect_show_amount_in_words')) ...[
          const SizedBox(height: 16),
          Text(
            '${settings.text('rect_lbl_amount_in_words')}: أربعة آلاف ومائتان وخمسون ${settings.text('rect_amount_words_suffix')}',
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
          ),
        ],
      ],
    );
  }
}

Widget _previewSection(String title, List<Widget> children) {
  return Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Text(
        title,
        style: const TextStyle(color: AdminPalette.primary, fontWeight: FontWeight.w600),
      ),
      const SizedBox(height: 8),
      ...children,
    ],
  );
}

Widget _previewRow(TemplateSettings settings, String key, String sampleValue) {
  final invoice = settings.kind == TemplateKind.invoice;
  final prefix = invoice ? 'inv' : 'rect';
  if (!settings.boolean('${prefix}_show_$key')) return const SizedBox.shrink();
  return Padding(
    padding: const EdgeInsets.symmetric(vertical: 3),
    child: Row(
      children: [
        Expanded(child: Text(settings.text('${prefix}_lbl_$key'))),
        Text(sampleValue, textDirection: TextDirection.ltr),
      ],
    ),
  );
}
