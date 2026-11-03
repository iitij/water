import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../../../core/l10n/laravel_screen_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../admin_shared/presentation/widgets/admin_permission_guard.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/repositories/settings_repository.dart';
import 'general_settings_resource_screen.dart';
import 'invoice_settings_studio_screen.dart';
import 'reading_settings_screen.dart';
import 'receipt_settings_studio_screen.dart';

class SettingsStudioScreen extends StatelessWidget {
  const SettingsStudioScreen({
    required this.repository,
    required this.can,
    this.initialSection,
    this.mediaBaseUrl,
    this.imagePicker,
    super.key,
  });

  final SettingsRepository repository;
  final AdminPermissionCheck can;

  /// Optional deep-link section id (`reading` / `invoice` / `receipt` /
  /// `general`). The studio is a hub list now and each center
  /// opens as its own sub-page; the id is retained for API compatibility
  /// with the router's `?section=` deep links.
  final String? initialSection;
  final String? mediaBaseUrl;
  final ImagePicker? imagePicker;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final centers = <_SettingsCenter>[
      if (can(AdminPermissions.viewReadingSettings))
        _SettingsCenter(
          id: 'reading',
          title: LaravelScreenStrings.readingSettings,
          description: 'دورة القراءات والفوترة الآلية',
          icon: Icons.calendar_month_outlined,
          builder: () => ReadingSettingsScreen(repository: repository),
        ),
      if (can(AdminPermissions.viewInvoiceSettings))
        _SettingsCenter(
          id: 'invoice',
          title: LaravelScreenStrings.invoiceSettings,
          description: 'نصوص الفاتورة وشعاراتها وحقولها',
          icon: Icons.receipt_long_outlined,
          builder: () => InvoiceSettingsStudioScreen(
            repository: repository,
            mediaBaseUrl: mediaBaseUrl,
            imagePicker: imagePicker,
          ),
        ),
      if (can(AdminPermissions.viewReceiptSettings))
        _SettingsCenter(
          id: 'receipt',
          title: LaravelScreenStrings.receiptSettings,
          description: 'نصوص السند وشعاراته وحقوله',
          icon: Icons.payments_outlined,
          builder: () => ReceiptSettingsStudioScreen(
            repository: repository,
            mediaBaseUrl: mediaBaseUrl,
            imagePicker: imagePicker,
          ),
        ),
      if (can(AdminPermissions.viewSettings))
        _SettingsCenter(
          id: 'general',
          title: LaravelScreenStrings.settings,
          description: 'مفاتيح الإعداد النصية وصور النظام',
          icon: Icons.tune_rounded,
          builder: () => GeneralSettingsResourceScreen(
            repository: repository,
            can: can,
            mediaBaseUrl: mediaBaseUrl,
            imagePicker: imagePicker,
          ),
        ),
    ];
    if (centers.isEmpty) {
      return const SubPageScaffold(
        title: LaravelScreenStrings.settings,
        child: AdminErrorView(
          message: 'لا تملك صلاحية الوصول إلى أي مركز من مراكز الإعدادات.',
        ),
      );
    }
    return SubPageScaffold(
      title: LaravelScreenStrings.settings,
      subtitle: 'مراكز ضبط القراءات والفوترة والتنبيهات',
      child: AppPageBody(
        maxWidth: 760,
        bottomPadding: 40,
        children: [
          _SettingsGroup(
            title: 'دورة القراءات',
            icon: Icons.event_repeat_rounded,
            rows: [
              for (final center in centers)
                if (center.id == 'reading') center,
            ],
          ),
          const Gap(16),
          _SettingsGroup(
            title: 'الفوترة والتنبيهات',
            icon: Icons.request_quote_outlined,
            rows: [
              for (final center in centers)
                if (center.id == 'invoice' || center.id == 'receipt')
                  center,
            ],
          ),
          const Gap(16),
          _SettingsGroup(
            title: 'إدارة النظام',
            icon: Icons.settings_outlined,
            rows: [
              for (final center in centers)
                if (center.id == 'general') center,
            ],
          ),
          const Gap(8),
          Text(
            'تُحفظ التغييرات في كل مركز عبر زر الحفظ أسفل الشاشة.',
            style: theme.textTheme.bodySmall,
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }
}

/// One grouped section: a quiet heading above a single card of setting rows.
class _SettingsGroup extends StatelessWidget {
  const _SettingsGroup({required this.title, required this.icon, required this.rows});

  final String title;
  final IconData icon;
  final List<_SettingsCenter> rows;

  @override
  Widget build(BuildContext context) {
    if (rows.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SectionHeading(title: title, icon: icon),
        const Gap(10),
        AppCard(
          padding: EdgeInsets.zero,
          child: Column(
            children: [
              for (var index = 0; index < rows.length; index++) ...[
                if (index > 0) const AppDivider(indent: 62),
                _SettingsCenterRow(center: rows[index]),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _SettingsCenterRow extends StatelessWidget {
  const _SettingsCenterRow({required this.center});

  final _SettingsCenter center;

  @override
  Widget build(BuildContext context) {
    return AppSettingRow(
      title: center.title,
      subtitle: center.description,
      icon: center.icon,
      color: Theme.of(context).colorScheme.primary,
      onTap: () {
        Navigator.of(context).push(
          MaterialPageRoute<void>(builder: (_) => center.builder()),
        );
      },
    );
  }
}

class _SettingsCenter {
  const _SettingsCenter({
    required this.id,
    required this.title,
    required this.description,
    required this.icon,
    required this.builder,
  });

  final String id;
  final String title;
  final String description;
  final IconData icon;
  final Widget Function() builder;
}
