part of 'generate_invoice_sheets.dart';

class _PickerTile extends StatelessWidget {
  const _PickerTile({
    required this.icon,
    required this.color,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final IconData icon;
  final Color color;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
        child: Row(
          children: <Widget>[
            AppGlyph(icon: icon, tone: color, size: 40),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(
                    title,
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 1),
                  Text(
                    subtitle,
                    style: theme.textTheme.bodySmall,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            Icon(
              Icons.chevron_left_rounded,
              size: 18,
              color: theme.colorScheme.onSurfaceVariant.withValues(alpha: .7),
            ),
          ],
        ),
      ),
    );
  }
}

// ─── الواجهات العامة الثلاث (تسليم Dev 4 / Dev 5) ──────────────────────────

/// فاتورة عداد — `GenerateMeterInvoice`.
/// يبني الـ Cubit ويهيّئه — يُستخدم من الدراج ومن مسار `/invoices/generate/:engine`.
class _GenerateSheetHost extends StatelessWidget {
  const _GenerateSheetHost({
    required this.mode,
    required this.parentContext,
    this.preselectedSubscriberId,
  });

  final GenerateInvoiceMode mode;
  final BuildContext parentContext;
  final String? preselectedSubscriberId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<InvoiceGenerationCubit>(
      create: (_) => InvoiceGenerationCubit(
            invoicesRepository: sl<InvoicesRepository>(),
            invoicesSource: sl<InvoicesRemoteDataSource>(),
            references: sl<ReferencesRemoteDataSource>(),
            tariffsRepository: sl<TariffsRepository>(),
          )
          ..bootstrap(preselectedSubscriberId: preselectedSubscriberId),
      child: _GenerateSheetBody(mode: mode, parentContext: parentContext),
    );
  }
}

class _GenerateSheetBody extends StatelessWidget {
  const _GenerateSheetBody({required this.mode, required this.parentContext});

  final GenerateInvoiceMode mode;
  final BuildContext parentContext;

  _SheetChrome _chrome() {

    return const _SheetChrome(
      title: 'إصدار فاتورة عداد',
      icon: Icons.calculate_outlined,
      color: FinanceColors.meter,
      submitLabel: 'إصدار الفاتورة',
    );
  }

  Future<void> _submit(BuildContext sheetContext, InvoiceGenerationCubit cubit) async {
    final InvoiceModel? invoice = await cubit.submitMeterInvoice();

    if (sheetContext.mounted) Navigator.of(sheetContext).pop();
    if (invoice == null || !parentContext.mounted) return;

    await _afterGenerated(parentContext, invoice);
  }

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<InvoiceGenerationCubit>();
    final chrome = _chrome();

    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.92,
      builder: (sheetContext, controller) => Material(
        color: Theme.of(sheetContext).scaffoldBackgroundColor,
        child: Column(
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 8, 8),
              child: Row(
                children: <Widget>[
                  AppGlyph(icon: chrome.icon, tone: chrome.color, size: 36),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      chrome.title,
                      style: Theme.of(sheetContext).textTheme.titleLarge?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                    ),
                  ),
                  AppIconButton(
                    tooltip: 'إغلاق',
                    size: 38,
                    onPressed: () => Navigator.of(sheetContext).pop(),
                    icon: Icons.close_rounded,
                  ),
                ],
              ),
            ),
            const Divider(height: 1),
            Expanded(
              child: ListView(
                controller: controller,
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
                children: <Widget>[
                  const SubscriberSearchField(),
                  const SizedBox(height: 12),
                  if (mode == GenerateInvoiceMode.meter) ...<Widget>[
                    const MeterPickerField(),
                    const SizedBox(height: 4),
                    const IncludeReadingsToggle(),
                    const SizedBox(height: 4),
                    const SizedBox(height: 12),
                  ]
                  ],
                  const GenerationNotesField(),
                  const SizedBox(height: 16),
                  const LiveSummaryCard(),
                  const SizedBox(height: 16),
                  BlocBuilder<InvoiceGenerationCubit, InvoiceGenerationState>(
                    builder: (context, state) => _GenerateSubmitBar(
                      state: state,
                      chrome: chrome,
                      onSubmit: () => _submit(sheetContext, cubit),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}


/// شكل الدراج حسب المحرّك (عنوان/أيقونة/لون/زر الإرسال).
/// شكل الدراج حسب المحرّك (عنوان/أيقونة/لون/زر الإرسال).
class _SheetChrome {
  const _SheetChrome({
    required this.title,
    required this.icon,
    required this.color,
    required this.submitLabel,
  });

  final String title;
  final IconData icon;
  final Color color;
  final String submitLabel;
}

/// صف الإرسال مع رسائل المنع (يطابق `ValidationException` في Laravel).
/// صف الإرسال مع رسائل المنع (يطابق `ValidationException` في Laravel).
class _GenerateSubmitBar extends StatelessWidget {
  const _GenerateSubmitBar({
    required this.state,
    required this.chrome,
    required this.onSubmit,
  });

  final InvoiceGenerationState state;
  final _SheetChrome chrome;
  final VoidCallback onSubmit;

  @override
  Widget build(BuildContext context) {
    final blocking = state.blockingReason;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        if (state.errorMessage != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              state.errorMessage!,
              style: const TextStyle(color: AppColors.danger, fontSize: 12),
            ),
          ),
        if (blocking != null && state.selectedSubscriber != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: InfoNotice(
              icon: Icons.block,
              color: AppColors.warning,
              text: blocking,
            ),
          ),
        AppPrimaryButton(
          label: chrome.submitLabel,
          icon: Icons.bolt_rounded,
          height: 48,
          loading: state.submitting,
          onPressed: (state.canSubmit && blocking == null && !state.submitting)
              ? onSubmit
              : null,
        ),
      ],
    );
  }
}

/// بطاقة الحساب الحي — تتحدث مع كل ضغطة رقم (قاعدة §3.4-2).
