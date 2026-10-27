import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../l10n/experience_strings.dart';
import '../theme/app_colors.dart';
import '../theme/app_theme.dart';
import '../ui/app_controls.dart';
import '../ui/app_tokens.dart';
import '../widgets/offline_status_bar.dart';

/// Frame for pushed (non-tab) pages: a compact branded header with a back
/// button, optional subtitle and trailing actions.
class SubPageScaffold extends StatelessWidget {
  const SubPageScaffold({
    super.key,
    required this.title,
    required this.child,
    this.subtitle,
    this.actions = const <Widget>[],
  });

  final String title;
  final String? subtitle;
  final Widget child;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final stackedActions =
        MediaQuery.sizeOf(context).width < 430 && actions.length > 2;
    return Scaffold(
      body: Column(
        children: <Widget>[
          Container(
            decoration: BoxDecoration(
              color: theme.colorScheme.surface,
              border: Border(
                bottom: BorderSide(color: theme.colorScheme.outlineVariant),
              ),
            ),
            child: SafeArea(
              bottom: false,
              child: Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(8, 8, 16, 10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: <Widget>[
                        AppIconButton(
                          icon: Icons.arrow_forward_rounded,
                          tooltip: ExperienceStrings.back,
                          size: 48,
                          onPressed: () {
                            if (context.canPop()) {
                              context.pop();
                            } else {
                              context.go('/home');
                            }
                          },
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: <Widget>[
                              Text(
                                title,
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                                style: theme.textTheme.titleLarge?.copyWith(
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              if (subtitle != null) ...<Widget>[
                                const SizedBox(height: 1),
                                Text(
                                  subtitle!,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: theme.textTheme.labelSmall,
                                ),
                              ],
                            ],
                          ),
                        ),
                        if (!stackedActions) ...actions,
                      ],
                    ),
                    if (stackedActions) ...[
                      const SizedBox(height: 8),
                      Wrap(
                        alignment: WrapAlignment.end,
                        spacing: 6,
                        runSpacing: 6,
                        children: actions,
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
          const OfflineStatusBar(),
          Expanded(child: child),
        ],
      ),
    );
  }
}

/// Small helper used by hub pages for consistent hero metadata.
class HeroMetaChip extends StatelessWidget {
  const HeroMetaChip({
    super.key,
    required this.icon,
    required this.label,
    this.color = Colors.white,
  });

  final IconData icon;
  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final onDark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(
          color: Colors.white.withValues(alpha: onDark ? .18 : .16),
        ),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Icon(icon, size: 13, color: AppColors.onPanelSoft),
          const SizedBox(width: 5),
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                color: AppColors.onPanelSoft,
                fontSize: 11,
                fontWeight: FontWeight.w500,
                height: 1.2,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Consistent hero action pair used on hub pages.
class HeroActions extends StatelessWidget {
  const HeroActions({super.key, required this.primary, this.secondary});

  final Widget primary;
  final Widget? secondary;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        if (constraints.maxWidth < 430 || secondary == null) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              primary,
              if (secondary != null) ...<Widget>[
                const SizedBox(height: 9),
                secondary!,
              ],
            ],
          );
        }
        return Row(
          children: <Widget>[
            Expanded(child: primary),
            const SizedBox(width: 10),
            Expanded(child: secondary!),
          ],
        );
      },
    );
  }
}

/// Label used above hero titles.
class HeroEyebrow extends StatelessWidget {
  const HeroEyebrow({
    super.key,
    required this.label,
    this.color = AppColors.secondary,
  });

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Flexible(
          child: Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              color: AppColors.onPanelMuted,
              fontSize: 11,
              fontWeight: FontWeight.w500,
              letterSpacing: .2,
            ),
          ),
        ),
      ],
    );
  }
}

/// Hero title + subtitle block shared by the hub pages.
class HeroCopy extends StatelessWidget {
  const HeroCopy({
    super.key,
    required this.title,
    required this.subtitle,
    this.eyebrow,
  });

  final String title;
  final String subtitle;
  final String? eyebrow;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        if (eyebrow != null) ...<Widget>[
          HeroEyebrow(label: eyebrow!),
          const SizedBox(height: 8),
        ],
        Text(
          title,
          style: theme.textTheme.headlineMedium?.copyWith(
            color: AppColors.onPanel,
            fontWeight: FontWeight.w600,
            height: 1.3,
            letterSpacing: -.2,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          subtitle,
          style: theme.textTheme.bodyMedium?.copyWith(
            color: AppColors.onPanelSoft,
            height: 1.6,
          ),
        ),
      ],
    );
  }
}

/// Light/dark aware decorative divider used between sections.
class SoftDivider extends StatelessWidget {
  const SoftDivider({super.key, this.height = 1});

  final double height;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: height,
      color: Theme.of(context).colorScheme.outlineVariant.withValues(alpha: .6),
    );
  }
}

/// Convenience for pages that want a themed spinner.
class AppLoader extends StatelessWidget {
  const AppLoader({super.key, this.label});

  final String? label;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 28),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const SizedBox.square(
            dimension: 26,
            child: CircularProgressIndicator(strokeWidth: 2.4),
          ),
          if (label != null) ...<Widget>[
            const SizedBox(height: 12),
            Text(label!, style: theme.textTheme.bodySmall),
          ],
        ],
      ),
    );
  }
}

/// Re-exported palette shim so pages can use brand accents without a second
/// theme import.
Color brandAccent(BuildContext context) =>
    AppTheme.isDark(context) ? AppColors.accentOnPanel : AppColors.primary;

/// Solid accent button used inside the ink summary panel.
class HeroButton extends StatelessWidget {
  const HeroButton({
    super.key,
    required this.label,
    this.onPressed,
    this.icon,
    this.expanded = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool expanded;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final foreground = dark ? AppColors.panel : Colors.white;
    final radius = BorderRadius.circular(AppRadius.md);
    return SizedBox(
      width: expanded ? double.infinity : null,
      height: 48,
      child: Material(
        color: scheme.primary,
        borderRadius: radius,
        elevation: 0,
        child: InkWell(
          onTap: onPressed,
          borderRadius: radius,
          splashColor: foreground.withValues(alpha: .16),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 18),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                if (icon != null) ...<Widget>[
                  Icon(icon, size: 18, color: foreground),
                  const SizedBox(width: 8),
                ],
                Flexible(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: foreground,
                      fontWeight: FontWeight.w600,
                      fontSize: 13.5,
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

/// Hairline-outline button for secondary actions on the ink panel.
class HeroGhostButton extends StatelessWidget {
  const HeroGhostButton({
    super.key,
    required this.label,
    this.onPressed,
    this.icon,
    this.expanded = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool expanded;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(AppRadius.md);
    return SizedBox(
      width: expanded ? double.infinity : null,
      height: 48,
      child: Material(
        color: Colors.transparent,
        borderRadius: radius,
        child: InkWell(
          onTap: onPressed,
          borderRadius: radius,
          splashColor: Colors.white.withValues(alpha: .12),
          child: Container(
            decoration: BoxDecoration(
              borderRadius: radius,
              border: Border.all(
                color: AppColors.onPanel.withValues(alpha: .18),
              ),
            ),
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                if (icon != null) ...<Widget>[
                  Icon(icon, size: 17, color: AppColors.onPanelSoft),
                  const SizedBox(width: 7),
                ],
                Flexible(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: AppColors.onPanelSoft,
                      fontWeight: FontWeight.w500,
                      fontSize: 13,
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
