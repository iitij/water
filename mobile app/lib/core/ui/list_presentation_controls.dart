import 'package:flutter/material.dart';

import '../l10n/list_options_strings.dart';

@immutable
class ListPresentationSettings {
  const ListPresentationSettings({
    this.sortBy = 'id',
    this.descending = true,
    this.pageSize = 25,
    this.compact = false,
    this.hiddenFields = const {},
  });
  final String sortBy;
  final bool descending, compact;
  final int pageSize;
  final Set<String> hiddenFields;
  bool isVisible(String field) => !hiddenFields.contains(field);
  ListPresentationSettings copyWith({
    String? sortBy,
    bool? descending,
    int? pageSize,
    bool? compact,
    Set<String>? hiddenFields,
  }) => ListPresentationSettings(
    sortBy: sortBy ?? this.sortBy,
    descending: descending ?? this.descending,
    pageSize: pageSize ?? this.pageSize,
    compact: compact ?? this.compact,
    hiddenFields: hiddenFields ?? this.hiddenFields,
  );
}

class ListPresentationControls extends StatelessWidget {
  const ListPresentationControls({
    super.key,
    required this.settings,
    required this.sortOptions,
    required this.onChanged,
    this.fields = const {},
    this.pageSizes = const [10, 25, 50, 100],
  });
  final ListPresentationSettings settings;
  final Map<String, String> sortOptions, fields;
  final ValueChanged<ListPresentationSettings> onChanged;
  final List<int> pageSizes;

  @override
  Widget build(BuildContext context) => IconButton(
    tooltip: ListOptionsStrings.title,
    icon: const Icon(Icons.tune_rounded),
    onPressed: () async {
      var draft = settings;
      final result = await showModalBottomSheet<ListPresentationSettings>(
        context: context,
        isScrollControlled: true,
        useSafeArea: true,
        showDragHandle: true,
        builder: (context) => StatefulBuilder(
          builder: (context, setSheetState) => ConstrainedBox(
            constraints: BoxConstraints(
              maxHeight: MediaQuery.sizeOf(context).height * .85,
            ),
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    ListOptionsStrings.title,
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  if (sortOptions.isNotEmpty) ...[
                    DropdownButtonFormField<String>(
                      key: ValueKey('list-sort-${draft.sortBy}'),
                      initialValue: sortOptions.containsKey(draft.sortBy)
                          ? draft.sortBy
                          : sortOptions.keys.first,
                      decoration: const InputDecoration(
                        labelText: ListOptionsStrings.sortBy,
                      ),
                      items: [
                        for (final entry in sortOptions.entries)
                          DropdownMenuItem(
                            value: entry.key,
                            child: Text(entry.value),
                          ),
                      ],
                      onChanged: (value) => setSheetState(
                        () => draft = draft.copyWith(sortBy: value),
                      ),
                    ),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      title: const Text(ListOptionsStrings.descending),
                      value: draft.descending,
                      onChanged: (value) => setSheetState(
                        () => draft = draft.copyWith(descending: value),
                      ),
                    ),
                  ],
                  DropdownButtonFormField<int>(
                    key: ValueKey('list-page-size-${draft.pageSize}'),
                    initialValue: draft.pageSize,
                    decoration: const InputDecoration(
                      labelText: ListOptionsStrings.pageSize,
                    ),
                    items: [
                      for (final size in {
                        ...pageSizes,
                        draft.pageSize,
                      }.toList()..sort())
                        DropdownMenuItem(value: size, child: Text('$size')),
                    ],
                    onChanged: (value) => setSheetState(
                      () => draft = draft.copyWith(pageSize: value),
                    ),
                  ),
                  SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    title: const Text(ListOptionsStrings.compact),
                    value: draft.compact,
                    onChanged: (value) => setSheetState(
                      () => draft = draft.copyWith(compact: value),
                    ),
                  ),
                  if (fields.isNotEmpty) ...[
                    const Divider(),
                    Text(
                      ListOptionsStrings.visibleFields,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    for (final entry in fields.entries)
                      CheckboxListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(entry.value),
                        value: draft.isVisible(entry.key),
                        onChanged: (value) => setSheetState(() {
                          final hidden = {...draft.hiddenFields};
                          if (value == true) {
                            hidden.remove(entry.key);
                          } else {
                            hidden.add(entry.key);
                          }
                          draft = draft.copyWith(hiddenFields: hidden);
                        }),
                      ),
                  ],
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: () => Navigator.pop(context, draft),
                    child: const Text(ListOptionsStrings.apply),
                  ),
                  TextButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text(ListOptionsStrings.cancel),
                  ),
                  TextButton(
                    onPressed: () => setSheetState(
                      () => draft = ListPresentationSettings(
                        sortBy: sortOptions.isEmpty
                            ? settings.sortBy
                            : sortOptions.keys.first,
                      ),
                    ),
                    child: const Text(ListOptionsStrings.reset),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
      if (result != null && context.mounted) onChanged(result);
    },
  );
}

/// Local paging only after the repository has collected every server page.
/// This never sorts a partial server page or replaces authorization filtering.
class ListPresentation<T> extends StatefulWidget {
  const ListPresentation({
    super.key,
    required this.items,
    required this.sortValues,
    required this.builder,
    this.fields = const {},
    this.initialSettings,
  });
  final List<T> items;
  final Map<
    String,
    ({String label, Comparable<dynamic>? Function(T item) value})
  >
  sortValues;
  final Map<String, String> fields;
  final ListPresentationSettings? initialSettings;
  final Widget Function(
    BuildContext context,
    List<T> items,
    ListPresentationSettings settings,
  )
  builder;
  @override
  State<ListPresentation<T>> createState() => _ListPresentationState<T>();
}

class _ListPresentationState<T> extends State<ListPresentation<T>> {
  late ListPresentationSettings _settings =
      widget.initialSettings ??
      ListPresentationSettings(
        sortBy: widget.sortValues.keys.first,
        descending: false,
      );
  int _page = 1;
  @override
  Widget build(BuildContext context) {
    final ordered = [...widget.items];
    final accessor = widget.sortValues[_settings.sortBy]?.value;
    if (accessor != null) {
      // Stable ties retain server ordering, including its deterministic id tie.
      final positions = <T, int>{
        for (var i = 0; i < ordered.length; i++) ordered[i]: i,
      };
      ordered.sort((left, right) {
        final a = accessor(left), b = accessor(right);
        if (a == null || b == null) {
          return a == b
              ? 0
              : a == null
              ? 1
              : -1;
        }
        final comparison = a.compareTo(b);
        if (comparison == 0) {
          return positions[left]!.compareTo(positions[right]!);
        }
        return _settings.descending ? -comparison : comparison;
      });
    }
    final size = _settings.pageSize < 1 ? 25 : _settings.pageSize;
    final lastPage = ordered.isEmpty ? 1 : (ordered.length / size).ceil();
    if (_page > lastPage) _page = lastPage;
    final start = (_page - 1) * size;
    final end = (start + size).clamp(0, ordered.length);
    final visible = ordered.sublist(start.clamp(0, ordered.length), end);
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  '${ListOptionsStrings.allRecords}: ${ordered.length}',
                ),
              ),
              ListPresentationControls(
                settings: _settings,
                fields: widget.fields,
                sortOptions: widget.sortValues.map(
                  (key, value) => MapEntry(key, value.label),
                ),
                onChanged: (settings) => setState(() {
                  _settings = settings;
                  _page = 1;
                }),
              ),
            ],
          ),
        ),
        Expanded(
          child: Theme(
            data: Theme.of(context).copyWith(
              visualDensity: _settings.compact
                  ? VisualDensity.compact
                  : VisualDensity.standard,
            ),
            child: widget.builder(context, visible, _settings),
          ),
        ),
        if (ordered.isNotEmpty)
          SafeArea(
            top: false,
            child: Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 10,
              children: [
                Text('${ListOptionsStrings.page} $_page / $lastPage'),
                TextButton(
                  onPressed: _page > 1 ? () => setState(() => _page--) : null,
                  child: const Text(ListOptionsStrings.previous),
                ),
                TextButton(
                  onPressed: _page < lastPage
                      ? () => setState(() => _page++)
                      : null,
                  child: const Text(ListOptionsStrings.next),
                ),
              ],
            ),
          ),
      ],
    );
  }
}
