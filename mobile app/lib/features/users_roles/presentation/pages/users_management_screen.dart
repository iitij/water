import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:image_picker/image_picker.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/list_presentation_controls.dart';
import '../../../../core/widgets/confirm_dialog.dart';
import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../../admin_shared/presentation/widgets/admin_permission_guard.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/rbac_entities.dart';
import '../../domain/repositories/rbac_repository.dart';
import '../cubit/users_cubit.dart';

class UsersManagementScreen extends StatelessWidget {
  const UsersManagementScreen({
    required this.repository,
    required this.can,
    this.currentUserId,
    super.key,
  });

  final RbacRepository repository;
  final AdminPermissionCheck can;
  final int? currentUserId;

  @override
  Widget build(BuildContext context) {
    if (!can(AdminPermissions.viewUsers)) {
      return const Scaffold(
        body: AdminErrorView(message: 'لا تملك صلاحية ViewAny:User لعرض المستخدمين.'),
      );
    }
    return BlocProvider(
      create: (_) => UsersCubit(repository, currentUserId: currentUserId)..load(),
      child: _UsersView(can: can, currentUserId: currentUserId),
    );
  }
}

class _UsersView extends StatelessWidget {
  const _UsersView({required this.can, this.currentUserId});

  final AdminPermissionCheck can;
  final int? currentUserId;

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<UsersCubit, UsersState>(
      listenWhen: (previous, current) => previous.errorMessage != current.errorMessage,
      listener: (context, state) {
        if (state.errorMessage != null) {
          showAdminSnackBar(context, adminErrorMessage(state.errorMessage!), isError: true);
        }
      },
      builder: (context, state) {
        return SubPageScaffold(
          title: 'إدارة المستخدمين',
          subtitle: 'الحسابات والأدوار وحالة الوصول',
          actions: [
            AppIconButton(
              tooltip: 'تحديث',
              onPressed: () => context.read<UsersCubit>().load(),
              icon: Icons.refresh_rounded,
            ),
            AdminPermissionGuard(
              permission: AdminPermissions.createUsers,
              can: can,
              child: AppIconButton(
                key: const ValueKey('create-user'),
                tooltip: 'إضافة مستخدم',
                onPressed: state.isSaving ? null : () => _openUserEditor(context, state),
                icon: Icons.person_add_alt_1_rounded,
              ),
            ),
          ],
          child: Column(
            children: [
              _UsersFilters(state: state),
              if (state.selectedIds.isNotEmpty && can(AdminPermissions.deleteAnyUsers))
                _BulkDeleteBar(count: state.selectedIds.length, isBusy: state.isSaving),
              Expanded(child: _buildUsers(context, state)),
            ],
          ),
        );
      },
    );
  }

  Widget _buildUsers(BuildContext context, UsersState state) {
    if (state.isLoading && state.users.isEmpty) {
      return const Padding(padding: EdgeInsets.all(16), child: AdminLoadingView(itemCount: 4));
    }
    if (state.users.isEmpty && state.errorMessage != null) {
      return AdminErrorView(
        message: adminErrorMessage(state.errorMessage!),
        onRetry: () => context.read<UsersCubit>().load(),
      );
    }
    final users = state.visibleUsers;
    if (users.isEmpty) return const AdminEmptyView(title: 'لا توجد حسابات مطابقة', icon: Icons.group_outlined);
    return ListPresentation<SystemUser>(items: users,
      sortValues: {
        'name': (label: ListOptionsStrings.name, value: (user) => user.name),
        'id': (label: ListOptionsStrings.id, value: (user) => user.id),
        'email': (label: ListOptionsStrings.email, value: (user) => user.email),
        'created_at': (label: ListOptionsStrings.createdAt, value: (user) => user.createdAt),
      },
      fields: const {'email': ListOptionsStrings.email, 'roles': ListOptionsStrings.roles,
        'created_at': ListOptionsStrings.createdAt},
      builder: (context, pageUsers, options) => ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 100),
      itemCount: pageUsers.length,
      separatorBuilder: (context, index) => const SizedBox(height: 8),
      itemBuilder: (context, index) {
        final user = pageUsers[index];
        final isCurrent = user.id == currentUserId;
        final deleting = state.deletingIds.contains(user.id);
        return AdminCard(
          padding: EdgeInsetsDirectional.fromSTEB(12, options.compact ? 6 : 10, 12, options.compact ? 6 : 10),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              if (can(AdminPermissions.deleteUsers)) ...[
                Checkbox(
                  value: state.selectedIds.contains(user.id),
                  onChanged: isCurrent
                      ? null
                      : (value) => context.read<UsersCubit>().toggleSelected(user.id, value ?? false),
                ),
                const SizedBox(width: 2),
              ],
              AppPhotoAvatar(url: user.avatarUrl, size: 40),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      isCurrent ? '${user.name} (أنت)' : user.name,
                      overflow: TextOverflow.ellipsis,
                      maxLines: 1,
                      style: const TextStyle(fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(height: 2),
                    if (options.isVisible('email')) LtrText(user.email, style: Theme.of(context).textTheme.bodySmall),
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 5,
                      runSpacing: 5,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        for (final role in options.isVisible('roles') ? user.roles : <String>[])
                          AdminStatusBadge(label: _roleName(role), color: _roleColor(role)),
                        if (options.isVisible('created_at')) Text(
                          'أضيف ${AdminFormat.date(user.createdAt)}',
                          style: Theme.of(context).textTheme.labelSmall,
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 4),
              if (can(AdminPermissions.updateUsers))
                AppIconButton(
                  key: ValueKey('edit-user-${user.id}'),
                  tooltip: 'تعديل',
                  onPressed: () => _openUserEditor(context, state, user),
                  icon: Icons.edit_outlined,
                  size: 38,
                ),
              if (can(AdminPermissions.deleteUsers)) ...[
                const SizedBox(width: 6),
                if (deleting)
                  const SizedBox.square(
                    dimension: 38,
                    child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
                  )
                else
                  AppIconButton(
                    key: ValueKey('delete-user-${user.id}'),
                    tooltip: isCurrent ? 'لا يمكن حذف حسابك' : 'حذف',
                    onPressed: isCurrent ? null : () => _confirmDelete(context, user),
                    icon: Icons.delete_outline_rounded,
                    color: isCurrent ? null : Theme.of(context).colorScheme.error,
                    size: 38,
                  ),
              ],
            ],
          ),
        );
      },
    ));
  }

  Future<void> _openUserEditor(BuildContext context, UsersState state, [SystemUser? user]) async {
    final cubit = context.read<UsersCubit>();
    final formKey = GlobalKey<FormState>();
    final nameController = TextEditingController(text: user?.name ?? '');
    final emailController = TextEditingController(text: user?.email ?? '');
    final passwordController = TextEditingController();
    final selectedRoles = {...?user?.roles};
    var obscurePassword = true;
    var roleError = false;
    Uint8List? avatarBytes;
    String? avatarFileName;
    var removeAvatar = false;
    final picker = ImagePicker();
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, setState) {
          Future<void> pickAvatar(ImageSource source) async {
            try {
              final picked = await picker.pickImage(
                source: source,
                imageQuality: 85,
                maxWidth: 1024,
              );
              if (picked == null) return;
              final bytes = await picked.readAsBytes();
              if (!dialogContext.mounted) return;
              setState(() {
                avatarBytes = bytes;
                avatarFileName = picked.name;
                removeAvatar = false;
              });
            } catch (_) {
              if (dialogContext.mounted) {
                showAdminSnackBar(
                  dialogContext,
                  'تعذّر اختيار الصورة الشخصية.',
                  isError: true,
                );
              }
            }
          }

          Future<void> save() async {
            final valid = formKey.currentState?.validate() ?? false;
            setState(() => roleError = selectedRoles.isEmpty);
            if (!valid || selectedRoles.isEmpty) return;
            if (user == null && passwordController.text.length < 8) {
              formKey.currentState?.validate();
              return;
            }
            final edited = SystemUser(
              id: user?.id ?? 0,
              name: nameController.text.trim(),
              email: emailController.text.trim(),
              avatarUrl: removeAvatar ? null : user?.avatarUrl,
              roles: selectedRoles.toList()..sort(),
              permissions: user?.permissions ?? const [],
              createdAt: user?.createdAt,
            );
            final success = await cubit.save(
              edited,
              password: passwordController.text.isEmpty ? null : passwordController.text,
              avatarBytes: avatarBytes,
              avatarFileName: avatarFileName,
              removeAvatar: removeAvatar,
            );
            if (success && dialogContext.mounted) Navigator.pop(dialogContext);
          }

          final ImageProvider<Object>? avatarProvider = avatarBytes != null
              ? MemoryImage(avatarBytes!)
              : (!removeAvatar && user?.avatarUrl != null && user!.avatarUrl!.isNotEmpty)
                  ? NetworkImage(user.avatarUrl!)
                  : null;

          return AlertDialog(
            title: Text(user == null ? 'إضافة مستخدم' : 'تعديل ${user.name}'),
            content: SizedBox(
              width: 640,
              child: SingleChildScrollView(
                child: Form(
                  key: formKey,
                  autovalidateMode: AutovalidateMode.onUserInteraction,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        children: [
                          _EditableAvatar(provider: avatarProvider),
                          const SizedBox(width: 14),
                          Expanded(
                            child: Wrap(
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                OutlinedButton.icon(
                                  key: const ValueKey('pick-user-avatar-gallery'),
                                  onPressed: () => pickAvatar(ImageSource.gallery),
                                  icon: const Icon(Icons.photo_library_outlined, size: 18),
                                  label: const Text('اختيار صورة'),
                                ),
                                OutlinedButton.icon(
                                  key: const ValueKey('pick-user-avatar-camera'),
                                  onPressed: () => pickAvatar(ImageSource.camera),
                                  icon: const Icon(Icons.photo_camera_outlined, size: 18),
                                  label: const Text('الكاميرا'),
                                ),
                                if (avatarProvider != null)
                                  TextButton.icon(
                                    key: const ValueKey('remove-user-avatar'),
                                    onPressed: () => setState(() {
                                      avatarBytes = null;
                                      avatarFileName = null;
                                      removeAvatar = true;
                                    }),
                                    style: TextButton.styleFrom(
                                      foregroundColor: AdminPalette.danger,
                                    ),
                                    icon: const Icon(Icons.delete_outline, size: 18),
                                    label: const Text('إزالة الصورة'),
                                  ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 16),
                      TextFormField(
                        controller: nameController,
                        validator: (value) => value == null || value.trim().isEmpty ? 'الاسم مطلوب' : null,
                        decoration: const InputDecoration(labelText: 'الاسم'),
                      ),
                      const SizedBox(height: 12),
                      Directionality(
                        textDirection: TextDirection.ltr,
                        child: TextFormField(
                          controller: emailController,
                          keyboardType: TextInputType.emailAddress,
                          validator: (value) {
                            if (value == null || value.trim().isEmpty) return 'البريد الإلكتروني مطلوب';
                            return RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(value.trim())
                                ? null
                                : 'أدخل بريداً إلكترونياً صالحاً';
                          },
                          decoration: const InputDecoration(labelText: 'Email address'),
                        ),
                      ),
                      const SizedBox(height: 12),
                      Directionality(
                        textDirection: TextDirection.ltr,
                        child: TextFormField(
                          controller: passwordController,
                          obscureText: obscurePassword,
                          validator: (value) {
                            if (user == null && (value == null || value.length < 8)) {
                              return 'كلمة المرور مطلوبة (8 أحرف على الأقل)';
                            }
                            if (value != null && value.isNotEmpty && value.length < 8) {
                              return 'كلمة المرور يجب ألا تقل عن 8 أحرف';
                            }
                            return null;
                          },
                          decoration: InputDecoration(
                            labelText: user == null ? 'كلمة المرور' : 'كلمة مرور جديدة (اختياري)',
                            suffixIcon: IconButton(
                              onPressed: () => setState(() => obscurePassword = !obscurePassword),
                              icon: Icon(obscurePassword ? Icons.visibility : Icons.visibility_off),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(height: 16),
                      Text('الأدوار والصلاحيات', style: Theme.of(dialogContext).textTheme.titleMedium),
                      if (roleError)
                        const Text('اختر دوراً واحداً على الأقل', style: TextStyle(color: AdminPalette.danger)),
                      if (state.availableRoles.isEmpty)
                        const Text('لا توجد أدوار متاحة. أنشئ دوراً أولاً.')
                      else
                        for (final role in state.availableRoles)
                          CheckboxListTile(
                            key: ValueKey('user-role-${role.name}'),
                            contentPadding: EdgeInsets.zero,
                            dense: true,
                            title: LtrText(role.name),
                            subtitle: Text('${role.effectivePermissionsCount} صلاحية'),
                            value: selectedRoles.contains(role.name),
                            onChanged: (value) => setState(() {
                              if (value ?? false) {
                                selectedRoles.add(role.name);
                              } else {
                                selectedRoles.remove(role.name);
                              }
                              roleError = false;
                            }),
                          ),
                    ],
                  ),
                ),
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(dialogContext), child: const Text('إلغاء')),
              FilledButton.icon(
                onPressed: state.isSaving ? null : save,
                icon: const Icon(Icons.save_outlined),
                label: const Text('حفظ المستخدم'),
              ),
            ],
          );
        },
      ),
    );
    nameController.dispose();
    emailController.dispose();
    passwordController.dispose();
  }

  Future<void> _confirmDelete(BuildContext context, SystemUser user) async {
    final cubit = context.read<UsersCubit>();
    final confirmed = await showConfirmDialog(
      context,
      title: 'حذف المستخدم',
      message: 'هل تريد حذف حساب «${user.name}»؟',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (confirmed) await cubit.delete(user);
  }
}

class _UsersFilters extends StatelessWidget {
  const _UsersFilters({required this.state});

  final UsersState state;

  @override
  Widget build(BuildContext context) {
    final roleFilter = AppFilterDropdown<String>(
      value: state.roleFilter,
      label: 'فلترة بالدور',
      icon: Icons.badge_outlined,
      items: [
        const DropdownMenuItem<String>(child: Text('كل الأدوار')),
        for (final role in state.availableRoles)
          DropdownMenuItem<String>(value: role.name, child: LtrText(role.name)),
      ],
      onChanged: context.read<UsersCubit>().filterRole,
    );
    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 14, 16, 4),
      child: LayoutBuilder(
        builder: (context, constraints) {
          if (constraints.maxWidth < 650) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                AppFilterBar(
                  query: state.query,
                  hint: 'بحث بالاسم أو البريد أو الدور',
                  onChanged: context.read<UsersCubit>().search,
                  padding: EdgeInsets.zero,
                ),
                const SizedBox(height: 8),
                roleFilter,
              ],
            );
          }
          return Row(
            children: [
              Expanded(
                child: AppFilterBar(
                  query: state.query,
                  hint: 'بحث بالاسم أو البريد أو الدور',
                  onChanged: context.read<UsersCubit>().search,
                  padding: EdgeInsets.zero,
                ),
              ),
              const SizedBox(width: 12),
              SizedBox(width: 230, child: roleFilter),
            ],
          );
        },
      ),
    );
  }
}

class _BulkDeleteBar extends StatelessWidget {
  const _BulkDeleteBar({required this.count, required this.isBusy});

  final int count;
  final bool isBusy;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      margin: const EdgeInsetsDirectional.fromSTEB(16, 12, 16, 0),
      padding: const EdgeInsetsDirectional.fromSTEB(12, 6, 12, 6),
      decoration: BoxDecoration(
        color: AppColors.accentSoft,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          const Icon(Icons.checklist_rounded, size: 18, color: AppColors.accentStrong),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              'تم تحديد $count مستخدم',
              style: theme.textTheme.labelMedium?.copyWith(
                color: AppColors.inkSoft,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          TextButton.icon(
            onPressed: isBusy ? null : context.read<UsersCubit>().bulkDelete,
            icon: const Icon(Icons.delete_outline_rounded, size: 18),
            label: const Text('حذف المحدد'),
            style: TextButton.styleFrom(foregroundColor: AppColors.danger),
          ),
        ],
      ),
    );
  }
}

String _roleName(String role) => switch (role) {
      'super_admin' => 'مدير النظام الأعلى',
      'admin' => 'مدير نظام',
      'collector' => 'محصل',
      'reader' || 'meter_reader' => 'قارئ عداد',
      _ => role,
    };

Color _roleColor(String role) => switch (role) {
      'super_admin' || 'admin' => AdminPalette.danger,
      'collector' => AdminPalette.success,
      'reader' || 'meter_reader' => AdminPalette.info,
      _ => AdminPalette.neutral,
    };

class _EditableAvatar extends StatelessWidget {
  const _EditableAvatar({required this.provider});

  final ImageProvider<Object>? provider;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final imageProvider = provider;
    return Container(
      width: 64,
      height: 64,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(AppRadius.xxl),
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: imageProvider == null
          ? Icon(Icons.person_outline_rounded, size: 28, color: scheme.onSurfaceVariant)
          : Image(image: imageProvider, fit: BoxFit.cover),
    );
  }
}
