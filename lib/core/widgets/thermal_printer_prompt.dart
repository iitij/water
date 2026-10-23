import 'package:flutter/material.dart';

import '../services/thermal_printer_service.dart';

/// يعرض مربع إدخال عنوان MAC للطابعة الحرارية ويحفظه في `SharedPreferences`.
///
/// يُستعمل من `invoices` و`payments` معاً (الطابعة الحرارية مشتركة بين
/// سندات القبض والفواتير)، فوضعها في `core` يمنع الاستيراد المتبادل بين الوحدتين.
/// المالك الفعلي للـ `ThermalPrinterService` هو Dev 1؛ هذه الدالة واجهة عرض فقط.
Future<String?> promptThermalPrinterAddress(
  BuildContext context,
  ThermalPrinterService service,
) async {
  final controller = TextEditingController(text: service.savedAddress ?? '');

  final confirmed = await showDialog<bool>(
    context: context,
    builder: (dialogContext) => AlertDialog(
      title: const Text('طابعة البلوتوث الحرارية'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const Text(
            'أدخل عنوان MAC للطابعة المقترنة بجهازك، مثال: 00:11:22:33:44:55',
          ),
          const SizedBox(height: 12),
          TextField(
            controller: controller,
            autofillHints: const <String>[],
            decoration: const InputDecoration(labelText: 'عنوان الطابعة'),
          ),
        ],
      ),
      actions: <Widget>[
        TextButton(
          onPressed: () => Navigator.of(dialogContext).pop(false),
          child: const Text('إلغاء'),
        ),
        FilledButton(
          onPressed: () => Navigator.of(dialogContext).pop(true),
          child: const Text('حفظ وطباعة'),
        ),
      ],
    ),
  );

  if (confirmed != true) return null;
  final address = controller.text.trim();
  if (address.isEmpty) return null;
  await service.saveAddress(address);
  return address;
}
