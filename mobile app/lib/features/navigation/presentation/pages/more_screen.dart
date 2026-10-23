import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/auth/session_service.dart';
import '../../../../core/config/app_config.dart';
import '../../../../core/navigation/module_catalog.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/permissions/permission_service.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_theme.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_motion.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/widgets/confirm_dialog.dart';
import '../../../auth/presentation/cubit/auth_cubit.dart';
import '../../../offline_sync/services/sync_queue_manager.dart';
import '../widgets/module_section.dart';

class MoreScreen extends StatelessWidget {
  const MoreScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final session = GetIt.I<SessionService>();
    final permissions = GetIt.I<PermissionService>();
    final queue = GetIt.I<SyncQueueManager>();
    final user = session.user ?? const <String, dynamic>{};
    final name = user['name']?.toString() ?? 'موظف المشروع';
    final email = user['email']?.toString() ?? '—';
    final avatar = user['avatar']?.toString();

    final insights = ModuleCatalog.visible(permissions, group: AppModuleGroup.insights);
    final admin = ModuleCatalog.visible(permissions, group: AppModuleGroup.admin);

    return AppPageBody(
      children: <Widget>[
        AppReveal(
          child: _IdentityCard(
            name: name,
            email: email,
            avatar: avatar,
            online: queue.isOnline,
            roles: permissions.currentRoles.toList(growable: false),
            isAdmin: permissions.isAdmin,
            onEdit: () => context.push(RouteNames.profile),
          ),
        ),
        if (insights.isNotEmpty) ...<Widget>[
          const Gap(AppSpacing.xl),
          AppReveal(
            delay: const Duration(milliseconds: 60),
            child: ModuleGridSection(
              modules: insights,
              title: 'التحليلات والتقارير',
              subtitle: 'أرقام دقيقة لاتخاذ القرار',
            ),
          ),
        ],
        if (admin.isNotEmpty) ...<Widget>[
          const Gap(AppSpacing.xl),
          AppReveal(
            delay: const Duration(milliseconds: 100),
            child: ModuleGridSection(
              modules: admin,
              title: 'الإدارة والإعدادات',
              subtitle: 'تحكم كامل في النظام والمستخدمين',
            ),
          ),
        ],
        const Gap(AppSpacing.xl),
        AppReveal(
          delay: const Duration(milliseconds: 140),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              const SectionHeading(
                title: 'النظام والحساب',
                subtitle: 'المزامنة والمظهر وبيانات الاتصال',
              ),
              const Gap(AppSpacing.sm),
              AppCard(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Column(
                  children: <Widget>[
                    AppSettingRow(
                      title: 'مراقب المزامنة',
                      subtitle: queue.pendingCount > 0
                          ? '${queue.pendingCount} عملية بانتظار الإرسال'
                          : 'جميع العمليات متزامنة مع الخادم',
                      icon: Icons.sync_rounded,
                      color: queue.failedCount > 0 ? AppColors.danger : AppColors.info,
                      onTap: () => context.push(RouteNames.offlineSync),
                    ),
                    const SoftDivider(),
                    AppSettingRow(
                      title: 'الملف الشخصي',
                      subtitle: 'تحديث الاسم والبريد وكلمة المرور',
                      icon: Icons.person_rounded,
                      color: AppColors.primary,
                      onTap: () => context.push(RouteNames.profile),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        const Gap(AppSpacing.lg),
        AppReveal(
          delay: const Duration(milliseconds: 180),
          child: const _AppearanceCard(),
        ),
        const Gap(AppSpacing.lg),
        AppReveal(
          delay: const Duration(milliseconds: 210),
          child: const _ServerCard(),
        ),
        const Gap(AppSpacing.xl),
        AppReveal(
          delay: const Duration(milliseconds: 240),
          child: AppCard(
            onTap: () => _confirmLogout(context),
            child: Row(
              children: <Widget>[
                AppIconBubble(
                  icon: Icons.logout_rounded,
                  color: AppColors.danger,
                  size: 42,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(
                        'تسجيل الخروج',
                        style: theme.textTheme.titleMedium?.copyWith(
                          color: AppColors.danger,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text('إنهاء الجلسة على هذا الجهاز', style: theme.textTheme.bodySmall),
                    ],
                  ),
                ),
                Icon(Icons.arrow_back_ios_new_rounded, size: 13, color: AppColors.danger),
              ],
            ),
          ),
        ),
        const Gap(AppSpacing.lg),
        Center(
          child: Column(
            children: <Widget>[
              Text(
                'مشروع مياه الوشاح • الإصدار 1.0.0',
                style: theme.textTheme.labelSmall,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 3),
              Text(
                'صُمم ليعمل في الميدان والمكتب على حد سواء',
                style: theme.textTheme.labelSmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant.withValues(alpha: .8),
                ),
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ],
    );
  }

  Future<void> _confirmLogout(BuildContext context) async {
    final confirmed = await showConfirmDialog(
      context,
      title: 'تسجيل الخروج',
      message: 'هل تريد إنهاء الجلسة الحالية؟ ستحتاج إلى تسجيل الدخول مرة أخرى.',
      confirmLabel: 'تسجيل الخروج',
      destructive: true,
    );
    if (!confirmed || !context.mounted) return;
    await context.read<AuthCubit>().logout();
  }
}

class _IdentityCard extends StatelessWidget {
  const _IdentityCard({
    required this.name,
    required this.email,
    required this.avatar,
    required this.online,
    required this.roles,
    required this.isAdmin,
    required this.onEdit,
  });

  final String name;
  final String email;
  final String? avatar;
  final bool? online;
  final List<String> roles;
  final bool isAdmin;
  final VoidCallback onEdit;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final roleLabel = isAdmin
        ? 'مدير النظام'
        : roles.isEmpty
            ? 'موظف'
            : roles.first;

    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: AlignmentDirectional.topStart,
          end: AlignmentDirectional.bottomEnd,
          colors: <Color>[
            AppColors.primary.withValues(alpha: dark ? .24 : .1),
            AppColors.violet.withValues(alpha: dark ? .16 : .06),
          ],
        ),
        borderRadius: BorderRadius.circular(AppRadius.xxl),
        border: Border.all(
          color: AppColors.primary.withValues(alpha: dark ? .28 : .14),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              AppAvatar(name: name, imageUrl: avatar, size: 62, online: online),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 2),
                    Directionality(
                      textDirection: TextDirection.ltr,
                      child: Text(
                        email,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        textAlign: TextAlign.start,
                        style: theme.textTheme.bodySmall,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: <Widget>[
                        AppPill(
                          label: roleLabel,
                          icon: Icons.verified_user_rounded,
                          color: AppColors.primary,
                          dense: true,
                        ),
                        AppPill(
                          label: online == true ? 'متصل' : 'دون اتصال',
                          icon: online == true ? Icons.wifi_rounded : Icons.wifi_off_rounded,
                          color: online == true ? AppColors.success : AppColors.warning,
                          dense: true,
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
          const Gap(AppSpacing.md),
          AppSecondaryButton(
            label: 'تعديل بيانات الحساب',
            icon: Icons.edit_outlined,
            height: 46,
            onPressed: onEdit,
          ),
        ],
      ),
    );
  }
}

class _AppearanceCard extends StatelessWidget {
  const _AppearanceCard();

  @override
  Widget build(BuildContext context) {
    final controller = GetIt.I<AppThemeController>();
    final theme = Theme.of(context);
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              AppIconBubble(
                icon: Icons.palette_outlined,
                color: AppColors.violet,
                size: 40,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text('مظهر التطبيق', style: theme.textTheme.titleMedium),
                    const SizedBox(height: 2),
                    Text(
                      'اختر النمط المريح لعينيك في الميدان أو المكتب',
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
            ],
          ),
          const Gap(AppSpacing.md),
          AnimatedBuilder(
            animation: controller,
            builder: (context, _) => AppSegmentedControl<ThemeMode>(
              value: controller.mode,
              onChanged: controller.setMode,
              options: const <AppSegmentedOption<ThemeMode>>[
                AppSegmentedOption<ThemeMode>(
                  value: ThemeMode.system,
                  label: 'النظام',
                  icon: Icons.brightness_auto_rounded,
                ),
                AppSegmentedOption<ThemeMode>(
                  value: ThemeMode.light,
                  label: 'فاتح',
                  icon: Icons.light_mode_rounded,
                ),
                AppSegmentedOption<ThemeMode>(
                  value: ThemeMode.dark,
                  label: 'داكن',
                  icon: Icons.dark_mode_rounded,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _ServerCard extends StatelessWidget {
  const _ServerCard();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final uri = Uri.tryParse(AppConfig.apiBaseUrl);
    final host = uri == null || uri.host.isEmpty ? AppConfig.apiBaseUrl : uri.host;
    final online = GetIt.I<SyncQueueManager>().isOnline;
    return AppCard(
      child: Row(
        children: <Widget>[
          AppIconBubble(
            icon: Icons.dns_outlined,
            color: AppColors.info,
            size: 40,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text('خادم المشروع', style: theme.textTheme.titleSmall),
                const SizedBox(height: 2),
                Directionality(
                  textDirection: TextDirection.ltr,
                  child: Text(
                    host,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall?.copyWith(fontFamily: 'monospace'),
                  ),
                ),
              ],
            ),
          ),
          AppPill(
            label: online == false ? 'أوفلاين' : 'متصل',
            icon: online == false ? Icons.wifi_off_rounded : Icons.lock_outline_rounded,
            color: online == false ? AppColors.warning : AppColors.success,
            dense: true,
          ),
        ],
      ),
    );
  }
}
