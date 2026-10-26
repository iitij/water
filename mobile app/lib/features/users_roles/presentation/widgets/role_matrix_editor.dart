import 'package:flutter/material.dart';
import '../../../../core/l10n/app_strings.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../domain/entities/rbac_entities.dart';

class RoleMatrixEditor extends StatefulWidget {
  const RoleMatrixEditor({
    required this.role,
    required this.catalog,
    required this.onSave,
    this.readOnly = false,
    super.key,
  });

  final Role role;
  final ShieldPermissionCatalog catalog;
  final Future<bool> Function(Role role) onSave;
  final bool readOnly;

  @override
  State<RoleMatrixEditor> createState() => _RoleMatrixEditorState();
}

class _RoleMatrixEditorState extends State<RoleMatrixEditor> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _nameController;
  late Set<String> _selected;
  late String _guard;
  int _guardRevision = 0;
  String? _error;
  bool _saving = false;
  ShieldPermissionCatalog get _catalog => widget.catalog.forGuard(_guard);

  bool get _readOnly => widget.readOnly || widget.role.isSuperAdmin;

  @override
  void initState() {
    super.initState();
    _nameController = TextEditingController(text: widget.role.name);
    _guard = widget.role.guardName;
    _selected = widget.role.isSuperAdmin
        ? _catalog.allNames
        : widget.role.permissions.toSet();
  }

  @override
  void dispose() {
    _nameController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final allNames = _catalog.allNames;
    final allSelected = allNames.isNotEmpty && _selected.containsAll(allNames);
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        appBar: AppBar(
          title: Text(widget.role.id == 0 ? 'إنشاء دور ومصفوفة صلاحيات' : 'مصفوفة: ${widget.role.name}'),
          automaticallyImplyLeading: false,
          actions: [
            AppIconButton(
              tooltip: 'إغلاق',
              onPressed: _saving ? null : () => Navigator.pop(context),
              icon: Icons.close_rounded,
            ),
          ],
          bottom: TabBar(
            tabs: [
              Tab(text: 'الموارد (${_catalog.byKind(ShieldPermissionKind.resource).length})'),
              Tab(text: 'الصفحات (${_catalog.byKind(ShieldPermissionKind.page).length})'),
              Tab(text: 'الودجات (${_catalog.byKind(ShieldPermissionKind.widget).length})'),
            ],
          ),
        ),
        body: Form(
          key: _formKey,
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsets.all(16),
                child: LayoutBuilder(
                  builder: (context, constraints) {
                    final name = TextFormField(
                      key: const ValueKey('role-name'),
                      controller: _nameController,
                      enabled: !_readOnly && !_saving,
                      textDirection: TextDirection.ltr,
                      validator: (value) => value == null || value.trim().isEmpty
                          ? 'اسم الدور مطلوب'
                          : !RegExp(r'^[a-zA-Z0-9_\-]+$').hasMatch(value.trim())
                              ? 'استخدم أحرفاً إنجليزية وأرقاماً و _ فقط'
                              : null,
                      decoration: const InputDecoration(
                        labelText: 'اسم الدور (name)',
                      ),
                    );
                    final guard = DropdownButtonFormField<String>(
                      key: ValueKey('role-guard-$_guard-$_guardRevision'), initialValue: _guard,
                      decoration: InputDecoration(labelText: AppStrings.of(context).roleGuard),
                      items: [for (final guard in {...widget.catalog.guardNames, _guard})
                        DropdownMenuItem(value: guard, child: Text(guard, textDirection: TextDirection.ltr))],
                      onChanged: _readOnly || _saving ? null : _changeGuard,
                    );
                    final selectAll = AppSettingRow(
                      title: 'تحديد الكل (Select All)',
                      subtitle: '${_selected.length} من ${allNames.length} صلاحية محددة',
                      icon: Icons.select_all_rounded,
                      color: AdminPalette.primary,
                      showChevron: false,
                      trailing: Switch(
                        key: const ValueKey('select-all-permissions'),
                        value: allSelected,
                        onChanged: _readOnly || _saving
                            ? null
                            : (value) => setState(() {
                                  _selected = value ? {...allNames} : {};
                                }),
                      ),
                    );
                    if (constraints.maxWidth < 800) {
                      return Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [name, const SizedBox(height: 10), guard, selectAll],
                      );
                    }
                    return Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(flex: 2, child: name),
                        const SizedBox(width: 12),
                        Expanded(child: guard),
                        const SizedBox(width: 12),
                        Expanded(flex: 2, child: selectAll),
                      ],
                    );
                  },
                ),
              ),
              if (widget.role.isSuperAdmin)
                const AppNotice(
                  title: 'دور محمي',
                  message: 'super_admin يملك وصولاً ضمنياً كاملاً؛ المصفوفة للعرض فقط.',
                  icon: Icons.lock_outline_rounded,
                  color: AppColors.warning,
                ),
              if (_error != null) Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
              ),
              Expanded(
                child: TabBarView(
                  children: [
                    for (final kind in ShieldPermissionKind.values)
                      _PermissionGroupsList(
                        groups: _catalog.byKind(kind),
                        selected: _selected,
                        readOnly: _readOnly || _saving,
                        onChanged: (names, selected) => setState(() {
                          if (selected) {
                            _selected.addAll(names);
                          } else {
                            _selected.removeAll(names);
                          }
                        }),
                        onPermissionChanged: (name, selected) => setState(() {
                          if (selected) {
                            _selected.add(name);
                          } else {
                            _selected.remove(name);
                          }
                        }),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
        bottomNavigationBar: SafeArea(
          minimum: const EdgeInsets.all(16),
          child: AppPrimaryButton(
            key: const ValueKey('save-role-matrix'),
            onPressed: _readOnly || _saving ? null : _save,
            icon: Icons.save_outlined,
            loading: _saving,
            label: _readOnly ? AppStrings.of(context).roleReadOnly : 'حفظ الدور ومزامنة الصلاحيات',
          ),
        ),
      ),
    );
  }

  Future<void> _save() async {
    if (_readOnly || _saving) return;
    if (!(_formKey.currentState?.validate() ?? false)) return;
    setState(() { _saving = true; _error = null; });
    final role = Role(
      id: widget.role.id,
      name: _nameController.text.trim(),
      guardName: _guard,
      permissions: _selected.toList()..sort(),
      permissionsCount: _selected.length,
      updatedAt: widget.role.updatedAt,
    );
    try {
      final success = await widget.onSave(role);
      if (!mounted) return;
      setState(() { _saving = false; _error = success ? null : AppStrings.of(context).unknownError; });
      if (success) Navigator.pop(context);
    } catch (_) {
      if (mounted) setState(() { _saving = false; _error = AppStrings.of(context).unknownError; });
    }
  }

  Future<void> _changeGuard(String? guard) async {
    if (guard == null || guard == _guard || _saving || _readOnly) return;
    final strings = AppStrings.of(context);
    final confirmed = await showDialog<bool>(context: context, builder: (context) => AlertDialog(
      title: Text(strings.roleGuardChangeTitle), content: Text(strings.roleGuardChangeMessage),
      actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: Text(strings.cancel)),
        FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(strings.roleGuardConfirm))],
    ));
    if (!mounted) return;
    if (confirmed != true) { setState(() => _guardRevision++); return; }
    final allowed = widget.catalog.forGuard(guard).allNames;
    setState(() { _guard = guard; _guardRevision++; _selected = _selected.intersection(allowed); _error = null; });
  }
}

class _PermissionGroupsList extends StatelessWidget {
  const _PermissionGroupsList({
    required this.groups,
    required this.selected,
    required this.readOnly,
    required this.onChanged,
    required this.onPermissionChanged,
  });

  final List<ShieldPermissionGroup> groups;
  final Set<String> selected;
  final bool readOnly;
  final void Function(Set<String> names, bool selected) onChanged;
  final void Function(String name, bool selected) onPermissionChanged;

  @override
  Widget build(BuildContext context) {
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 20),
      itemCount: groups.length,
      itemBuilder: (context, index) {
        final group = groups[index];
        final names = group.permissions.map((permission) => permission.name).toSet();
        final count = names.where(selected.contains).length;
        final checkboxValue = count == 0 ? false : count == names.length ? true : null;
        return Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: AppCard(
            padding: EdgeInsets.zero,
            child: ExpansionTile(
            key: ValueKey('permission-group-${group.key}'),
            initiallyExpanded: group.kind != ShieldPermissionKind.resource,
            title: Row(
              children: [
                Checkbox(
                  key: ValueKey('select-group-${group.key}'),
                  tristate: true,
                  value: checkboxValue,
                  onChanged: readOnly
                      ? null
                      : (_) => onChanged(names, checkboxValue != true),
                ),
                Expanded(
                  child: Text(group.label, style: const TextStyle(fontWeight: FontWeight.w600)),
                ),
                Text('$count/${names.length}', style: const TextStyle(color: AdminPalette.primary)),
              ],
            ),
            subtitle: LtrDirectionalText(group.key),
            childrenPadding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
            children: [
              const AppDivider(),
              ...group.permissions.map(
                (permission) => CheckboxListTile(
                  key: ValueKey('permission-${permission.name}'),
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  title: Text(permission.label),
                  subtitle: LtrDirectionalText(permission.name),
                  value: selected.contains(permission.name),
                  onChanged: readOnly
                      ? null
                      : (value) => onPermissionChanged(permission.name, value ?? false),
                ),
              ),
            ],
          ),
          ),
        );
      },
    );
  }
}

class LtrDirectionalText extends StatelessWidget {
  const LtrDirectionalText(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: TextDirection.ltr,
        child: Text(text, style: Theme.of(context).textTheme.bodySmall),
      );
}
