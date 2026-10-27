import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../core/auth/session_service.dart';
import '../../../core/l10n/owner_ux_strings.dart';
import '../../../core/permissions/permission_service.dart';
import '../../../core/router/route_names.dart';
import '../../../core/ui/app_filters.dart';
import '../domain/workspace_catalog.dart';
import 'widgets/workspace_widgets.dart';

class ServicesScreen extends StatelessWidget {
  const ServicesScreen({super.key, this.autofocus = false});
  final bool autofocus;

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: GetIt.I<SessionService>(),
    builder: (context, _) => ServicesDirectory(
      can: GetIt.I<PermissionService>().can,
      autofocus: autofocus,
      onNavigate: (route) {
        if (route == RouteNames.profile || route == RouteNames.offlineSync) {
          context.go(route);
        } else {
          context.push(route);
        }
      },
    ),
  );
}

class ServicesDirectory extends StatefulWidget {
  const ServicesDirectory({
    super.key,
    required this.can,
    required this.onNavigate,
    this.autofocus = false,
  });
  final bool Function(String) can;
  final ValueChanged<String> onNavigate;
  final bool autofocus;

  @override
  State<ServicesDirectory> createState() => _ServicesDirectoryState();
}

class _ServicesDirectoryState extends State<ServicesDirectory> {
  final _search = TextEditingController();
  final _searchFocus = FocusNode();
  WorkspaceGroup? _group;

  @override
  void dispose() {
    _search.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(ServicesDirectory oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.autofocus) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _searchFocus.requestFocus();
      });
    }
    // A changed account/permission set must not leave an invisible category selected.
    if (_group != null &&
        !WorkspaceCatalog.visible(widget.can).any((e) => e.group == _group)) {
      _group = null;
    }
  }

  void _clear() => setState(() {
    _search.clear();
    _group = null;
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final available = WorkspaceCatalog.visible(widget.can);
    final entries = WorkspaceCatalog.visible(
      widget.can,
      query: _search.text,
      group: _group,
    );
    final groups = WorkspaceGroup.values.where(
      (group) => available.any((entry) => entry.group == group),
    );
    final rows = <Object>[
      for (final group in WorkspaceGroup.values)
        if (entries.any((entry) => entry.group == group)) ...<Object>[
          group,
          ...entries.where((entry) => entry.group == group),
        ],
    ];
    return SafeArea(
      top: false,
      child: CustomScrollView(
        key: const PageStorageKey('services-directory'),
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        slivers: [
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
            sliver: SliverToBoxAdapter(
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 1080),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        OwnerUxStrings.allServices,
                        style: theme.textTheme.headlineSmall?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        OwnerUxStrings.directoryHelp,
                        style: theme.textTheme.bodyLarge,
                      ),
                      const SizedBox(height: 20),
                      TextField(
                        controller: _search,
                        focusNode: _searchFocus,
                        autofocus: widget.autofocus,
                        textInputAction: TextInputAction.search,
                        onChanged: (_) => setState(() {}),
                        onSubmitted: (_) => FocusScope.of(context).unfocus(),
                        decoration: InputDecoration(
                          labelText: OwnerUxStrings.searchServiceLabel,
                          hintText: OwnerUxStrings.serviceExample,
                          prefixIcon: const Icon(Icons.search_rounded),
                          suffixIcon: _search.text.isEmpty
                              ? null
                              : IconButton(
                                  tooltip: OwnerUxStrings.clearSearch,
                                  icon: const Icon(Icons.close_rounded),
                                  onPressed: () =>
                                      setState(() => _search.clear()),
                                ),
                        ),
                      ),
                      const SizedBox(height: 16),
                      Wrap(
                        spacing: 6,
                        runSpacing: 6,
                        children: [
                          AppFilterChip(
                            label: OwnerUxStrings.all,
                            selected: _group == null,
                            onTap: () => setState(() => _group = null),
                          ),
                          for (final group in groups)
                            AppFilterChip(
                              label: group.label,
                              selected: _group == group,
                              onTap: () => setState(
                                () => _group = _group == group ? null : group,
                              ),
                            ),
                        ],
                      ),
                      const SizedBox(height: 20),
                      Text(
                        OwnerUxStrings.servicesCount(entries.length),
                        style: theme.textTheme.bodyMedium,
                      ),
                      const SizedBox(height: 12),
                    ],
                  ),
                ),
              ),
            ),
          ),
          if (entries.isEmpty)
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.all(32),
                child: Column(
                  children: [
                    Icon(
                      Icons.search_off_rounded,
                      size: 48,
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                    const SizedBox(height: 16),
                    Text(
                      OwnerUxStrings.noServices,
                      style: theme.textTheme.titleMedium,
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      OwnerUxStrings.noServicesHelp,
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 12),
                    TextButton(
                      onPressed: _clear,
                      child: const Text(OwnerUxStrings.showAll),
                    ),
                  ],
                ),
              ),
            ),
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 28),
            sliver: SliverList.builder(
              itemCount: rows.length,
              itemBuilder: (context, index) {
                final row = rows[index];
                if (row is WorkspaceGroup) {
                  return Center(
                    child: Container(
                      constraints: const BoxConstraints(maxWidth: 1080),
                      alignment: AlignmentDirectional.centerStart,
                      padding: const EdgeInsets.only(top: 12, bottom: 10),
                      child: Text(
                        row.label,
                        style: theme.textTheme.titleMedium,
                      ),
                    ),
                  );
                }
                final entry = row as WorkspaceEntry;
                return Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 1080),
                    child: Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: WorkspaceTile(
                        entry: entry,
                        onTap: () {
                          FocusScope.of(context).unfocus();
                          widget.onNavigate(entry.route);
                        },
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
