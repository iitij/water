part of '../home_screen.dart';

/// Rebuilds the summary panel when the dashboard snapshot changes, without
/// rebuilding the rest of the page.
class _SummarySlot extends StatelessWidget {
  const _SummarySlot({
    required this.cubit,
    required this.roleLabel,
    required this.isOnline,
    required this.pendingSync,
    required this.primaryLabel,
    required this.primaryIcon,
    required this.onPrimary,
    required this.canViewDashboard,
    required this.canViewInvoices,
    required this.onDashboard,
  });

  final DashboardCubit? cubit;
  final String roleLabel;
  final bool? isOnline;
  final int pendingSync;
  final String primaryLabel;
  final IconData primaryIcon;
  final VoidCallback onPrimary;
  final bool canViewDashboard;
  final bool canViewInvoices;
  final VoidCallback onDashboard;

  @override
  Widget build(BuildContext context) {
    final active = cubit;
    if (active == null) {
      return _BalancePanel(
        roleLabel: roleLabel,
        isOnline: isOnline,
        pendingSync: pendingSync,
        primaryLabel: primaryLabel,
        primaryIcon: primaryIcon,
        onPrimary: onPrimary,
        canViewDashboard: canViewDashboard,
        canViewInvoices: canViewInvoices,
        onDashboard: onDashboard,
      );
    }
    return BlocBuilder<DashboardCubit, DashboardState>(
      buildWhen: (previous, current) => previous.snapshot != current.snapshot,
      builder: (context, state) {
        final stats = state.snapshot?.data.stats;
        return _BalancePanel(
          roleLabel: roleLabel,
          isOnline: isOnline,
          pendingSync: pendingSync,
          primaryLabel: primaryLabel,
          primaryIcon: primaryIcon,
          onPrimary: onPrimary,
          canViewDashboard: canViewDashboard,
          canViewInvoices: canViewInvoices,
          onDashboard: onDashboard,
          outstanding: stats?.outstandingAmount,
          collected: stats?.monthlyCollection,
          unpaidInvoices: stats?.unpaidInvoicesCount,
          overdueInvoices: stats?.overdueInvoicesCount,
        );
      },
    );
  }
}

/// The page's single dark surface: outstanding balance, collection progress and
/// the two actions that matter today.
/// The page's single dark surface: outstanding balance, collection progress and
/// the two actions that matter today.
class _BalancePanel extends StatelessWidget {
  const _BalancePanel({
    required this.roleLabel,
    required this.isOnline,
    required this.pendingSync,
    required this.primaryLabel,
    required this.primaryIcon,
    required this.onPrimary,
    required this.canViewDashboard,
    required this.canViewInvoices,
    required this.onDashboard,
    this.outstanding,
    this.collected,
    this.unpaidInvoices,
    this.overdueInvoices,
  });

  final String roleLabel;
  final bool? isOnline;
  final int pendingSync;
  final String primaryLabel;
  final IconData primaryIcon;
  final VoidCallback onPrimary;
  final bool canViewDashboard;
  final bool canViewInvoices;
  final VoidCallback onDashboard;
  final double? outstanding;
  final double? collected;
  final int? unpaidInvoices;
  final int? overdueInvoices;

  static const Color _panelAccent = AppColors.accentOnPanel;
  static const Color _onPanel = AppColors.onPanel;
  static const Color _onPanelSoft = AppColors.onPanelSoft;
  static const Color _onPanelMuted = AppColors.onPanelMuted;

  double get _ratio {
    final due = outstanding ?? 0;
    final paid = collected ?? 0;
    final total = due + paid;
    if (total <= 0) return 0;
    return (paid / total).clamp(0, 1).toDouble();
  }

  bool get _hasNumbers => outstanding != null;

  @override
  Widget build(BuildContext context) {
    final ratio = _ratio;
    final percent = (ratio * 100).round();
    final connection = isOnline == true
        ? 'متصل'
        : isOnline == false
            ? 'أوفلاين'
            : 'جارٍ الفحص';

    return AppHeroPanel(
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  _hasNumbers ? 'إجمالي الديون المستحقة' : 'مساحة العمل اليوم',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: _onPanelSoft,
                    fontSize: 11.5,
                    fontWeight: FontWeight.w500,
                    height: 1.3,
                  ),
                ),
              ),
              Container(
                width: 6,
                height: 6,
                decoration: BoxDecoration(
                  color: isOnline == false ? AppColors.warning : _panelAccent,
                  shape: BoxShape.circle,
                ),
              ),
              const SizedBox(width: 6),
              Text(
                connection,
                style: const TextStyle(
                  color: _onPanelMuted,
                  fontSize: 10.5,
                  height: 1.3,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          if (_hasNumbers)
            _AmountLine(value: CurrencyFormatter.amount(outstanding))
          else
            Text(
              pendingSync > 0 ? '$pendingSync عملية بانتظار المزامنة' : 'لا مهام معلقة اليوم',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                color: _onPanel,
                fontSize: 20,
                fontWeight: FontWeight.w600,
                height: 1.3,
              ),
            ),
          if (_hasNumbers) ...<Widget>[
            const SizedBox(height: 3),
            HomeInvoiceCounts(
              unpaid: unpaidInvoices ?? 0,
              overdue: overdueInvoices ?? 0,
              canViewInvoices: canViewInvoices,
            ),
            const SizedBox(height: 14),
            ClipRRect(
              borderRadius: BorderRadius.circular(AppRadius.pill),
              child: TweenAnimationBuilder<double>(
                tween: Tween<double>(begin: 0, end: ratio),
                duration: AppMotion.slow,
                curve: Curves.easeOutCubic,
                builder: (context, value, _) => LinearProgressIndicator(
                  value: value,
                  minHeight: 4,
                  backgroundColor: Colors.white.withValues(alpha: .12),
                  valueColor: const AlwaysStoppedAnimation<Color>(_panelAccent),
                ),
              ),
            ),
            const SizedBox(height: 8),
            Row(
              children: <Widget>[
                Expanded(
                  child: Text(
                    'تم تحصيل $percent% من المستحقات',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: _onPanelMuted, fontSize: 10, height: 1.4),
                  ),
                ),
                Text(
                  '${CurrencyFormatter.amount(collected ?? 0)} ر.ي',
                  style: const TextStyle(color: _onPanelSoft, fontSize: 10.5, height: 1.4),
                ),
              ],
            ),
          ] else ...<Widget>[
            const SizedBox(height: 2),
            Text(
              roleLabel,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: _onPanelMuted, fontSize: 10.5, height: 1.4),
            ),
          ],
          const SizedBox(height: 16),
          LayoutBuilder(
            builder: (context, constraints) {
              final primary = _PanelButton(
                label: primaryLabel,
                icon: primaryIcon,
                primary: true,
                onPressed: onPrimary,
              );
              if (!canViewDashboard || constraints.maxWidth < 300) {
                return primary;
              }
              return Row(
                children: <Widget>[
                  Expanded(flex: 3, child: primary),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 2,
                    child: _PanelButton(
                      label: 'لوحة القيادة',
                      icon: Icons.insights_rounded,
                      primary: false,
                      onPressed: onDashboard,
                    ),
                  ),
                ],
              );
            },
          ),
        ],
      ),
    );
  }
}

class _AmountLine extends StatelessWidget {
  const _AmountLine({required this.value});

  final String value;

  @override
  Widget build(BuildContext context) {
    return Row(
      textDirection: TextDirection.rtl,
      crossAxisAlignment: CrossAxisAlignment.end,
      children: <Widget>[
        Flexible(
          child: FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerEnd,
            child: Directionality(
              textDirection: TextDirection.ltr,
              child: Text(
                value,
                maxLines: 1,
                style: const TextStyle(
                  color: AppColors.onPanel,
                  fontSize: 31,
                  fontWeight: FontWeight.w600,
                  height: 1.1,
                  letterSpacing: -.6,
                ),
              ),
            ),
          ),
        ),
        const SizedBox(width: 5),
        const Padding(
          padding: EdgeInsets.only(bottom: 4),
          child: Text(
            'ر.ي',
            style: TextStyle(
              color: AppColors.onPanelMuted,
              fontSize: 11.5,
              fontWeight: FontWeight.w500,
            ),
          ),
        ),
      ],
    );
  }
}

class _PanelButton extends StatelessWidget {
  const _PanelButton({
    required this.label,
    required this.icon,
    required this.primary,
    required this.onPressed,
  });

  final String label;
  final IconData icon;
  final bool primary;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    const radius = BorderRadius.all(Radius.circular(AppRadius.md));
    final foreground = primary ? AppColors.panel : AppColors.onPanelSoft;
    return SizedBox(
      height: 44,
      child: Material(
        color: primary ? AppColors.accentOnPanel : Colors.transparent,
        borderRadius: radius,
        child: InkWell(
          onTap: onPressed,
          borderRadius: radius,
          splashColor: primary ? Colors.black12 : Colors.white12,
          child: Container(
            decoration: BoxDecoration(
              borderRadius: radius,
              border: primary ? null : Border.all(color: Colors.white.withValues(alpha: .18)),
            ),
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: <Widget>[
                Icon(icon, size: 16, color: foreground),
                const SizedBox(width: 6),
                Flexible(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: foreground,
                      fontSize: 12.5,
                      fontWeight: primary ? FontWeight.w600 : FontWeight.w500,
                      height: 1.2,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

