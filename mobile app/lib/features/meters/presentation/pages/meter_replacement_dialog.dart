import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../../data/models/meter.dart';
import '../../domain/meters_repository.dart';

/// Replacements permanently delete the previous meter's data. Online only.
class MeterReplacementDialog extends StatefulWidget {
  const MeterReplacementDialog({required this.meter, required this.repository, super.key});

  final Meter meter;
  final MetersRepository repository;

  @override
  State<MeterReplacementDialog> createState() => _MeterReplacementDialogState();
}

class _MeterReplacementDialogState extends State<MeterReplacementDialog> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _path = TextEditingController(
    text: widget.meter.pathNumber?.toString() ?? '',
  );
  final _code = TextEditingController();
  final _initial = TextEditingController(text: '0');
  final _reason = TextEditingController();
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _path.dispose();
    _code.dispose();
    _initial.dispose();
    _reason.dispose();
    super.dispose();
  }

  String _message(Object error) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map) {
        final errors = data['errors'];
        if (errors is Map && errors.isNotEmpty) {
          final message = errors.values.first;
          if (message is List && message.isNotEmpty) return message.first.toString();
        }
        if (data['message'] != null) return data['message'].toString();
      }
      if (error.response == null) return 'لا يوجد اتصال بالخادم. استبدال العداد يتطلب الإنترنت.';
    }
    return 'تعذر استبدال العداد. تحقق من البيانات والمسار واتصال الخادم.';
  }

  Future<void> _replace() async {
    if (_saving || !(_form.currentState?.validate() ?? false)) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final replacement = await widget.repository.replaceMeter(
        meterId: widget.meter.id,
        uniqueCode: _code.text.trim(),
        pathNumber: _path.text.trim().isEmpty ? null : int.parse(_path.text.trim()),
        initialReading: int.parse(_initial.text.trim()),
        reason: _reason.text.trim(),
        removedAt: DateTime.now().toIso8601String().split('T').first,
      );
      if (!mounted) return;
      Navigator.of(context).pop(replacement);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = _message(error);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('استبدال العداد الحالي'),
      content: SizedBox(
        width: 440,
        child: SingleChildScrollView(
          child: Form(
            key: _form,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('العداد السابق: ${widget.meter.uniqueCode}'),
                const SizedBox(height: 8),
                const Text(
                  'سيُحذف العداد السابق نهائياً مع قراءاته وفواتيره وسنداته وبياناته المرتبطة. '
                  'لن يُحتفظ بسجل تاريخي للعداد السابق. '
                  'لا يمكن التراجع عن العملية، وتتطلب اتصالاً بالخادم.',
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _code,
                  decoration: const InputDecoration(labelText: 'رقم العداد الجديد'),
                  textInputAction: TextInputAction.next,
                  validator: (text) {
                    if (text == null || text.trim().isEmpty) return 'أدخل رقم العداد الجديد';
                    if (text.trim() == widget.meter.uniqueCode) return 'يجب إدخال رقم عداد مختلف';
                    return null;
                  },
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _path,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: 'رقم المسار (اختياري)'),
                  validator: (text) {
                    if (text == null || text.trim().isEmpty) return null;
                    final value = int.tryParse(text.trim());
                    return value == null || value < 0 ? 'رقم المسار غير صحيح' : null;
                  },
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _initial,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: 'القراءة الابتدائية للعداد الجديد'),
                  validator: (text) {
                    final value = int.tryParse(text?.trim() ?? '');
                    return value == null || value < 0 ? 'أدخل قراءة صحيحة غير سالبة' : null;
                  },
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _reason,
                  maxLines: 2,
                  decoration: const InputDecoration(labelText: 'سبب الاستبدال'),
                  validator: (text) =>
                      text == null || text.trim().length < 3 ? 'اكتب سبب الاستبدال' : null,
                ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                ],
              ],
            ),
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _saving ? null : () => Navigator.of(context).pop(),
          child: const Text('إلغاء'),
        ),
        FilledButton(
          onPressed: _saving ? null : _replace,
          child: Text(_saving ? 'جارٍ الاستبدال…' : 'استبدال العداد'),
        ),
      ],
    );
  }
}
