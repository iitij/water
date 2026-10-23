import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/navigation/module_catalog.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../offline_sync/services/sync_queue_manager.dart';

class AppModuleTileView extends StatelessWidget {
  const AppModuleTileView({super.key, required this.module, this.list = false});

  final AppModule module;
  final bool list;

  @override
  Widget build(BuildContext context) {
    return AppModuleTile(
      title: module.title,
      subtitle: module.subtitle,
      icon: module.icon,
      color: module.color,
      list: list,
      onTap: () => context.push(module.route),
    );
  }
}

/// Grid of modules with an optional section heading.
class ModuleGridSection extends StatelessWidget {
  const ModuleGridSection({
    super.key,
    required this.modules,
    this.title,
    this.subtitle,
    this.trailing,
    this.minTileWidth = 200,
    this.tileHeight = 152,
  });

  final List<AppModule> modules;
  final String? title;
  final String? subtitle;
  final Widget? trailing;
  final double minTileWidth;
  final double tileHeight;

  @override
  Widget build(BuildContext context) {
    if (modules.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        if (title != null) ...<Widget>[
          SectionHeading(title: title!, subtitle: subtitle, trailing: trailing),
          const Gap(AppSpacing.sm),
        ],
        AppModuleGrid(
          minTileWidth: minTileWidth,
          tileHeight: tileHeight,
          children: modules
              .map((module) => AppModuleTileView(module: module))
              .toList(growable: false),
        ),
      ],
    );
  }
}

/// Horizontal module rail with a "view all" affordance.
class ModuleRailSection extends StatelessWidget {
  const ModuleRailSection({
    super.key,
    required this.modules,
    required this.title,
    required this.onViewAll,
    this.subtitle,
    this.tileWidth = 208,
    this.tileHeight = 152,
  });

  final List<AppModule> modules;
  final String title;
  final String? subtitle;
  final VoidCallback onViewAll;
  final double tileWidth;
  final double tileHeight;

  @override
  Widget build(BuildContext context) {
    if (modules.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        SectionHeading(
          title: title,
          subtitle: subtitle,
          trailing: TextButton(
            onPressed: onViewAll,
            child: const Row(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text('الكل'),
                SizedBox(width: 2),
                Icon(Icons.arrow_back_ios_new_rounded, size: 11),
              ],
            ),
          ),
        ),
        const Gap(AppSpacing.sm),
        AppModuleRail(
          tileWidth: tileWidth,
          tileHeight: tileHeight,
          children: modules
              .map((module) => AppModuleTileView(module: module))
              .toList(growable: false),
        ),
      ],
    );
  }
}

/// Live sync summary used on several pages.
class SyncSummaryCard extends StatelessWidget {
  const SyncSummaryCard({super.key, this.onManage, this.showDescription = true});

  final VoidCallback? onManage;
  final bool showDescription;

  @override
  Widget build(BuildContext context) {
    final queue = GetIt.I<SyncQueueManager>();
    return AnimatedBuilder(
      animation: queue,
      builder: (context, _) {
        final online = queue.isOnline;
        final pending = queue.pendingCount;
        final failed = queue.failedCount;
        final headline = queue.isSyncing
            ? 'جارٍ إرسال العمليات الآن'
            : failed > 0
                ? '$failed عملية تحتاج إلى إعادة محاولة'
                : pending > 0
                    ? '$pending عملية بانتظار المزامنة'
                    : 'كل شيء متزامن مع الخادم';
        return AppStatStrip(
          items: <AppStatItem>[
            AppStatItem(
              label: 'بانتظار المزامنة',
              value: '$pending',
              icon: Icons.cloud_upload_outlined,
              color: pending > 0 ? AppColors.warning : AppColors.success,
            ),
            AppStatItem(
              label: 'تحتاج إعادة محاولة',
              value: '$failed',
              icon: Icons.error_outline_rounded,
              color: failed > 0 ? AppColors.danger : AppColors.neutral,
            ),
            AppStatItem(
              label: 'الاتصال بالخادم',
              value: online == true
                  ? 'متصل'
                  : online == false
                      ? 'دون اتصال'
                      : 'جارٍ الفحص',
              icon: online == true ? Icons.wifi_rounded : Icons.wifi_off_rounded,
              color: online == true ? AppColors.success : AppColors.warning,
            ),
          ],
          description: showDescription ? headline : null,
        );
      },
    );
  }
}

/// A numbered process card used for "how it works" sections.
class ProcessStepCard extends StatelessWidget {
  const ProcessStepCard({
    super.key,
    required this.index,
    required this.title,
    required this.description,
    required this.icon,
    required this.color,
  });

  final int index;
  final String title;
  final String description;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return AppCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Column(
            children: <Widget>[
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: AppTint.soft(color, context),
                  borderRadius: BorderRadius.circular(AppRadius.sm),
                  border: Border.all(color: color.withValues(alpha: .16)),
                ),
                child: Icon(icon, color: color, size: 20),
              ),
              const SizedBox(height: 8),
              Text(
                '$index',
                style: theme.textTheme.labelSmall?.copyWith(
                  fontWeight: FontWeight.w600,
                  color: color,
                  fontSize: 13,
                ),
              ),
            ],
          ),
          const SizedBox(width: 13),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(title, style: theme.textTheme.titleMedium),
                const SizedBox(height: 4),
                Text(description, style: theme.textTheme.bodySmall?.copyWith(height: 1.55)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
