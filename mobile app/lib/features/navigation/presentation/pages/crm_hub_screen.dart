import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/l10n/laravel_screen_strings.dart';
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

class CrmHubScreen extends StatelessWidget {
  const CrmHubScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final permissions = GetIt.I<PermissionService>();
    final modules = ModuleCatalog.visible(permissions, group: AppModuleGroup.crm);

    return AppPageBody(
      children: <Widget>[
        AppReveal(
          child: AppHeroPanel(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                const HeroCopy(
                  eyebrow: 'المشتركون والخدمات',
                  title: 'اعرف مشتركك بالكامل',
                  subtitle:
                      'ملف 360° يجمع العدادات والقراءات والفواتير والمدفوعات وسجل التواصل في مكان واحد.',
                ),
                const Gap(AppSpacing.md),
                const Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: <Widget>[
                    HeroMetaChip(icon: Icons.badge_outlined, label: 'ملف 360°'),
                    HeroMetaChip(icon: Icons.location_city_rounded, label: 'مربعات وحارات'),
                    HeroMetaChip(icon: Icons.receipt_long_outlined, label: 'كشف حساب'),
                  ],
                ),
                const Gap(AppSpacing.lg),
                HeroActions(
                  primary: HeroButton(
                    label: 'إضافة مشترك جديد',
                    icon: Icons.person_add_alt_1_rounded,
                    onPressed: () => context.push(RouteNames.subscriberNew),
                  ),
                  secondary: HeroGhostButton(
                    label: 'بحث في المشتركين',
                    icon: Icons.search_rounded,
                    onPressed: () => context.push(RouteNames.subscribers),
                  ),
                ),
              ],
            ),
          ),
        ),
        const Gap(AppSpacing.xl),
        AppReveal(
          delay: const Duration(milliseconds: 70),
          child: ModuleGridSection(
            modules: modules,
            title: 'إدارة المشتركين',
            subtitle: 'الملفات والمربعات والرسوم والخدمات',
          ),
        ),
        const Gap(AppSpacing.xl),
        const AppReveal(
          delay: Duration(milliseconds: 110),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              SectionHeading(
                title: 'كل ما يخص المشترك',
                subtitle: 'مبني على رحلة العميل داخل المشروع',
                icon: Icons.auto_awesome_rounded,
              ),
              Gap(AppSpacing.sm),
              ProcessStepCard(
                index: 1,
                title: 'الملف الأساسي',
                description: 'الاسم والهاتف والعنوان والمربع، مع بحث فوري بالمسار أو رقم العداد.',
                icon: Icons.person_search_rounded,
                color: AppColors.primary,
              ),
              Gap(AppSpacing.sm),
              ProcessStepCard(
                index: 2,
                title: 'العدادات والقراءات',
                description: 'كل العدادات المرتبطة، آخر قراءة، ومتوسط الاستهلاك الشهري.',
                icon: Icons.water_damage_outlined,
                color: AppColors.info,
              ),
              Gap(AppSpacing.sm),
              ProcessStepCard(
                index: 3,
                title: 'الحساب والتحصيل',
                description: 'الفواتير والمدفوعات والأرصدة مع كشف حساب قابل للطباعة والمشاركة.',
                icon: Icons.request_quote_outlined,
                color: AppColors.success,
              ),
            ],
          ),
        ),
        const Gap(AppSpacing.lg),
        AppReveal(
          delay: const Duration(milliseconds: 140),
          child: AppNotice(
            title: 'طلبات الوايت والخدمات',
            message:
                'سجّل طلب الوايت من الميدان، وتابع تنفيذه، ثم حوّله إلى فاتورة مباشرة دون أي ورقة إضافية.',
            icon: Icons.local_shipping_outlined,
            color: AppColors.warning,
            action: TextButton(
              onPressed: () => context.push(RouteNames.serviceRequests),
              style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(0, 28)),
              child: const Text('فتح ${LaravelScreenStrings.serviceRequests}'),
            ),
          ),
        ),
      ],
    );
  }
}
