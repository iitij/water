import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/di/injection_container.dart';
import '../../../core/security/app_lock_service.dart';
import '../../../core/security/pin_hasher.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/ui/app_surfaces.dart';
import '../../../core/ui/app_tokens.dart';

/// بوابة القفل: تُلبس كل التطبيق فلا يمكن تجاوزها بالتنقل.
///
/// تبقى الشجرة تحتها حيّة (Stack) حتى لا يفقد المستخدم مكانه في الشاشة بعد
/// الفتح، لكن اللمس يُحجب تماماً وطبقة القفل معتمة.
class LockGate extends StatefulWidget {
  const LockGate({super.key, required this.child, this.service});

  final Widget child;

  /// حقن للاختبارات؛ الافتراضي الخدمة المسجّلة في الحاوية.
  final AppLockService? service;

  @override
  State<LockGate> createState() => _LockGateState();
}

class _LockGateState extends State<LockGate> with WidgetsBindingObserver {
  AppLockService get _lock => widget.service ?? getIt<AppLockService>();

  Timer? _idleTicker;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    if (!_lock.isLoaded) unawaited(_lock.load());
    // فحص الخمول أثناء الاستخدام (شاشة مفتوحة بلا لمس) كل 30 ثانية.
    _idleTicker = Timer.periodic(const Duration(seconds: 30), (_) {
      _lock.checkIdle();
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _idleTicker?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _lock.checkIdle();
      if (mounted) setState(() {});
    } else if (state == AppLifecycleState.paused) {
      // مغادرة التطبيق ليست تلقائياً انتهاء خمول؛ لكنها تُحسب عند العودة.
      _lock.checkIdle();
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _lock,
      builder: (context, _) {
        final locked = _lock.isLocked && _lock.hasPin;
        return Listener(
          behavior: HitTestBehavior.translucent,
          onPointerDown: (_) => _lock.recordActivity(),
          child: Stack(
            children: <Widget>[
              AbsorbPointer(absorbing: locked, child: widget.child),
              if (locked)
                Positioned.fill(
                  child: LockScreen(service: _lock),
                ),
            ],
          ),
        );
      },
    );
  }
}

/// شاشة إدخال رقم الحماية.
class LockScreen extends StatefulWidget {
  const LockScreen({super.key, required this.service});

  final AppLockService service;

  @override
  State<LockScreen> createState() => _LockScreenState();
}

class _LockScreenState extends State<LockScreen> {
  final TextEditingController _pin = TextEditingController();
  final FocusNode _focus = FocusNode();

  bool _busy = false;
  String? _error;
  Timer? _countdown;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _focus.requestFocus());
  }

  @override
  void dispose() {
    _countdown?.cancel();
    _pin.dispose();
    _focus.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy || widget.service.isLockedOut) return;
    final pin = _pin.text.trim();
    if (!PinHasher.isValidPin(pin)) {
      setState(() => _error = 'أدخل رقم الحماية (4 أرقام على الأقل).');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final result = await widget.service.unlock(pin);
    if (!mounted) return;

    setState(() {
      _busy = false;
      if (result == UnlockResult.success) {
        _pin.clear();
        _error = null;
      } else if (result == UnlockResult.lockedOut) {
        _pin.clear();
        _error = 'محاولات كثيرة خاطئة. انتظر قليلاً ثم أعد المحاولة.';
        _startCountdown();
      } else if (result == UnlockResult.wrongPin) {
        _pin.clear();
        _error = 'الرقم غير صحيح. المحاولات المتبقية: '
            '${widget.service.maxAttempts - widget.service.failedAttempts}';
      } else {
        _error = null;
      }
    });
  }

  void _startCountdown() {
    _countdown?.cancel();
    _countdown = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      if (!widget.service.isLockedOut) {
        timer.cancel();
        setState(() => _error = null);
        return;
      }
      setState(() {});
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final remaining = widget.service.lockoutRemaining;

    return Material(
      color: AppColors.panel,
      child: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 380),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  const Icon(Icons.lock_rounded, size: 42, color: AppColors.accentOnPanel),
                  const Gap(AppSpacing.md),
                  Text(
                    'التطبيق مقفل',
                    textAlign: TextAlign.center,
                    style: theme.textTheme.titleLarge?.copyWith(color: AppColors.onPanel),
                  ),
                  const Gap(6),
                  Text(
                    'أدخل رقم الحماية للمتابعة. الرقم يُخزَّن مشفّراً على هذا الجهاز فقط.',
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodySmall?.copyWith(color: AppColors.onPanelSoft),
                  ),
                  const Gap(AppSpacing.lg),
                  TextField(
                    controller: _pin,
                    focusNode: _focus,
                    enabled: !widget.service.isLockedOut,
                    obscureText: true,
                    keyboardType: TextInputType.number,
                    textAlign: TextAlign.center,
                    maxLength: 8,
                    onSubmitted: (_) => _submit(),
                    inputFormatters: <TextInputFormatter>[
                      FilteringTextInputFormatter.digitsOnly,
                    ],
                    decoration: InputDecoration(
                      counterText: '',
                      hintText: '••••',
                      filled: true,
                      fillColor: AppColors.panelElevated,
                      errorText: _error,
                    ),
                    style: const TextStyle(color: AppColors.onPanel, letterSpacing: 6),
                  ),
                  if (remaining > Duration.zero) ...<Widget>[
                    const Gap(AppSpacing.sm),
                    Text(
                      'أعد المحاولة بعد ${remaining.inSeconds} ثانية',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodySmall?.copyWith(color: AppColors.onPanelMuted),
                    ),
                  ],
                  const Gap(AppSpacing.md),
                  FilledButton.icon(
                    onPressed: (_busy || widget.service.isLockedOut) ? null : _submit,
                    icon: const Icon(Icons.lock_open_rounded),
                    label: Text(_busy ? 'جارٍ التحقق…' : 'فتح'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
