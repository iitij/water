import 'package:flutter/material.dart';

class WhatsAppTemplateEditor extends StatelessWidget {
  const WhatsAppTemplateEditor({
    required this.controller,
    required this.variables,
    required this.sampleValues,
    required this.onChanged,
    super.key,
  });

  final TextEditingController controller;
  final List<String> variables;
  final Map<String, String> sampleValues;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.symmetric(vertical: 12),
      children: [
        Text(
          'اضغط أي متغير لإدراجه في موضع المؤشر:',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final variable in variables)
              ActionChip(
                label: Text(
                  '{$variable}',
                  style: const TextStyle(fontFamily: 'monospace', fontSize: 11),
                ),
                onPressed: () => _insertVariable(variable),
              ),
          ],
        ),
        const SizedBox(height: 12),
        TextFormField(
          controller: controller,
          onChanged: onChanged,
          minLines: 6,
          maxLines: 9,
          textDirection: TextDirection.rtl,
          decoration: const InputDecoration(
            labelText: 'نص القالب',
            alignLabelWithHint: true,
            border: OutlineInputBorder(),
          ),
          validator: (value) =>
              value == null || value.trim().isEmpty ? 'نص القالب مطلوب' : null,
        ),
        const SizedBox(height: 12),
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: Text('معاينة الرسالة', style: Theme.of(context).textTheme.labelLarge),
        ),
        const SizedBox(height: 6),
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: Container(
            constraints: const BoxConstraints(maxWidth: 520),
            padding: const EdgeInsets.all(12),
            decoration: const BoxDecoration(
              color: Color(0xFFD9FDD3),
              borderRadius: BorderRadius.only(
                topRight: Radius.circular(14),
                topLeft: Radius.circular(14),
                bottomRight: Radius.circular(14),
              ),
            ),
            child: Text(
              renderWhatsAppPreview(controller.text, sampleValues),
              style: const TextStyle(color: Colors.black87, height: 1.5),
            ),
          ),
        ),
      ],
    );
  }

  void _insertVariable(String variable) {
    final token = '{$variable}';
    final text = controller.text;
    final selection = controller.selection;
    final start = selection.isValid && selection.start >= 0 ? selection.start : text.length;
    final end = selection.isValid && selection.end >= 0 ? selection.end : text.length;
    final updated = text.replaceRange(start, end, token);
    controller.value = TextEditingValue(
      text: updated,
      selection: TextSelection.collapsed(offset: start + token.length),
    );
    onChanged(updated);
  }
}

String renderWhatsAppPreview(String template, Map<String, String> values) {
  var rendered = template.replaceAllMapped(
    RegExp(r'\{if:([a-zA-Z0-9_]+)\}(.*?)\{/if:\1\}', dotAll: true),
    (match) {
      final value = values[match.group(1)] ?? '';
      final empty = value.trim().isEmpty || value.trim() == '0' || value.trim() == '0.00';
      return empty ? '' : match.group(2)!;
    },
  );
  for (final entry in values.entries) {
    rendered = rendered.replaceAll('{${entry.key}}', entry.value);
  }
  return rendered;
}

abstract final class WhatsAppTemplateSamples {
  static const common = <String, String>{
    'subscriber_name': 'أحمد محمد',
    'name': 'أحمد محمد',
    'bill_number': 'INV-2026-001',
    'receipt_number': 'REC-202610-0001',
    'period_start': '2026-10-01',
    'period_end': '2026-10-31',
    'from_date': '2026-10-01',
    'to_date': '2026-10-31',
    'meter_code': 'MTR-1042',
    'previous_reading': '1240',
    'current_reading': '1265',
    'consumption_units': '25',
    'unit_price': '150',
    'consumption_fee': '3750',
    'consumption_value': '3750',
    'subscription_fee': '500',
    'tanker_fee': '0',
    'previous_balance': '0',
    'credit_applied': '0',
    'credit_balance': '0',
    'total_amount': '4250',
    'amount': '4250',
    'invoice_remaining': '0',
    'outstanding_balance': '0',
    'collector': 'علي الوشاح',
    'date': '2026-10-23',
    'payment_method': 'نقدي',
    'pdf_url': 'https://example.test/invoice.pdf',
    'pdf_link': 'https://example.test/invoice.pdf',
  };

  static const invoiceVariables = [
    'subscriber_name', 'bill_number', 'period_start', 'period_end', 'meter_code',
    'previous_reading', 'current_reading', 'consumption_units', 'unit_price',
    'consumption_fee', 'subscription_fee', 'tanker_fee', 'previous_balance',
    'credit_applied', 'total_amount', 'collector', 'pdf_url',
  ];

  static const paymentVariables = [
    'subscriber_name', 'receipt_number', 'amount', 'date', 'payment_method',
    'invoice_remaining', 'outstanding_balance', 'credit_balance', 'collector', 'pdf_url',
  ];

  static const subscriptionVariables = [
    'subscriber_name', 'bill_number', 'subscription_fee', 'previous_balance',
    'total_amount', 'collector', 'pdf_url',
  ];

  static const tankerVariables = [
    'subscriber_name', 'bill_number', 'tanker_fee', 'previous_balance',
    'total_amount', 'collector', 'pdf_url',
  ];
}
