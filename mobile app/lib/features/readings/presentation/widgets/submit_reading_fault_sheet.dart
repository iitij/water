part of '../pages/submit_reading_screen.dart';

/// مسودة تعذّر القراءة قبل الحفظ.
class _FaultDraft {
  const _FaultDraft({required this.reason, this.note});

  final ReadingFaultReason reason;
  final String? note;
}

/// ورقة اختيار سبب تعذّر القراءة.
///
/// السبب إلزامي لأن «تعذّر» بلا سبب لا يُساعد المشرف على إرسال فني أو
/// مراجعة المشترك؛ والملاحظة حرّة وتلزم فقط مع «سبب آخر».
class _FaultSheet extends StatefulWidget {
  const _FaultSheet({required this.meterCode, this.subscriberName});

  final String meterCode;
  final String? subscriberName;

  @override
  State<_FaultSheet> createState() => _FaultSheetState();
}

class _FaultSheetState extends State<_FaultSheet> {
  final _noteController = TextEditingController();
  ReadingFaultReason? _reason;
  String? _error;

  @override
  void dispose() {
    _noteController.dispose();
    super.dispose();
  }

  void _submit() {
    final reason = _reason;
    if (reason == null) {
      setState(() => _error = 'اختر سبب تعذّر القراءة.');
      return;
    }
    final note = _noteController.text.trim();
    if (reason.needsNote && note.isEmpty) {
      setState(() => _error = 'اكتب سبباً واضحاً لتعذّر القراءة.');
      return;
    }
    Navigator.pop(
      context,
      _FaultDraft(reason: reason, note: note.isEmpty ? null : note),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Padding(
        padding: EdgeInsets.only(
          left: 16,
          right: 16,
          top: 16,
          bottom: MediaQuery.of(context).viewInsets.bottom + 16,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('تعذّر قراءة العداد', style: theme.textTheme.titleLarge),
            const SizedBox(height: 4),
            Text(
              'العداد ${widget.meterCode}'
              '${widget.subscriberName == null ? '' : ' — ${widget.subscriberName}'}',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            for (final reason in ReadingFaultReason.values)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: Icon(
                  _reason == reason
                      ? Icons.radio_button_checked
                      : Icons.radio_button_unchecked,
                  color: _reason == reason ? theme.colorScheme.primary : null,
                ),
                title: Text(reason.label),
                onTap: () => setState(() {
                  _reason = reason;
                  _error = null;
                }),
              ),
            const SizedBox(height: 8),
            TextField(
              controller: _noteController,
              maxLines: 2,
              maxLength: 200,
              decoration: InputDecoration(
                labelText: _reason == null || _reason!.needsNote
                    ? 'ملاحظة (مطلوبة مع «سبب آخر»)'
                    : 'ملاحظة اختيارية',
                hintText: 'مثال: الزجاج مكسور والمشترك طلب زيارة فني',
              ),
            ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  _error!,
                  style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.error),
                ),
              ),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text('إلغاء'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: FilledButton(
                    onPressed: _submit,
                    child: const Text('تسجيل التعذّر'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
