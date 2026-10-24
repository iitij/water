import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/error/failures.dart';
import '../../../core/finance/finance_feedback.dart';
import '../../../core/finance/finance_locator.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/ui/app_surfaces.dart';
import '../../../core/ui/app_tokens.dart';
import '../../../core/utils/currency_formatter.dart';
import '../domain/cash_session.dart';
import '../services/cash_session_service.dart';

/// شاشة صندوق المحصّل: فتح، متابعة السندات، ثم إغلاق بعدّ النقد.
///
/// قواعد العمل الظاهرة للمستخدم:
/// * صندوق واحد مفتوح لكل محصّل.
/// * المبلغ المتوقع يحسبه الخادم من السندات الفعلية (بعد استثناء الملغاة).
/// * الفرق = المعدود − المتوقع؛ العجز سالب ويستلزم تفسيراً مكتوباً.
///
/// مرجع: خطة الإصدار 2.0 — الفصل 5.2.
class CashSessionScreen extends StatefulWidget {
  const CashSessionScreen({super.key});

  @override
  State<CashSessionScreen> createState() => _CashSessionScreenState();
}

class _CashSessionScreenState extends State<CashSessionScreen> {
  final _countedController = TextEditingController();
  final _notesController = TextEditingController();
  final _openingController = TextEditingController(text: '0');
  bool _busy = false;
  String? _error;

  CashSessionService get _service => sl<CashSessionService>();

  @override
  void initState() {
    super.initState();
    _service.addListener(_onServiceChanged);
    WidgetsBinding.instance.addPostFrameCallback((_) => _refresh());
  }

  @override
  void dispose() {
    _service.removeListener(_onServiceChanged);
    _countedController.dispose();
    _notesController.dispose();
    _openingController.dispose();
    super.dispose();
  }

  void _onServiceChanged() {
    if (mounted) setState(() {});
  }

  Future<void> _refresh() async {
    setState(() => _error = null);
    await _service.refresh();
    if (!mounted) return;
    final failure = _service.lastError;
    if (failure != null) setState(() => _error = failure.message);
  }

  Future<void> _run(Future<void> Function() action) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await action();
    } on AppFailure catch (failure) {
      if (mounted) setState(() => _error = failure.message);
    } on CashSessionRequiredException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (error) {
      if (mounted) setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _openSession() async {
    final opening = double.tryParse(_openingController.text.replaceAll(',', '')) ?? 0;
    if (opening < 0) {
      setState(() => _error = 'مبلغ الافتتاح لا يكون سالباً.');
      return;
    }
    await _run(() => _service.open(openingAmount: opening));
    if (!mounted || _service.isOpen == false) return;
    AppFeedback.success(context, 'تم فتح صندوق المحصّل. يمكنك الآن تسجيل التحصيل.');
  }

  Future<void> _closeSession() async {
    final session = _service.current;
    if (session == null) return;
    final counted = double.tryParse(_countedController.text.replaceAll(',', ''));
    if (counted == null || counted < 0) {
      setState(() => _error = 'أدخل المبلغ النقدي المعدود فعلياً.');
      return;
    }

    final expected = session.expectedAmount;
    final variance = counted - expected;
    final notes = _notesController.text.trim();

    if (variance.abs() >= 10 && notes.length < 10) {
      setState(() => _error =
          'الفرق ${variance.toStringAsFixed(2)} يتطلب تفسيراً مكتوباً (10 أحرف على الأقل).');
      return;
    }

    final confirmed = await ConfirmDialog.show(
      context,
      title: 'إغلاق الصندوق',
      message: 'سيُقفل الصندوق ويُرسل للاعتماد.',
      confirmLabel: 'إغلاق وإرسال',
      details: <String>[
        'المتوقع: ${CurrencyFormatter.amount(expected)}',
        'المعدود: ${CurrencyFormatter.amount(counted)}',
        variance.abs() < 0.01
            ? 'لا يوجد فرق.'
            : variance < 0
                ? 'عجز: ${CurrencyFormatter.amount(variance.abs())}'
                : 'زيادة: ${CurrencyFormatter.amount(variance)}',
      ],
    );
    if (!confirmed) return;

    await _run(
      () => _service.close(
        countedAmount: counted,
        notes: notes.isEmpty ? null : notes,
      ),
    );
    if (!mounted) return;
    if (_error == null) {
      _countedController.clear();
      _notesController.clear();
      AppFeedback.success(context, 'تم إغلاق الصندوق وحساب الفرق.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = _service.current;

    return RefreshIndicator(
      onRefresh: _refresh,
      child: AppPageBody(
        topPadding: 16,
        bottomPadding: 32,
        children: <Widget>[
          AppHeroPanel(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Text('صندوق المحصّل', style: Theme.of(context).textTheme.titleLarge),
                const Gap(6),
                Text(
                  _service.isOpen
                      ? 'الصندوق مفتوح — كل سند قبض تسجله اليوم يُحتسب في هذا الصندوق.'
                      : 'افتح صندوقاً قبل التحصيل ليُطابق النقد المسلَّم مع السندات.',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                if (_service.isLoading) ...<Widget>[
                  const Gap(AppSpacing.md),
                  const LinearProgressIndicator(),
                ],
              ],
            ),
          ),
          const Gap(AppSpacing.md),
          if (_error != null) ...<Widget>[
            AppCard(
              borderColor: AppColors.danger,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  const Icon(Icons.error_outline, color: AppColors.danger),
                  const Gap(8),
                  Expanded(child: Text(_error!)),
                ],
              ),
            ),
            const Gap(AppSpacing.md),
          ],
          if (session == null)
            _OpenForm(
              controller: _openingController,
              busy: _busy,
              onOpen: _openSession,
            )
          else if (session.isOpen)
            _OpenSessionCard(
              session: session,
              countedController: _countedController,
              notesController: _notesController,
              busy: _busy,
              onClose: _closeSession,
            )
          else
            _ClosedSummaryCard(session: session),
        ],
      ),
    );
  }
}

class _OpenForm extends StatelessWidget {
  const _OpenForm({
    required this.controller,
    required this.busy,
    required this.onOpen,
  });

  final TextEditingController controller;
  final bool busy;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text('فتح صندوق جديد', style: Theme.of(context).textTheme.titleMedium),
          const Gap(4),
          const Text('المبلغ الافتتاحي هو النقد الموجود معك قبل أول سند (عهدة فكّة).'),
          const Gap(AppSpacing.md),
          TextField(
            controller: controller,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            inputFormatters: <TextInputFormatter>[
              FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]')),
            ],
            decoration: const InputDecoration(
              labelText: 'المبلغ الافتتاحي',
              suffixText: 'ريال',
              border: OutlineInputBorder(),
            ),
          ),
          const Gap(AppSpacing.md),
          FilledButton.icon(
            onPressed: busy ? null : onOpen,
            icon: const Icon(Icons.lock_open_rounded),
            label: Text(busy ? 'جارٍ الفتح…' : 'فتح الصندوق'),
          ),
        ],
      ),
    );
  }
}

class _OpenSessionCard extends StatelessWidget {
  const _OpenSessionCard({
    required this.session,
    required this.countedController,
    required this.notesController,
    required this.busy,
    required this.onClose,
  });

  final CashSession session;
  final TextEditingController countedController;
  final TextEditingController notesController;
  final bool busy;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        AppCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Row(
                children: <Widget>[
                  const Icon(Icons.account_balance_wallet_outlined),
                  const Gap(8),
                  Expanded(
                    child: Text(
                      'صندوق ${session.id} — ${session.sessionDate.toIso8601String().split('T').first}',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                  ),
                ],
              ),
              const Gap(AppSpacing.md),
              _AmountRow(label: 'الافتتاحي', value: session.openingAmount),
              _AmountRow(label: 'المتوقع من السندات', value: session.expectedAmount),
              _AmountRow(label: 'عدد السندات', value: session.receipts.toDouble(), money: false),
              if (session.voidedReceipts > 0)
                _AmountRow(
                  label: 'سندات ملغاة (مستثناة)',
                  value: session.voidedReceipts.toDouble(),
                  money: false,
                ),
              if (session.byMethod.isNotEmpty) ...<Widget>[
                const Gap(AppSpacing.sm),
                ...session.byMethod.map(
                  (row) => Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Text(
                      '${row.arabicLabel}: ${row.receipts} سنداً — '
                      '${CurrencyFormatter.amount(row.total)}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
        const Gap(AppSpacing.md),
        AppCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text('إغلاق الصندوق', style: Theme.of(context).textTheme.titleMedium),
              const Gap(4),
              const Text('عُدّ النقد فعلياً؛ الفرق يُحسب تلقائياً ويُسجَّل في سجل التدقيق.'),
              const Gap(AppSpacing.md),
              TextField(
                controller: countedController,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: <TextInputFormatter>[
                  FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]')),
                ],
                decoration: const InputDecoration(
                  labelText: 'المبلغ المعدود نقداً',
                  suffixText: 'ريال',
                  border: OutlineInputBorder(),
                ),
              ),
              const Gap(AppSpacing.sm),
              TextField(
                controller: notesController,
                maxLines: 2,
                maxLength: 1000,
                decoration: const InputDecoration(
                  labelText: 'تفسير الفرق / ملاحظات الإغلاق',
                  border: OutlineInputBorder(),
                ),
              ),
              const Gap(AppSpacing.sm),
              FilledButton.icon(
                onPressed: busy ? null : onClose,
                icon: const Icon(Icons.lock_rounded),
                label: Text(busy ? 'جارٍ الإغلاق…' : 'إغلاق وإرسال للاعتماد'),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _ClosedSummaryCard extends StatelessWidget {
  const _ClosedSummaryCard({required this.session});

  final CashSession session;

  @override
  Widget build(BuildContext context) {
    final variance = session.varianceAmount ?? 0;
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(
            'صندوق ${session.id} — ${session.state.arabicLabel}',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const Gap(AppSpacing.md),
          _AmountRow(label: 'المتوقع', value: session.expectedAmount),
          _AmountRow(label: 'المعدود', value: session.countedAmount ?? 0),
          _AmountRow(label: 'الفرق', value: variance),
          const Gap(AppSpacing.sm),
          Text(
            variance.abs() < 0.01
                ? 'الصندوق مطابق تماماً.'
                : variance < 0
                    ? 'يوجد عجز بمقدار ${CurrencyFormatter.amount(variance.abs())}.'
                    : 'توجد زيادة بمقدار ${CurrencyFormatter.amount(variance)}.',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: variance.abs() < 0.01 ? null : AppColors.danger,
                ),
          ),
          if ((session.closingNotes ?? '').isNotEmpty) ...<Widget>[
            const Gap(AppSpacing.sm),
            Text('ملاحظات: ${session.closingNotes}'),
          ],
          if (session.state == CashSessionState.rejected &&
              (session.approvalNotes ?? '').isNotEmpty) ...<Widget>[
            const Gap(AppSpacing.sm),
            Text('سبب الرفض: ${session.approvalNotes}'),
          ],
        ],
      ),
    );
  }
}

class _AmountRow extends StatelessWidget {
  const _AmountRow({required this.label, required this.value, this.money = true});

  final String label;
  final double value;
  final bool money;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: <Widget>[
          Expanded(child: Text(label, style: Theme.of(context).textTheme.bodyMedium)),
          Text(
            money ? CurrencyFormatter.amount(value) : value.toStringAsFixed(0),
            style: Theme.of(context)
                .textTheme
                .titleSmall
                ?.copyWith(fontFeatures: const <FontFeature>[FontFeature.tabularFigures()]),
          ),
        ],
      ),
    );
  }
}
