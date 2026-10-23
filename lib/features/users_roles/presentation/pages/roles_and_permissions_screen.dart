import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

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
        return Scaffold(
          appBar: AppBar(
            title: const Text('الأدوار ومصفوفة Shield'),
            actions: [
              IconButton(
                onPressed: () => context.read<RolesCubit>().load(),
                icon: const Icon(Icons.refresh_rounded),
              ),
            ],
          ),
          floatingActionButton: AdminPermissionGuard(
            permission: AdminPermissions.createRoles,
            can: can,
            child: FloatingActionButton.extended(
              key: const ValueKey('create-role'),
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
              icon: const Icon(Icons.add_moderator_outlined),
              label: const Text('إضافة دور'),
            ),
          ),
          body: _buildBody(context, state),
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
    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 100),
      itemCount: state.roles.length,
      separatorBuilder: (context, index) => const SizedBox(height: 10),
      itemBuilder: (context, index) {
        final role = state.roles[index];
        return AdminCard(
          padding: EdgeInsets.zero,
          child: ListTile(
            contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            leading: CircleAvatar(
              backgroundColor: role.isSuperAdmin
                  ? AdminPalette.danger.withAlpha(20)
                  : AdminPalette.primary.withAlpha(20),
              child: Icon(
                role.isSuperAdmin ? Icons.security_rounded : Icons.admin_panel_settings_outlined,
                color: role.isSuperAdmin ? AdminPalette.danger : AdminPalette.primary,
              ),
            ),
            title: LtrText(role.name, style: const TextStyle(fontWeight: FontWeight.w800)),
            subtitle: Wrap(
              spacing: 8,
              runSpacing: 6,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                AdminStatusBadge(label: role.guardName, color: AdminPalette.warning),
                Text('${role.effectivePermissionsCount} صلاحية'),
                Text('آخر تحديث ${AdminFormat.dateTime(role.updatedAt)}'),
              ],
            ),
            trailing: SizedBox(
              width: 100,
              child: Row(
                children: [
                  if (can(AdminPermissions.updateRoles))
                    IconButton(
                      key: ValueKey('edit-role-${role.id}'),
                      tooltip: role.isSuperAdmin ? 'عرض المصفوفة المحمية' : 'تعديل المصفوفة',
                      onPressed: state.catalog == null
                          ? null
                          : () => _openEditor(context, state.catalog!, role),
                      icon: Icon(role.isSuperAdmin ? Icons.visibility_outlined : Icons.edit_outlined),
                    ),
                  if (can(AdminPermissions.deleteRoles))
                    IconButton(
                      key: ValueKey('delete-role-${role.id}'),
                      tooltip: role.isSuperAdmin ? 'دور محمي' : 'حذف الدور',
                      onPressed: role.isSuperAdmin || state.deletingIds.contains(role.id)
                          ? null
                          : () => _confirmDelete(context, role),
                      icon: state.deletingIds.contains(role.id)
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.delete_outline_rounded),
                    ),
                ],
              ),
            ),
          ),
        );
      },
    );
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
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('حذف الدور'),
        content: Text('هل تريد حذف دور «${role.name}»؟ لن يُحذف إذا كان مرتبطاً بمستخدمين وفق حماية الخادم.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('حذف')),
        ],
      ),
    );
    if (confirmed ?? false) await cubit.delete(role);
  }
}
