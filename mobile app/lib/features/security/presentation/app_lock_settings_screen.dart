import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/di/injection_container.dart';
import '../../../core/finance/finance_feedback.dart';
import '../../../core/security/app_lock_service.dart';
import '../../../core/security/pin_hasher.dart';
import '../../../core/ui/app_surfaces.dart';
import '../../../core/ui/app_tokens.dart';

/// إعداد رقم حماية التطبيق (بطاقة S5-01).
///
/// الشاشة لا تعرض الرقم المحفوظ أبداً؛ تسمح بالتعيين والتغيير والحذف فقط.
class AppLockSettingsScreen extends StatefulWidget {
  const AppLockSettingsScreen({super.key, this.service});

  final AppLockService? service;

  @override
  State<AppLockSettingsScreen> createState() => _AppLockSettingsScreenState();
}

class _AppLockSettingsScreenState extends State<AppLockSettingsScreen> {
  final _current = TextEditingController();
  final _next = TextEditingController();
  final _confirm = TextEditingController();

  bool _busy = false;
  String? _error;

  AppLockService get _lock => widget.service ?? getIt<AppLockService>();

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final next = _next.text.trim();
    final confirm = _confirm.text.trim();

    if (!PinHasher.isValidPin(next)) {
      setState(() => _error = 'رقم الحماية من 4 إلى 8 أرقام.');
      return;
    }
    if (next != confirm) {
      setState(() => _error = 'الرقمان غير متطابقين.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await _lock.setPin(
        next,
        currentPin: _lock.hasPin ? _current.text.trim() : null,
      );
      if (!mounted) return;
      _current.clear();
      _next.clear();
      _confirm.clear();
      AppFeedback.success(context, 'تم تفعيل قفل التطبيق. ستحتاج الرقم عند كل فتح.');
    } catch (error) {
      if (mounted) {
        setState(() => _error = error is ArgumentError
            ? error.message.toString()
            : 'تعذّر تعيين الرقم. تحقّق من الرقم الحالي.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _remove() async {
    final current = _current.text.trim();
    if (_lock.hasPin) {
      final result = await _lock.unlock(current);
      if (result != UnlockResult.success) {
        if (mounted) setState(() => _error = 'أدخل الرقم الحالي لحذف القفل.');
        return;
      }
    }

    await _lock.clearPin();
    if (!mounted) return;
    _current.clear();
    AppFeedback.info(context, 'أُزيل قفل التطبيق.');
    setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _lock,
      builder: (context, _) => AppPageBody(
        topPadding: 16,
        bottomPadding: 32,
        children: <Widget>[
          AppHeroPanel(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Text('قفل التطبيق', style: Theme.of(context).textTheme.titleLarge),
                const Gap(6),
                Text(
                  _lock.hasPin
                      ? 'القفل مفعّل. يُغلق التطبيق تلقائياً بعد ${_lock.idleTimeout.inMinutes} دقائق بلا استخدام.'
                      : 'لا يوجد رقم حماية. من يمسك الجهاز يستطيع فتح التطبيق كاملاً.',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              ],
            ),
          ),
          const Gap(AppSpacing.md),
          AppCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                if (_lock.hasPin) ...<Widget>[
                  _pinField(
                    controller: _current,
                    label: 'الرقم الحالي',
                  ),
                  const Gap(AppSpacing.sm),
                ],
                _pinField(
                  controller: _next,
                  label: _lock.hasPin ? 'الرقم الجديد' : 'رقم الحماية',
                ),
                const Gap(AppSpacing.sm),
                _pinField(controller: _confirm, label: 'تأكيد الرقم'),
                if (_error != null) ...<Widget>[
                  const Gap(AppSpacing.sm),
                  Text(
                    _error!,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: Theme.of(context).colorScheme.error,
                        ),
                  ),
                ],
                const Gap(AppSpacing.md),
                FilledButton.icon(
                  onPressed: _busy ? null : _save,
                  icon: const Icon(Icons.lock_rounded),
                  label: Text(_lock.hasPin ? 'تغيير الرقم' : 'تفعيل القفل'),
                ),
                if (_lock.hasPin) ...<Widget>[
                  const Gap(AppSpacing.sm),
                  OutlinedButton.icon(
                    onPressed: _busy ? null : _remove,
                    icon: const Icon(Icons.lock_open_rounded),
                    label: const Text('إزالة القفل'),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _pinField({required TextEditingController controller, required String label}) {
    return TextField(
      controller: controller,
      obscureText: true,
      keyboardType: TextInputType.number,
      maxLength: 8,
      inputFormatters: <TextInputFormatter>[FilteringTextInputFormatter.digitsOnly],
      decoration: InputDecoration(
        labelText: label,
        counterText: '',
        border: const OutlineInputBorder(),
      ),
    );
  }
}
