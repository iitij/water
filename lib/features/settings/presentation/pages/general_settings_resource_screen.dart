import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:image_picker/image_picker.dart';

import '../../../admin_shared/presentation/widgets/admin_permission_guard.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/settings_entities.dart';
import '../../domain/repositories/settings_repository.dart';
import '../cubit/general_settings_cubit.dart';
import '../widgets/setting_image_field.dart';
import '../widgets/settings_form_widgets.dart';

class GeneralSettingsResourceScreen extends StatelessWidget {
  const GeneralSettingsResourceScreen({
    required this.repository,
    required this.can,
    this.embedded = false,
    this.mediaBaseUrl,
    this.imagePicker,
    super.key,
  });

  final SettingsRepository repository;
  final AdminPermissionCheck can;
  final bool embedded;
  final String? mediaBaseUrl;
  final ImagePicker? imagePicker;

  @override
  Widget build(BuildContext context) {
    if (!can(AdminPermissions.viewSettings)) {
      return const Scaffold(
        body: AdminErrorView(message: 'لا تملك صلاحية ViewAny:Setting لعرض الإعدادات.'),
      );
    }
    return BlocProvider(
      create: (_) => GeneralSettingsCubit(repository)..load(),
      child: _GeneralSettingsView(
        can: can,
        embedded: embedded,
        mediaBaseUrl: mediaBaseUrl,
        imagePicker: imagePicker,
      ),
    );
  }
}

class _GeneralSettingsView extends StatelessWidget {
  const _GeneralSettingsView({
    required this.can,
    required this.embedded,
    this.mediaBaseUrl,
    this.imagePicker,
  });

  final AdminPermissionCheck can;
  final bool embedded;
  final String? mediaBaseUrl;
  final ImagePicker? imagePicker;

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<GeneralSettingsCubit, GeneralSettingsState>(
      listenWhen: (previous, current) => previous.errorMessage != current.errorMessage,
      listener: (context, state) {
        if (state.errorMessage != null) {
          showAdminSnackBar(context, adminErrorMessage(state.errorMessage!), isError: true);
        }
      },
      builder: (context, state) {
        return Scaffold(
          appBar: embedded ? null : AppBar(title: const Text('إدارة الإعدادات العامة')),
          floatingActionButton: AdminPermissionGuard(
            permission: AdminPermissions.createSettings,
            can: can,
            child: FloatingActionButton.extended(
              key: const ValueKey('add-general-setting'),
              onPressed: state.isSaving ? null : () => _openEditor(context),
              icon: const Icon(Icons.add_rounded),
              label: const Text('إضافة إعداد'),
            ),
          ),
          body: Column(
            children: [
              Padding(
                padding: const EdgeInsets.all(16),
                child: TextField(
                  key: const ValueKey('general-settings-search'),
                  onChanged: context.read<GeneralSettingsCubit>().search,
                  decoration: const InputDecoration(
                    prefixIcon: Icon(Icons.search_rounded),
                    labelText: 'بحث بالمفتاح أو الاسم أو القيمة',
                    border: OutlineInputBorder(),
                  ),
                ),
              ),
              Expanded(child: _buildList(context, state)),
            ],
          ),
        );
      },
    );
  }

  Widget _buildList(BuildContext context, GeneralSettingsState state) {
    if (state.isLoading && state.items.isEmpty) return const SettingsLoadingBody();
    if (state.items.isEmpty && state.errorMessage != null) {
      return SettingsErrorBody(
        message: adminErrorMessage(state.errorMessage!),
        onRetry: () => context.read<GeneralSettingsCubit>().load(),
      );
    }
    final items = state.visibleItems;
    if (items.isEmpty) {
      return AdminEmptyView(
        title: state.query.isEmpty ? 'لا توجد إعدادات' : 'لا توجد نتائج مطابقة',
        icon: Icons.settings_outlined,
      );
    }
    return RefreshIndicator(
      onRefresh: () => context.read<GeneralSettingsCubit>().load(),
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 100),
        itemCount: items.length,
        separatorBuilder: (context, index) => const SizedBox(height: 8),
        itemBuilder: (context, index) {
          final item = items[index];
          return AdminCard(
            padding: EdgeInsets.zero,
            child: ListTile(
              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              leading: CircleAvatar(
                child: Icon(item.type == 'image' ? Icons.image_outlined : Icons.text_fields_rounded),
              ),
              title: Text(item.label, style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  LtrText(item.key, style: Theme.of(context).textTheme.bodySmall),
                  const SizedBox(height: 4),
                  Text(
                    item.type == 'image' ? (item.imagePath ?? 'لا توجد صورة') : item.value,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
              trailing: Wrap(
                children: [
                  AdminPermissionGuard(
                    permission: AdminPermissions.updateSettings,
                    can: can,
                    child: IconButton(
                      key: ValueKey('edit-setting-${item.id}'),
                      tooltip: 'تعديل',
                      onPressed: () => _openEditor(context, item),
                      icon: const Icon(Icons.edit_outlined),
                    ),
                  ),
                  AdminPermissionGuard(
                    permission: AdminPermissions.deleteSettings,
                    can: can,
                    child: IconButton(
                      key: ValueKey('delete-setting-${item.id}'),
                      tooltip: 'حذف',
                      onPressed: state.deletingIds.contains(item.id)
                          ? null
                          : () => _confirmDelete(context, item),
                      icon: state.deletingIds.contains(item.id)
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.delete_outline_rounded),
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Future<void> _openEditor(BuildContext context, [GeneralSetting? item]) async {
    final cubit = context.read<GeneralSettingsCubit>();
    final formKey = GlobalKey<FormState>();
    final keyController = TextEditingController(text: item?.key ?? '');
    final labelController = TextEditingController(text: item?.label ?? '');
    final valueController = TextEditingController(text: item?.value ?? '');
    var type = item?.type ?? 'text';
    var imagePath = item?.imagePath;
    var isUploading = false;
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, setState) {
          Future<void> save() async {
            if (!(formKey.currentState?.validate() ?? false)) return;
            final setting = GeneralSetting(
              id: item?.id ?? 0,
              key: keyController.text.trim(),
              label: labelController.text.trim(),
              type: type,
              value: type == 'text' ? valueController.text : '',
              imagePath: type == 'image' ? imagePath : null,
            );
            final success = await cubit.save(setting);
            if (success && dialogContext.mounted) Navigator.pop(dialogContext);
          }

          return AlertDialog(
            title: Text(item == null ? 'إضافة إعداد عام' : 'تعديل الإعداد'),
            content: SizedBox(
              width: 620,
              child: SingleChildScrollView(
                child: Form(
                  key: formKey,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      SettingsTextField(
                        controller: keyController,
                        label: 'المفتاح البرمجي (Key)',
                        required: true,
                        forceLtr: true,
                        onChanged: (_) {},
                      ),
                      SettingsTextField(
                        controller: labelController,
                        label: 'اسم الإعداد',
                        required: true,
                        onChanged: (_) {},
                      ),
                      DropdownButtonFormField<String>(
                        initialValue: type,
                        decoration: const InputDecoration(
                          labelText: 'نوع الإعداد',
                          border: OutlineInputBorder(),
                        ),
                        items: const [
                          DropdownMenuItem(value: 'text', child: Text('نص')),
                          DropdownMenuItem(value: 'image', child: Text('صورة')),
                        ],
                        onChanged: (value) => setState(() => type = value ?? 'text'),
                      ),
                      if (type == 'text')
                        SettingsTextField(
                          controller: valueController,
                          label: 'القيمة النصية',
                          maxLines: 4,
                          onChanged: (_) {},
                        )
                      else ...[
                        const SizedBox(height: 12),
                        SettingImageField(
                          label: 'صورة الإعداد',
                          settingKey: 'general_image',
                          path: imagePath ?? '',
                          mediaBaseUrl: mediaBaseUrl,
                          picker: imagePicker,
                          isUploading: isUploading,
                          onSelected: (file) async {
                            setState(() => isUploading = true);
                            final path = await cubit.uploadImage(
                              keyController.text.trim(),
                              await file.readAsBytes(),
                              file.name,
                            );
                            if (dialogContext.mounted) {
                              setState(() {
                                imagePath = path ?? imagePath;
                                isUploading = false;
                              });
                            }
                          },
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(dialogContext), child: const Text('إلغاء')),
              FilledButton.icon(
                onPressed: isUploading ? null : save,
                icon: const Icon(Icons.save_outlined),
                label: const Text('حفظ'),
              ),
            ],
          );
        },
      ),
    );
    keyController.dispose();
    labelController.dispose();
    valueController.dispose();
  }

  Future<void> _confirmDelete(BuildContext context, GeneralSetting item) async {
    final cubit = context.read<GeneralSettingsCubit>();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('حذف الإعداد'),
        content: Text('سيتم حذف «${item.label}» نهائياً. هل أنت متأكد؟'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('حذف'),
          ),
        ],
      ),
    );
    if (confirmed ?? false) await cubit.delete(item);
  }
}
