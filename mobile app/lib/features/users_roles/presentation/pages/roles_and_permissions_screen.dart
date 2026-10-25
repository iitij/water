import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/navigation/sub_page.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/widgets/confirm_dialog.dart';
import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../../admin_shared/presentation/widgets/admin_permission_guard.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/rbac_entities.dart';
import '../../domain/repositories/rbac_repository.dart';
import '../cubit/roles_cubit.dart';
import '../widgets/role_matrix_editor.dart';

class RolesAndPermissionsScreen extends StatelessWidget {
  const RolesAndPermissionsScreen({
    required this.repository,
    required this.can,
    super.key,
  });

  final RbacRepository repository;
  final AdminPermissionCheck can;

  @override
  Widget build(BuildContext context) {
    if (!can(AdminPermissions.viewRoles)) {
      return const Scaffold(
        body: AdminErrorView(message: 'لا تملك صلاحية ViewAny:Role لعرض الأدوار.'),
      );
    }
    return BlocProvider(
      create: (_) => RolesCubit(repository)..load(),
      child: _RolesView(can: can),
    );
  }
}

class _RolesView extends StatelessWidget {
  const _RolesView({required this.can});

  final AdminPermissionCheck can;

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<RolesCubit, RolesState>(
      listenWhen: (previous, current) => previous.errorMessage != current.errorMessage,
      listener: (context, state) {
        if (state.errorMessage != null) {
          showAdminSnackBar(context, adminErrorMessage(state.errorMessage!), isError: true);
        }
      },
      builder: (context, state) {
        final catalog = state.catalog;
        return SubPageScaffold(
          title: 'الأدوار ومصفوفة Shield',
          subtitle: 'أدوار النظام وصلاحياتها التفصيلية',
          actions: [
            AppIconButton(
              tooltip: 'تحديث',
              onPressed: () => context.read<RolesCubit>().load(),
              icon: Icons.refresh_rounded,
            ),
            AdminPermissionGuard(
              permission: AdminPermissions.createRoles,
              can: can,
              child: AppIconButton(
                key: const ValueKey('create-role'),
                tooltip: 'إضافة دور',
                onPressed: catalog == null
                    ? null
                    : () => _openEditor(
                          context,
                          catalog,
                          const Role(
                            id: 0,
                            name: '',
                            guardName: 'web',
                            permissions: [],
                          ),
                        ),
                icon: Icons.add_moderator_outlined,
              ),
            ),
          ],
          child: _buildBody(context, state),
        );
      },
    );
  }

  Widget _buildBody(BuildContext context, RolesState state) {
    if (state.isLoading && state.roles.isEmpty) {
      return const Padding(padding: EdgeInsets.all(16), child: AdminLoadingView(itemCount: 4));
    }
    if (state.roles.isEmpty && state.errorMessage != null) {
      return AdminErrorView(
        message: adminErrorMessage(state.errorMessage!),
        onRetry: () => context.read<RolesCubit>().load(),
      );
    }
    if (state.roles.isEmpty) return const AdminEmptyView(title: 'لا توجد أدوار بعد', icon: Icons.admin_panel_settings_outlined);
    final selectionCount = state.selectedIds.length;
    return Column(
      children: [
        if (selectionCount > 0 && can(AdminPermissions.deleteRoles))
          _BulkDeleteBar(
            count: selectionCount,
            isBusy: state.isBulkDeleting,
            onCancel: () => context.read<RolesCubit>().clearSelection(),
            onDelete: () => _confirmBulkDelete(context, selectionCount),
          ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () => context.read<RolesCubit>().load(),
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 100),
              itemCount: state.roles.length,
              separatorBuilder: (context, index) => const SizedBox(height: 10),
              itemBuilder: (context, index) {
                final role = state.roles[index];
                final checked = state.selectedIds.contains(role.id);
                final deleting = state.deletingIds.contains(role.id);
                return GestureDetector(
                  onLongPress: can(AdminPermissions.deleteRoles) && !role.isSuperAdmin
                      ? () => context.read<RolesCubit>().toggleSelect(role)
                      : null,
                  child: AdminCard(
                    padding: const EdgeInsetsDirectional.fromSTEB(12, 10, 12, 10),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.center,
                      children: [
                      if (can(AdminPermissions.deleteRoles) && !role.isSuperAdmin) ...[
                        Checkbox(
                          value: checked,
                          onChanged: (_) => context.read<RolesCubit>().toggleSelect(role),
                          visualDensity: VisualDensity.compact,
                        ),
                        const SizedBox(width: 2),
                      ],
                      AppGlyph(
                        icon: role.isSuperAdmin
                            ? Icons.security_rounded
                            : Icons.admin_panel_settings_outlined,
                        tone: role.isSuperAdmin ? AdminPalette.danger : AdminPalette.primary,
                        size: 40,
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            LtrText(
                              role.name,
                              style: const TextStyle(fontWeight: FontWeight.w600),
                            ),
                            const SizedBox(height: 6),
                            Wrap(
                              spacing: 8,
                              runSpacing: 6,
                              crossAxisAlignment: WrapCrossAlignment.center,
                              children: [
                                AdminStatusBadge(label: role.guardName, color: AdminPalette.warning),
                                Text(
                                  '${role.effectivePermissionsCount} صلاحية',
                                  style: Theme.of(context).textTheme.labelSmall,
                                ),
                                Text(
                                  'آخر تحديث ${AdminFormat.dateTime(role.updatedAt)}',
                                  style: Theme.of(context).textTheme.labelSmall,
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 4),
                      if (can(AdminPermissions.updateRoles))
                        AppIconButton(
                          key: ValueKey('edit-role-${role.id}'),
                          tooltip: role.isSuperAdmin ? 'عرض المصفوفة المحمية' : 'تعديل المصفوفة',
                          onPressed: state.catalog == null
                              ? null
                              : () => _openEditor(context, state.catalog!, role),
                          icon: role.isSuperAdmin ? Icons.visibility_outlined : Icons.edit_outlined,
                          size: 38,
                        ),
                      if (can(AdminPermissions.deleteRoles)) ...[
                        const SizedBox(width: 6),
                        if (deleting)
                          const SizedBox.square(
                            dimension: 38,
                            child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
                          )
                        else
                          AppIconButton(
                            key: ValueKey('delete-role-${role.id}'),
                            tooltip: role.isSuperAdmin ? 'دور محمي' : 'حذف الدور',
                            onPressed: role.isSuperAdmin ? null : () => _confirmDelete(context, role),
                            icon: Icons.delete_outline_rounded,
                            color: role.isSuperAdmin ? null : Theme.of(context).colorScheme.error,
                            size: 38,
                          ),
                      ],
                    ],
                  ),
                ),
                );
              },
            ),
          ),
        ),
      ],
    );
  }

  Future<void> _confirmBulkDelete(BuildContext context, int count) async {
    final cubit = context.read<RolesCubit>();
    final confirmed = await showConfirmDialog(
      context,
      title: 'حذف الأدوار المحددة',
      message: 'هل تريد حذف $count دور محدد؟ الأدوار المرتبطة بمستخدمين لن تُحذف وفق حماية الخادم.',
      confirmLabel: 'حذف المحدد',
      destructive: true,
    );
    if (confirmed) await cubit.bulkDelete();
  }

  Future<void> _openEditor(
    BuildContext context,
    ShieldPermissionCatalog catalog,
    Role role,
  ) {
    final cubit = context.read<RolesCubit>();
    return showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (context) => Dialog.fullscreen(
        child: RoleMatrixEditor(
          role: role,
          catalog: catalog,
          onSave: cubit.save,
        ),
      ),
    );
  }

  Future<void> _confirmDelete(BuildContext context, Role role) async {
    final cubit = context.read<RolesCubit>();
    final confirmed = await showConfirmDialog(
      context,
      title: 'حذف الدور',
      message: 'هل تريد حذف دور «${role.name}»؟ لن يُحذف إذا كان مرتبطاً بمستخدمين وفق حماية الخادم.',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (confirmed) await cubit.delete(role);
  }
}

/// Selection bar for the bulk delete flow. Kept visually quiet: a soft accent
/// surface with a hairline, the count and the destructive action.
class _BulkDeleteBar extends StatelessWidget {
  const _BulkDeleteBar({
    required this.count,
    required this.isBusy,
    required this.onCancel,
    required this.onDelete,
  });

  final int count;
  final bool isBusy;
  final VoidCallback onCancel;
  final VoidCallback onDelete;

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
              'تم تحديد $count دور',
              style: theme.textTheme.labelMedium?.copyWith(
                color: AppColors.inkSoft,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          TextButton(
            onPressed: isBusy ? null : onCancel,
            child: const Text('إلغاء'),
          ),
          if (isBusy)
            const Padding(
              padding: EdgeInsetsDirectional.only(start: 12, end: 12),
              child: SizedBox.square(
                dimension: 16,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            )
          else
            TextButton.icon(
              onPressed: onDelete,
              icon: const Icon(Icons.delete_sweep_rounded, size: 18),
              label: const Text('حذف المحدد'),
              style: TextButton.styleFrom(foregroundColor: AppColors.danger),
            ),
        ],
      ),
    );
  }
}
