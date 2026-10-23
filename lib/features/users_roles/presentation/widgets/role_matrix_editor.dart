import 'package:flutter/material.dart';

import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../domain/entities/rbac_entities.dart';

class RoleMatrixEditor extends StatefulWidget {
  const RoleMatrixEditor({
    required this.role,
    required this.catalog,
    required this.onSave,
    super.key,
  });

  final Role role;
  final ShieldPermissionCatalog catalog;
  final Future<bool> Function(Role role) onSave;

  @override
  State<RoleMatrixEditor> createState() => _RoleMatrixEditorState();
}

class _RoleMatrixEditorState extends State<RoleMatrixEditor> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _nameController;
  late Set<String> _selected;
  bool _saving = false;

  bool get _readOnly => widget.role.isSuperAdmin;

  @override
  void initState() {
    super.initState();
    _nameController = TextEditingController(text: widget.role.name);
    _selected = _readOnly
        ? widget.catalog.allNames
        : widget.role.permissions.toSet();
  }

  @override
  void dispose() {
    _nameController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final allNames = widget.catalog.allNames;
    final allSelected = allNames.isNotEmpty && _selected.containsAll(allNames);
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        appBar: AppBar(
          title: Text(widget.role.id == 0 ? 'إنشاء دور ومصفوفة صلاحيات' : 'مصفوفة: ${widget.role.name}'),
          automaticallyImplyLeading: false,
          actions: [
            IconButton(
              tooltip: 'إغلاق',
              onPressed: _saving ? null : () => Navigator.pop(context),
              icon: const Icon(Icons.close_rounded),
            ),
          ],
          bottom: TabBar(
            tabs: [
              Tab(text: 'الموارد (${widget.catalog.byKind(ShieldPermissionKind.resource).length})'),
              Tab(text: 'الصفحات (${widget.catalog.byKind(ShieldPermissionKind.page).length})'),
              Tab(text: 'الودجات (${widget.catalog.byKind(ShieldPermissionKind.widget).length})'),
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
                      enabled: !_readOnly,
                      textDirection: TextDirection.ltr,
                      validator: (value) => value == null || value.trim().isEmpty
                          ? 'اسم الدور مطلوب'
                          : !RegExp(r'^[a-zA-Z0-9_\-]+$').hasMatch(value.trim())
                              ? 'استخدم أحرفاً إنجليزية وأرقاماً و _ فقط'
                              : null,
                      decoration: const InputDecoration(
                        labelText: 'اسم الدور (name)',
                        border: OutlineInputBorder(),
                      ),
                    );
                    final guard = TextFormField(
                      initialValue: widget.role.guardName,
                      enabled: false,
                      textDirection: TextDirection.ltr,
                      decoration: const InputDecoration(
                        labelText: 'Guard',
                        border: OutlineInputBorder(),
                      ),
                    );
                    final selectAll = SwitchListTile(
                      key: const ValueKey('select-all-permissions'),
                      value: allSelected,
                      onChanged: _readOnly
                          ? null
                          : (value) => setState(() {
                                _selected = value ? {...allNames} : {};
                              }),
                      title: const Text('تحديد الكل (Select All)'),
                      subtitle: Text('${_selected.length} من ${allNames.length} صلاحية محددة'),
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
              if (_readOnly)
                const MaterialBanner(
                  content: Text('super_admin يملك وصولاً ضمنياً كاملاً؛ المصفوفة للعرض فقط.'),
                  leading: Icon(Icons.lock_outline_rounded),
                  actions: [SizedBox.shrink()],
                ),
              Expanded(
                child: TabBarView(
                  children: [
                    for (final kind in ShieldPermissionKind.values)
                      _PermissionGroupsList(
                        groups: widget.catalog.byKind(kind),
                        selected: _selected,
                        readOnly: _readOnly,
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
          child: FilledButton.icon(
            key: const ValueKey('save-role-matrix'),
            onPressed: _readOnly || _saving ? null : _save,
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
            icon: _saving
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : const Icon(Icons.save_outlined),
            label: Text(_readOnly ? 'دور محمي — للعرض فقط' : 'حفظ الدور ومزامنة الصلاحيات'),
          ),
        ),
      ),
    );
  }

  Future<void> _save() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    setState(() => _saving = true);
    final role = Role(
      id: widget.role.id,
      name: _nameController.text.trim(),
      guardName: widget.role.guardName,
      permissions: _selected.toList()..sort(),
      permissionsCount: _selected.length,
      updatedAt: widget.role.updatedAt,
    );
    final success = await widget.onSave(role);
    if (!mounted) return;
    setState(() => _saving = false);
    if (success) Navigator.pop(context);
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
        return Card(
          elevation: 0,
          margin: const EdgeInsets.only(bottom: 10),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
            side: BorderSide(color: Theme.of(context).colorScheme.outlineVariant),
          ),
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
                  child: Text(group.label, style: const TextStyle(fontWeight: FontWeight.w800)),
                ),
                Text('$count/${names.length}', style: const TextStyle(color: AdminPalette.primary)),
              ],
            ),
            subtitle: LtrDirectionalText(group.key),
            childrenPadding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
            children: [
              const Divider(),
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
