import 'package:flutter/material.dart';

import '../../../../core/navigation/sub_page.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';

/// Dashboard welcome hero: identity, connection state and the two field
/// shortcuts managers use most.
class CustomAccountBanner extends StatelessWidget {
  const CustomAccountBanner({
    required this.userName,
    required this.roleLabel,
    required this.isOnline,
    this.avatarUrl,
    this.pendingSyncCount = 0,
    this.onAddReading,
    this.onAddSubscriber,
    super.key,
  });

  final String userName;
  final String roleLabel;
  final String? avatarUrl;
  final bool isOnline;
  final int pendingSyncCount;
  final VoidCallback? onAddReading;
  final VoidCallback? onAddSubscriber;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final connectionLabel = isOnline
        ? 'متصل بالخادم'
        : pendingSyncCount > 0
            ? 'أوفلاين • $pendingSyncCount بانتظار المزامنة'
            : 'أوفلاين';

    return AppHeroPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: <Widget>[
              AppAvatar(
                name: userName,
                imageUrl: avatarUrl,
                size: 58,
                online: isOnline,
                ringColor: Colors.white.withValues(alpha: .55),
                background: Colors.white.withValues(alpha: .18),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      'مرحباً، $userName',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.headlineSmall?.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      roleLabel,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: Colors.white.withValues(alpha: .8),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const Gap(AppSpacing.md),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: <Widget>[
              HeroMetaChip(
                icon: isOnline ? Icons.cloud_done_rounded : Icons.cloud_off_rounded,
                label: connectionLabel,
              ),
              const HeroMetaChip(icon: Icons.today_rounded, label: 'خلاصة اليوم'),
            ],
          ),
          if (onAddReading != null || onAddSubscriber != null) ...<Widget>[
            const Gap(AppSpacing.lg),
            HeroActions(
              primary: onAddReading == null
                  ? const SizedBox.shrink()
                  : HeroButton(
                      label: 'إضافة قراءة',
                      icon: Icons.speed_rounded,
                      onPressed: onAddReading,
                    ),
              secondary: onAddSubscriber == null
                  ? null
                  : HeroGhostButton(
                      label: 'إضافة مشترك',
                      icon: Icons.person_add_alt_1_rounded,
                      onPressed: onAddSubscriber,
                    ),
            ),
          ],
        ],
      ),
    );
  }
}
