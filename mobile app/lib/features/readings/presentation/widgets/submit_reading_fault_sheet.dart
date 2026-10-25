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
          top: 8,
          bottom: MediaQuery.of(context).viewInsets.bottom,
        ),
        child: SafeArea(
          top: false,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 36,
                    height: 4,
                    decoration: BoxDecoration(
                      color: theme.dividerColor,
                      borderRadius: BorderRadius.circular(AppRadius.pill),
                    ),
                  ),
                ),
                const SizedBox(height: 14),
                Text(
                  'تعذّر قراءة العداد',
                  style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 4),
                Text(
                  'العداد ${widget.meterCode}'
                  '${widget.subscriberName == null ? '' : ' — ${widget.subscriberName}'}',
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: 12),
                AppCard(
                  padding: EdgeInsets.zero,
                  child: Column(
                    children: <Widget>[
                      for (var index = 0;
                          index < ReadingFaultReason.values.length;
                          index++) ...<Widget>[
                        if (index > 0) const AppDivider(indent: 14, endIndent: 14),
                        _reasonRow(ReadingFaultReason.values[index]),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 12),
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
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: AppSecondaryButton(
                        label: 'إلغاء',
                        onPressed: () => Navigator.pop(context),
                        expand: false,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: AppPrimaryButton(
                        label: 'تسجيل التعذّر',
                        onPressed: _submit,
                        expand: false,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _reasonRow(ReadingFaultReason reason) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final selected = _reason == reason;
    return InkWell(
      onTap: () => setState(() {
        _reason = reason;
        _error = null;
      }),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
        child: Row(
          children: <Widget>[
            Icon(
              selected
                  ? Icons.radio_button_checked_rounded
                  : Icons.radio_button_unchecked_rounded,
              size: 20,
              color: selected ? scheme.primary : scheme.onSurfaceVariant.withValues(alpha: .6),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                reason.label,
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: selected ? FontWeight.w600 : FontWeight.w400,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
