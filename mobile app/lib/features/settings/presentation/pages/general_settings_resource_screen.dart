import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:image_picker/image_picker.dart';

import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/l10n/app_strings.dart';
import '../../../../core/l10n/laravel_screen_strings.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/list_presentation_controls.dart';
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
      return const SubPageScaffold(
        title: LaravelScreenStrings.settings,
        child: AdminErrorView(message: 'لا تملك صلاحية ViewAny:Setting لعرض الإعدادات.'),
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
        final cubit = context.read<GeneralSettingsCubit>();
        final selectionBar = _SelectionBar(
          visible: state.selectedIds.isNotEmpty && can(AdminPermissions.deleteSettings),
          selectedCount: state.selectedIds.length,
          isSaving: state.isSaving,
          onSelectAll: cubit.selectAllVisible,
          onClear: cubit.clearSelection,
          onDelete: () => _confirmBulkDelete(context, state.selectedIds.length),
        );
        final body = Column(
          children: [
            AppFilterBar(
              key: const ValueKey('general-settings-search'),
              query: state.query,
              hint: 'بحث بالمفتاح أو الاسم أو القيمة',
              onChanged: cubit.search,
              onClear: () => cubit.search(''),
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
            ),
            selectionBar,
            Expanded(child: _buildList(context, state)),
          ],
        );
        final fab = AdminPermissionGuard(
          permission: AdminPermissions.createSettings,
          can: can,
          child: FloatingActionButton.extended(
            key: const ValueKey('add-general-setting'),
            onPressed: state.isSaving ? null : () => _openEditor(context),
            icon: const Icon(Icons.add_rounded),
            label: const Text('إضافة إعداد'),
          ),
        );
        if (embedded) {
          return Scaffold(body: body, floatingActionButton: fab);
        }
        return Scaffold(
          floatingActionButton: fab,
          body: SubPageScaffold(
            title: LaravelScreenStrings.settings,
            subtitle: 'المفاتيح المشتركة بين شاشات النظام',
            child: body,
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
    final strings = AppStrings.of(context);
    return ListPresentation<GeneralSetting>(items: items,
      sortValues: {
        'label': (label: strings.settingLabel, value: (setting) => setting.label),
        'id': (label: ListOptionsStrings.id, value: (setting) => setting.id),
        'key': (label: strings.settingKey, value: (setting) => setting.key),
        'type': (label: ListOptionsStrings.type, value: (setting) => setting.type),
        'created_at': (label: ListOptionsStrings.createdAt, value: (setting) => setting.createdAt),
        'updated_at': (label: ListOptionsStrings.updatedAt, value: (setting) => setting.updatedAt),
      }, fields: {'key': strings.settingKey, 'value': strings.settingValue,
        'created_at': ListOptionsStrings.createdAt, 'updated_at': ListOptionsStrings.updatedAt},
      builder: (context, pageItems, options) => RefreshIndicator(
      onRefresh: () => context.read<GeneralSettingsCubit>().load(),
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 100),
        itemCount: pageItems.length,
        separatorBuilder: (context, index) => const SizedBox(height: 8),
        itemBuilder: (context, index) {
          final item = pageItems[index];
          final canDelete = can(AdminPermissions.deleteSettings);
          final isDeleting = state.deletingIds.contains(item.id);
          return AppCard(
            padding: EdgeInsets.symmetric(horizontal: 14, vertical: options.compact ? 8 : 12),
            child: Row(
              children: [
                if (canDelete) ...[
                  Checkbox(
                    key: ValueKey('select-setting-${item.id}'),
                    value: state.selectedIds.contains(item.id),
                    onChanged: (val) => context
                        .read<GeneralSettingsCubit>()
                        .toggleSelected(item.id, val ?? false),
                  ),
                  const SizedBox(width: 6),
                ],
                AppGlyph(
                  icon: item.type == 'image' ? Icons.image_outlined : Icons.text_fields_rounded,
                  neutral: true,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        item.label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                      const SizedBox(height: 3),
                      if (options.isVisible('key')) LtrText(item.key, style: Theme.of(context).textTheme.labelSmall),
                      const SizedBox(height: 4),
                      if (options.isVisible('value')) Text(
                        item.type == 'image' ? (item.imagePath ?? 'لا توجد صورة') : item.value,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      if (options.isVisible('created_at') && item.createdAt != null)
                        Text('${ListOptionsStrings.createdAt}: ${AdminFormat.dateTime(item.createdAt)}',
                          style: Theme.of(context).textTheme.labelSmall),
                      if (options.isVisible('updated_at') && item.updatedAt != null)
                        Text('${ListOptionsStrings.updatedAt}: ${AdminFormat.dateTime(item.updatedAt)}',
                          style: Theme.of(context).textTheme.labelSmall),
                    ],
                  ),
                ),
                if (isDeleting) ...[
                  const SizedBox(width: 8),
                  const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                ],
                _SettingRowMenu(
                  item: item,
                  canEdit: can(AdminPermissions.updateSettings),
                  canDelete: canDelete,
                  deleteDisabled: isDeleting || state.isSaving,
                  onEdit: () => _openEditor(context, item),
                  onDelete: () => _confirmDelete(context, item),
                ),
              ],
            ),
          );
        },
      ),
    ));
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
                            final currentKey = keyController.text.trim();
                            if (currentKey.isEmpty) {
                              showAdminSnackBar(
                                dialogContext,
                                'أدخل المفتاح البرمجي (Key) أولاً قبل رفع الصورة.',
                                isError: true,
                              );
                              return;
                            }
                            setState(() => isUploading = true);
                            final path = await cubit.uploadImage(
                              currentKey,
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
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'حذف الإعداد',
      message: 'سيتم حذف «${item.label}» نهائياً. هل أنت متأكد؟',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (confirmed) await cubit.delete(item);
  }

  Future<void> _confirmBulkDelete(BuildContext context, int count) async {
    final cubit = context.read<GeneralSettingsCubit>();
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'حذف جماعي للإعدادات',
      message: 'سيتم حذف $count إعداد نهائياً. هل أنت متأكد؟',
      confirmLabel: 'حذف الكل',
      destructive: true,
    );
    if (confirmed) {
      final ok = await cubit.bulkDelete();
      if (ok && context.mounted) {
        showAdminSnackBar(context, 'تم حذف $count إعداد بنجاح.');
      }
    }
  }
}

/// Quiet selection banner replacing the raw MaterialBanner: count on the
/// start edge, the three selection actions on the end edge.
class _SelectionBar extends StatelessWidget {
  const _SelectionBar({
    required this.visible,
    required this.selectedCount,
    required this.isSaving,
    required this.onSelectAll,
    required this.onClear,
    required this.onDelete,
  });

  final bool visible;
  final int selectedCount;
  final bool isSaving;
  final VoidCallback onSelectAll;
  final VoidCallback onClear;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    if (!visible) return const SizedBox.shrink();
    final scheme = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      child: AppCard(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
        child: Row(
          children: [
            Icon(Icons.checklist_rounded, size: 18, color: scheme.primary),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                'تم تحديد $selectedCount إعداد',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleSmall,
              ),
            ),
            TextButton(
              onPressed: isSaving ? null : onSelectAll,
              child: const Text('تحديد الكل'),
            ),
            TextButton(
              onPressed: isSaving ? null : onClear,
              child: const Text('إلغاء'),
            ),
            TextButton.icon(
              key: const ValueKey('bulk-delete-general-settings'),
              onPressed: isSaving ? null : onDelete,
              style: TextButton.styleFrom(foregroundColor: scheme.error),
              icon: const Icon(Icons.delete_outline_rounded, size: 18),
              label: const Text('حذف المحدد'),
            ),
          ],
        ),
      ),
    );
  }
}

/// Row actions collapsed into one overflow menu (wave-1 quiet-card pattern).
class _SettingRowMenu extends StatelessWidget {
  const _SettingRowMenu({
    required this.item,
    required this.canEdit,
    required this.canDelete,
    required this.deleteDisabled,
    required this.onEdit,
    required this.onDelete,
  });

  final GeneralSetting item;
  final bool canEdit;
  final bool canDelete;
  final bool deleteDisabled;
  final VoidCallback onEdit;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    if (!canEdit && !canDelete) return const SizedBox.shrink();
    final scheme = Theme.of(context).colorScheme;
    return PopupMenuButton<String>(
      key: ValueKey('setting-actions-${item.id}'),
      tooltip: 'خيارات الإعداد',
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.lg)),
      onSelected: (value) {
        if (value == 'edit') onEdit();
        if (value == 'delete') onDelete();
      },
      itemBuilder: (context) => [
        if (canEdit)
          const PopupMenuItem(
            value: 'edit',
            child: Row(
              children: [
                Icon(Icons.edit_outlined, size: 18),
                SizedBox(width: 10),
                Text('تعديل'),
              ],
            ),
          ),
        if (canDelete)
          PopupMenuItem(
            value: 'delete',
            enabled: !deleteDisabled,
            child: Row(
              children: [
                Icon(Icons.delete_outline_rounded, size: 18, color: scheme.error),
                const SizedBox(width: 10),
                Text('حذف', style: TextStyle(color: scheme.error)),
              ],
            ),
          ),
      ],
    );
  }
}
