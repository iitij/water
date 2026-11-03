import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../core/auth/session_service.dart';
import '../../../core/permissions/permission_service.dart';
import '../../../core/router/route_names.dart';
import '../../../core/ui/app_filters.dart';
import '../../../core/ui/app_tiles.dart';
import '../../../core/ui/app_tokens.dart';
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
    final visibleGroups = WorkspaceGroup.values
        .where((group) => entries.any((entry) => entry.group == group))
        .toList();
    return SafeArea(
      top: false,
      child: CustomScrollView(
        key: const PageStorageKey('services-directory'),
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        slivers: [
          SliverPadding(
            padding: const EdgeInsetsDirectional.fromSTEB(20, 22, 20, 0),
            sliver: SliverToBoxAdapter(
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 1080),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Container(
                        padding: const EdgeInsetsDirectional.fromSTEB(
                          16,
                          14,
                          16,
                          14,
                        ),
                        decoration: BoxDecoration(
                          color: theme.colorScheme.primary.withValues(
                            alpha: .08,
                          ),
                          border: Border.all(
                            color: theme.colorScheme.primary.withValues(
                              alpha: .22,
                            ),
                          ),
                          borderRadius: BorderRadius.circular(AppRadius.lg),
                        ),
                        child: Row(
                          children: [
                            Container(
                              width: 42,
                              height: 42,
                              decoration: BoxDecoration(
                                color: theme.colorScheme.primary.withValues(
                                  alpha: .16,
                                ),
                                borderRadius: BorderRadius.circular(
                                  AppRadius.md,
                                ),
                              ),
                              child: Icon(
                                Icons.apps_rounded,
                                color: theme.colorScheme.primary,
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Text(
                                'اختر خدمة أو ابحث عنها بالاسم.',
                                style: theme.textTheme.titleMedium?.copyWith(
                                  fontWeight: FontWeight.w600,
                                  color: theme.colorScheme.onSurface,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 16),
                      TextField(
                        controller: _search,
                        focusNode: _searchFocus,
                        autofocus: widget.autofocus,
                        textInputAction: TextInputAction.search,
                        onChanged: (_) => setState(() {}),
                        onSubmitted: (_) => FocusScope.of(context).unfocus(),
                        decoration: InputDecoration(
                          hintText: 'ابحث عن خدمة',
                          prefixIcon: const Icon(Icons.search_rounded),
                          suffixIcon: _search.text.isEmpty
                              ? null
                              : IconButton(
                                  tooltip: 'مسح البحث',
                                  icon: const Icon(Icons.close_rounded),
                                  onPressed: () =>
                                      setState(() => _search.clear()),
                                ),
                        ),
                      ),
                      const SizedBox(height: 14),
                      AppFilterChips(
                        padding: EdgeInsets.zero,
                        spacing: 8,
                        children: [
                          AppFilterChip(
                            label: 'الكل',
                            count: available.length,
                            selected: _group == null,
                            onTap: () => setState(() => _group = null),
                          ),
                          for (final group in groups)
                            AppFilterChip(
                              label: group.label,
                              count: available
                                  .where((entry) => entry.group == group)
                                  .length,
                              selected: _group == group,
                              onTap: () => setState(
                                () => _group = _group == group ? null : group,
                              ),
                            ),
                        ],
                      ),
                      const SizedBox(height: 22),
                      Row(
                        children: [
                          Flexible(
                            child: Text(
                              '${entries.length} خدمة متاحة',
                              style: theme.textTheme.labelLarge?.copyWith(
                                color: theme.colorScheme.onSurfaceVariant,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Divider(
                              color: theme.colorScheme.outlineVariant,
                            ),
                          ),
                        ],
                      ),
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
                      'لم نجد خدمة بهذا الاسم',
                      style: theme.textTheme.titleMedium,
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      'جرّب اسماً آخر أو اختر قسماً مختلفاً.',
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 12),
                    TextButton(
                      onPressed: _clear,
                      child: const Text('عرض كل الخدمات'),
                    ),
                  ],
                ),
              ),
            ),
          SliverPadding(
            padding: const EdgeInsetsDirectional.fromSTEB(20, 0, 20, 28),
            sliver: SliverList.builder(
              itemCount: visibleGroups.length,
              itemBuilder: (context, index) {
                final group = visibleGroups[index];
                final groupEntries = entries
                    .where((entry) => entry.group == group)
                    .toList();
                return Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 1080),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Padding(
                          padding: const EdgeInsetsDirectional.fromSTEB(
                            4,
                            22,
                            4,
                            10,
                          ),
                          child: Row(
                            children: [
                              Container(
                                width: 4,
                                height: 18,
                                decoration: BoxDecoration(
                                  color: theme.colorScheme.primary,
                                  borderRadius: BorderRadius.circular(
                                    AppRadius.pill,
                                  ),
                                ),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: Text(
                                  group.label,
                                  style: theme.textTheme.titleSmall?.copyWith(
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                        AppModuleGrid(
                          children: [
                            for (final entry in groupEntries)
                              WorkspaceTile(
                                entry: entry,
                                onTap: () {
                                  FocusScope.of(context).unfocus();
                                  widget.onNavigate(entry.route);
                                },
                              ),
                          ],
                        ),
                      ],
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
