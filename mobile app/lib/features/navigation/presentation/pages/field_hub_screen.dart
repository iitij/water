import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/navigation/module_catalog.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/permissions/permission_service.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_motion.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../widgets/module_section.dart';

class FieldHubScreen extends StatelessWidget {
  const FieldHubScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final permissions = GetIt.I<PermissionService>();
    final modules = ModuleCatalog.visible(permissions, group: AppModuleGroup.field);
    final extraModules = ModuleCatalog.visible(permissions, group: AppModuleGroup.billing)
        .where((module) => module.id == 'quick_collector')
        .toList(growable: false);

    return AppPageBody(
      children: <Widget>[
        AppReveal(
          child: AppHeroPanel(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                const HeroCopy(
                  eyebrow: 'العمليات الميدانية',
                  title: 'الميدان بين يديك',
                  subtitle:
                      'سجّل القراءات، امسح الباركود وتابع العدادات — كل ذلك يعمل بسلاسة حتى دون اتصال بالشبكة.',
                ),
                const Gap(AppSpacing.md),
                const Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: <Widget>[
                    HeroMetaChip(icon: Icons.bolt_rounded, label: 'إدخال سريع'),
                    HeroMetaChip(icon: Icons.wifi_off_rounded, label: 'يعمل أوفلاين'),
                    HeroMetaChip(icon: Icons.qr_code_scanner_rounded, label: 'مسح باركود'),
                  ],
                ),
                const Gap(AppSpacing.lg),
                HeroActions(
                  primary: HeroButton(
                    label: 'بدء جولة القراءة',
                    icon: Icons.play_arrow_rounded,
                    onPressed: () => context.push(RouteNames.fieldReader),
                  ),
                  secondary: HeroGhostButton(
                    label: 'سجل القراءات',
                    icon: Icons.fact_check_outlined,
                    onPressed: () => context.push(RouteNames.readings),
                  ),
                ),
              ],
            ),
          ),
        ),
        const Gap(AppSpacing.lg),
        const AppReveal(
          delay: Duration(milliseconds: 60),
          child: SyncSummaryCard(
            showDescription: true,
          ),
        ),
        const Gap(AppSpacing.md),
        AppReveal(
          delay: const Duration(milliseconds: 90),
          child: AppNotice(
            title: 'القراءة بلا إنترنت آمنة',
            message:
                'تُحفظ القراءات على الجهاز أولاً ثم تُرسل تلقائياً عند عودة الشبكة، مع منع التكرار لكل عملية.',
            icon: Icons.cloud_done_outlined,
            color: AppColors.success,
            action: TextButton(
              onPressed: () => context.push(RouteNames.offlineSync),
              style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(0, 28)),
              child: const Text('متابعة حالة المزامنة'),
            ),
          ),
        ),
        const Gap(AppSpacing.xl),
        AppReveal(
          delay: const Duration(milliseconds: 120),
          child: ModuleGridSection(
            modules: modules,
            title: 'أدوات الميدان',
            subtitle: 'كل ما تحتاجه لزيارة ميدانية كاملة',
          ),
        ),
        const Gap(AppSpacing.xl),
        const AppReveal(
          delay: Duration(milliseconds: 160),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              SectionHeading(
                title: 'دورة القراءة في ثلاث خطوات',
                subtitle: 'مصممة لتقليل الأخطاء وتسريع العمل',
              ),
              Gap(AppSpacing.sm),
              ProcessStepCard(
                index: 1,
                title: 'ابحث عن المسار أو امسح الباركود',
                description: 'أدخل رقم المربع والعداد، أو امسح الكود مباشرة للوصول إلى المشترك.',
                icon: Icons.qr_code_scanner_rounded,
                color: AppColors.primary,
              ),
              Gap(AppSpacing.sm),
              ProcessStepCard(
                index: 2,
                title: 'أدخل القراءة وراجع الفارق',
                description: 'يعرض النظام القراءة السابقة والاستهلاك المتوقع قبل الحفظ.',
                icon: Icons.speed_rounded,
                color: AppColors.info,
              ),
              Gap(AppSpacing.sm),
              ProcessStepCard(
                index: 3,
                title: 'أصدر الفاتورة أو اطبع السند',
                description: 'أكمل الزيارة بإصدار الفاتورة أو تحصيل المبلغ وطباعة السند فوراً.',
                icon: Icons.print_rounded,
                color: AppColors.success,
              ),
            ],
          ),
        ),
        if (extraModules.isNotEmpty) ...<Widget>[
          const Gap(AppSpacing.xl),
          AppReveal(
            delay: const Duration(milliseconds: 200),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                const SectionHeading(
                  title: 'متابعة مالية سريعة',
                  subtitle: 'عندما يكون التحصيل جزءاً من الزيارة الميدانية',
                ),
                const Gap(AppSpacing.sm),
                ...extraModules.map((module) => AppModuleTileView(module: module, list: true)),
              ],
            ),
          ),
        ],
      ],
    );
  }
}
