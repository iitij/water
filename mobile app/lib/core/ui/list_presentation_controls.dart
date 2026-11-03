import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../l10n/list_options_strings.dart';
import '../utils/compound_search_parser.dart';

@immutable
class ListFilterOptions {
  const ListFilterOptions({
    this.hasPath = false,
    this.hasCreatedAt = false,
    this.squares = const [],
    this.search = true,
  });
  final bool hasPath, hasCreatedAt, search;
  final List<String> squares;
}

@immutable
class ListPresentationSettings {
  const ListPresentationSettings({
    this.sortBy = 'id',
    this.descending = true,
    this.pageSize = 25,
    this.compact = false,
    this.hiddenFields = const {},
    this.query = '',
    this.pathNumber,
    this.squareName,
    this.createdFrom,
    this.createdTo,
  });
  final String sortBy;
  final bool descending, compact;
  final int pageSize;
  final Set<String> hiddenFields;
  final String query;
  final int? pathNumber;
  final String? squareName;
  final DateTime? createdFrom, createdTo;
  int get filterCount =>
      (query.trim().isEmpty ? 0 : 1) +
      (pathNumber == null ? 0 : 1) +
      (squareName == null ? 0 : 1) +
      (createdFrom == null && createdTo == null ? 0 : 1);
  bool isVisible(String field) => !hiddenFields.contains(field);
  ListPresentationSettings copyWith({
    String? sortBy,
    bool? descending,
    int? pageSize,
    bool? compact,
    Set<String>? hiddenFields,
    String? query,
    int? pathNumber,
    String? squareName,
    DateTime? createdFrom,
    DateTime? createdTo,
    bool clearFilters = false,
    bool clearPath = false,
    bool clearSquare = false,
    bool clearCreatedFrom = false,
    bool clearCreatedTo = false,
  }) => ListPresentationSettings(
    sortBy: sortBy ?? this.sortBy,
    descending: descending ?? this.descending,
    pageSize: pageSize ?? this.pageSize,
    compact: compact ?? this.compact,
    hiddenFields: hiddenFields ?? this.hiddenFields,
    query: clearFilters ? '' : query ?? this.query,
    pathNumber: clearFilters || clearPath
        ? null
        : pathNumber ?? this.pathNumber,
    squareName: clearFilters || clearSquare
        ? null
        : squareName ?? this.squareName,
    createdFrom: clearFilters || clearCreatedFrom
        ? null
        : createdFrom ?? this.createdFrom,
    createdTo: clearFilters || clearCreatedTo
        ? null
        : createdTo ?? this.createdTo,
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
    this.filterOptions,
  });
  final ListPresentationSettings settings;
  final Map<String, String> sortOptions, fields;
  final ValueChanged<ListPresentationSettings> onChanged;
  final List<int> pageSizes;
  final ListFilterOptions? filterOptions;

  @override
  Widget build(BuildContext context) => IconButton(
    tooltip: ListOptionsStrings.title,
    icon: Badge(
      isLabelVisible: settings.filterCount > 0,
      label: Text('${settings.filterCount}'),
      child: const Icon(Icons.tune_rounded),
    ),
    onPressed: () async {
      var draft = settings;
      var resetKey = 0;
      String? pathError;
      String? periodError;
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
                key: ValueKey(resetKey),
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    ListOptionsStrings.title,
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  if (filterOptions case final options?) ...[
                    const SizedBox(height: 12),
                    Text(
                      ListOptionsStrings.filters,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    if (options.search)
                      TextFormField(
                        initialValue: draft.query,
                        decoration: const InputDecoration(
                          labelText: ListOptionsStrings.search,
                          hintText: ListOptionsStrings.searchHint,
                          prefixIcon: Icon(Icons.search),
                        ),
                        onChanged: (value) =>
                            draft = draft.copyWith(query: value),
                      ),
                    if (options.hasPath)
                      TextFormField(
                        initialValue: draft.pathNumber?.toString() ?? '',
                        keyboardType: TextInputType.number,
                        decoration: InputDecoration(
                          labelText: ListOptionsStrings.path,
                          hintText: ListOptionsStrings.allPaths,
                          errorText: pathError,
                        ),
                        onChanged: (value) => setSheetState(() {
                          final text = CompoundSearchQuery.normalizeDigits(
                            value.trim(),
                          );
                          final number = int.tryParse(text);
                          pathError =
                              text.isNotEmpty && (number == null || number < 0)
                              ? ListOptionsStrings.invalidPath
                              : null;
                          draft = draft.copyWith(
                            pathNumber: number,
                            clearPath: text.isEmpty,
                          );
                        }),
                      ),
                    if (options.squares.isNotEmpty)
                      DropdownButtonFormField<String>(
                        initialValue: options.squares.contains(draft.squareName)
                            ? draft.squareName
                            : null,
                        isExpanded: true,
                        decoration: const InputDecoration(
                          labelText: ListOptionsStrings.square,
                        ),
                        items: [
                          const DropdownMenuItem(
                            value: '',
                            child: Text(ListOptionsStrings.allSquares),
                          ),
                          for (final name in options.squares)
                            DropdownMenuItem(
                              value: name,
                              child: Text(
                                name,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                        ],
                        onChanged: (value) => setSheetState(
                          () => draft = draft.copyWith(
                            squareName: value,
                            clearSquare: value == null || value.isEmpty,
                          ),
                        ),
                      ),
                    if (options.hasCreatedAt) ...[
                      for (final isStart in [true, false])
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          title: Text(
                            isStart
                                ? ListOptionsStrings.addedFrom
                                : ListOptionsStrings.addedTo,
                          ),
                          subtitle: Text(
                            _dateLabel(
                              isStart ? draft.createdFrom : draft.createdTo,
                            ),
                          ),
                          trailing: IconButton(
                            icon: const Icon(Icons.clear),
                            onPressed: () => setSheetState(() {
                              draft = draft.copyWith(
                                clearCreatedFrom: isStart,
                                clearCreatedTo: !isStart,
                              );
                              periodError = null;
                            }),
                          ),
                          onTap: () async {
                            final selected = isStart
                                ? draft.createdFrom
                                : draft.createdTo;
                            final value = await showDatePicker(
                              context: context,
                              initialDate: selected ?? DateTime.now(),
                              firstDate: DateTime(1900),
                              lastDate: DateTime(2200),
                            );
                            if (value == null || !context.mounted) return;
                            setSheetState(() {
                              draft = isStart
                                  ? draft.copyWith(createdFrom: value)
                                  : draft.copyWith(createdTo: value);
                              periodError =
                                  draft.createdFrom != null &&
                                      draft.createdTo != null &&
                                      draft.createdFrom!.isAfter(
                                        draft.createdTo!,
                                      )
                                  ? ListOptionsStrings.invalidPeriod
                                  : null;
                            });
                          },
                        ),
                      if (periodError != null)
                        Text(
                          periodError!,
                          style: TextStyle(
                            color: Theme.of(context).colorScheme.error,
                          ),
                        ),
                    ],
                    TextButton(
                      onPressed: () => Navigator.pop(
                        context,
                        draft.copyWith(clearFilters: true),
                      ),
                      child: const Text(ListOptionsStrings.clearFilters),
                    ),
                    const Divider(),
                  ],
                  if (sortOptions.isNotEmpty) ...[
                    DropdownButtonFormField<String>(
                      key: ValueKey('list-sort-${draft.sortBy}'),
                      isExpanded: true,
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
                            child: Text(
                              entry.value,
                              overflow: TextOverflow.ellipsis,
                            ),
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
                    onPressed: pathError != null || periodError != null
                        ? null
                        : () => Navigator.pop(context, draft),
                    child: const Text(ListOptionsStrings.apply),
                  ),
                  TextButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text(ListOptionsStrings.cancel),
                  ),
                  TextButton(
                    onPressed: () => setSheetState(() {
                      resetKey++;
                      pathError = null;
                      periodError = null;
                      draft = ListPresentationSettings(
                        sortBy: sortOptions.isEmpty
                            ? settings.sortBy
                            : sortOptions.keys.first,
                      );
                    }),
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

  static String _dateLabel(DateTime? date) => date == null
      ? ListOptionsStrings.anyDate
      : '${date.year}/${date.month.toString().padLeft(2, '0')}/${date.day.toString().padLeft(2, '0')}';
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
    this.pathNumbers,
    this.onVisibleItemsChanged,
  }) : sliversBuilder = null;

  /// Scroll controls with nested profile lists to keep short viewports usable.
  const ListPresentation.slivers({
    super.key,
    required this.items,
    required this.sortValues,
    required this.sliversBuilder,
    this.fields = const {},
    this.initialSettings,
    this.pathNumbers,
    this.onVisibleItemsChanged,
  }) : builder = null;

  final List<Widget> Function(BuildContext, List<T>, ListPresentationSettings)?
  sliversBuilder;
  final List<T> items;
  final Map<
    String,
    ({String label, Comparable<dynamic>? Function(T item) value})
  >
  sortValues;
  final Map<String, String> fields;
  final ListPresentationSettings? initialSettings;
  final List<int> Function(T item)? pathNumbers;
  final ValueChanged<List<T>>? onVisibleItemsChanged;
  final Widget Function(
    BuildContext context,
    List<T> items,
    ListPresentationSettings settings,
  )?
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
  List<T> _lastVisible = [];

  @override
  void didUpdateWidget(covariant ListPresentation<T> oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.items, widget.items)) _page = 1;
  }

  @override
  Widget build(BuildContext context) {
    final ordered = widget.items.where(_matchesFilters).toList();
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
              ? positions[left]!.compareTo(positions[right]!)
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
    if (widget.onVisibleItemsChanged != null &&
        !listEquals(_lastVisible, visible)) {
      _lastVisible = List.unmodifiable(visible);
      final snapshot = _lastVisible;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && identical(_lastVisible, snapshot)) {
          widget.onVisibleItemsChanged?.call(snapshot);
        }
      });
    }
    final header = Column(
      mainAxisSize: MainAxisSize.min,
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
                filterOptions: ListFilterOptions(
                  hasPath: widget.sortValues.containsKey('path_number'),
                  hasCreatedAt: widget.sortValues.containsKey('created_at'),
                  squares: {
                    for (final item in widget.items)
                      if (widget.sortValues['square_name']
                              ?.value(item)
                              ?.toString()
                          case final String name)
                        if (name.isNotEmpty) name,
                  }.toList()..sort(),
                ),
                sortOptions: Map.fromEntries(
                  widget.sortValues.entries
                      .where((entry) => entry.key != 'square_id')
                      .map((entry) => MapEntry(entry.key, entry.value.label)),
                ),
                onChanged: (settings) => setState(() {
                  _settings = settings;
                  _page = 1;
                }),
              ),
            ],
          ),
        ),
        if (_settings.filterCount > 0)
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: TextButton.icon(
              icon: const Icon(Icons.filter_alt_off_outlined),
              label: Text(
                '${ListOptionsStrings.filterCount(_settings.filterCount)} · ${ListOptionsStrings.clearFilters}',
              ),
              onPressed: () => setState(() {
                _settings = _settings.copyWith(clearFilters: true);
                _page = 1;
              }),
            ),
          ),
        if (_settings.filterCount > 0)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Text(
              ListOptionsStrings.sourceTotals,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
      ],
    );
    final footer = ordered.isEmpty
        ? const SizedBox.shrink()
        : SafeArea(
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
          );
    if (widget.sliversBuilder case final sliversBuilder?) {
      return CustomScrollView(
        slivers: [
          SliverToBoxAdapter(child: header),
          ...sliversBuilder(context, visible, _settings),
          SliverToBoxAdapter(child: footer),
        ],
      );
    }
    return Column(
      children: [
        header,
        Expanded(
          child: Theme(
            data: Theme.of(context).copyWith(
              visualDensity: _settings.compact
                  ? VisualDensity.compact
                  : VisualDensity.standard,
            ),
            child: widget.builder!(context, visible, _settings),
          ),
        ),
        footer,
      ],
    );
  }

  bool _matchesFilters(T item) {
    final paths =
        widget.pathNumbers?.call(item) ??
        [
          if (widget.sortValues['path_number']?.value(item) case final int path)
            path,
        ];
    if (_settings.pathNumber != null && !paths.contains(_settings.pathNumber)) {
      return false;
    }
    if (_settings.squareName != null &&
        widget.sortValues['square_name']?.value(item)?.toString() !=
            _settings.squareName) {
      return false;
    }
    if (_settings.createdFrom != null || _settings.createdTo != null) {
      final value = widget.sortValues['created_at']?.value(item);
      final date = value is DateTime
          ? value
          : DateTime.tryParse(value?.toString() ?? '');
      if (date == null) return false;
      final local = date.toLocal();
      final day = DateTime(local.year, local.month, local.day);
      if (_settings.createdFrom != null &&
          day.isBefore(_settings.createdFrom!)) {
        return false;
      }
      if (_settings.createdTo != null && day.isAfter(_settings.createdTo!)) {
        return false;
      }
    }
    final query = CompoundSearchQuery.parse(_settings.query);
    if (query.isEmpty) return true;
    if (query.hasSplitParams) {
      return paths.contains(query.pathNumber) &&
          widget.sortValues['square_id']?.value(item) == query.squareId;
    }
    final normalized = query.rawQuery.toLowerCase();
    return paths.any((path) => path.toString() == normalized) ||
        widget.sortValues.values.any(
          (field) => CompoundSearchQuery.normalizeDigits(
            field.value(item)?.toString() ?? '',
          ).toLowerCase().contains(normalized),
        );
  }
}
