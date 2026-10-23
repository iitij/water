import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../../admin_shared/presentation/widgets/admin_permission_guard.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/repositories/settings_repository.dart';
import 'general_settings_resource_screen.dart';
import 'invoice_settings_studio_screen.dart';
import 'reading_settings_screen.dart';
import 'receipt_settings_studio_screen.dart';
import 'whatsapp_settings_screen.dart';

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
  final String? initialSection;
  final String? mediaBaseUrl;
  final ImagePicker? imagePicker;

  @override
  Widget build(BuildContext context) {
    final centers = <_SettingsCenter>[
      if (can(AdminPermissions.viewReadingSettings))
        _SettingsCenter(
          id: 'reading',
          title: 'القراءات',
          icon: Icons.calendar_month_outlined,
          builder: () => ReadingSettingsScreen(repository: repository, embedded: true),
        ),
      if (can(AdminPermissions.viewInvoiceSettings))
        _SettingsCenter(
          id: 'invoice',
          title: 'الفاتورة',
          icon: Icons.receipt_long_outlined,
          builder: () => InvoiceSettingsStudioScreen(
            repository: repository,
            embedded: true,
            mediaBaseUrl: mediaBaseUrl,
            imagePicker: imagePicker,
          ),
        ),
      if (can(AdminPermissions.viewReceiptSettings))
        _SettingsCenter(
          id: 'receipt',
          title: 'سند القبض',
          icon: Icons.payments_outlined,
          builder: () => ReceiptSettingsStudioScreen(
            repository: repository,
            embedded: true,
            mediaBaseUrl: mediaBaseUrl,
            imagePicker: imagePicker,
          ),
        ),
      if (can(AdminPermissions.viewWhatsAppSettings))
        _SettingsCenter(
          id: 'whatsapp',
          title: 'الواتساب',
          icon: Icons.chat_outlined,
          builder: () => WhatsAppSettingsScreen(repository: repository, embedded: true),
        ),
      if (can(AdminPermissions.viewSettings))
        _SettingsCenter(
          id: 'general',
          title: 'الإعدادات العامة',
          icon: Icons.tune_rounded,
          builder: () => GeneralSettingsResourceScreen(
            repository: repository,
            can: can,
            embedded: true,
            mediaBaseUrl: mediaBaseUrl,
            imagePicker: imagePicker,
          ),
        ),
    ];
    if (centers.isEmpty) {
      return const Scaffold(
        body: AdminErrorView(message: 'لا تملك صلاحية الوصول إلى أي مركز من مراكز الإعدادات.'),
      );
    }
    final requestedIndex = centers.indexWhere((center) => center.id == initialSection);
    return DefaultTabController(
      // Reset the tab controller when a different deep link is opened.
      key: ValueKey('${initialSection ?? "all"}:${centers.map((center) => center.id).join(",")}'),
      length: centers.length,
      initialIndex: requestedIndex < 0 ? 0 : requestedIndex,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('الإعدادات'),
          bottom: TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            tabs: [
              for (final center in centers) Tab(text: center.title, icon: Icon(center.icon)),
            ],
          ),
        ),
        body: TabBarView(children: [for (final center in centers) center.builder()]),
      ),
    );
  }
}

class _SettingsCenter {
  const _SettingsCenter({required this.id, required this.title, required this.icon, required this.builder});

  final String id;
  final String title;
  final IconData icon;
  final Widget Function() builder;
}
