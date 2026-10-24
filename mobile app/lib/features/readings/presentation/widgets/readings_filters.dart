part of '../pages/readings_list_screen.dart';

/// فلاتر القراءات (شرائح + ورقة اختيار متقدمة).

class _ReadingsFilters extends StatefulWidget {
  const _ReadingsFilters({required this.state, required this.cubit});

  final ReadingsState state;
  final ReadingsCubit cubit;

  @override
  State<_ReadingsFilters> createState() => _ReadingsFiltersState();
}

class _ReadingsFiltersState extends State<_ReadingsFilters> {
  late final TextEditingController _search =
      TextEditingController(text: widget.state.query);

  @override
  void didUpdateWidget(covariant _ReadingsFilters oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.state.query != _search.text) {
      _search.value = TextEditingValue(
        text: widget.state.query,
        selection: TextSelection.collapsed(offset: widget.state.query.length),
      );
    }
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  String? get _squareName {
    final id = widget.state.squareFilter;
    if (id == null) return null;
    for (final square in widget.state.squares) {
      if (square.id == id) return square.name;
    }
    return 'مربع #$id';
  }

  bool get _hasDates =>
      widget.state.fromDate != null || widget.state.toDate != null;

  int get _sheetFacets => (_squareName == null ? 0 : 1) + (_hasDates ? 1 : 0);

  String get _dateSummary {
    String fmt(DateTime? value) => value == null ? '…' : _date(value);
    return '${fmt(widget.state.fromDate)} ← ${fmt(widget.state.toDate)}';
  }

  /// حفظ الفلاتر الحالية باسم يكتبه القارئ (أو الاسم المقترح).
  Future<void> _savePreset(BuildContext context) async {
    final controller = TextEditingController(text: widget.cubit.suggestPresetName());
    final name = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(AppStrings.of(context).savedFiltersTitle),
        content: TextField(
          controller: controller,
          autofocus: true,
          maxLength: 40,
          decoration: const InputDecoration(labelText: 'اسم الفلتر'),
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(controller.text),
            child: const Text('حفظ'),
          ),
        ],
      ),
    );
    controller.dispose();
    if (name == null || name.trim().isEmpty) return;
    final saved = await widget.cubit.savePreset(name);
    if (!context.mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(saved ? 'حُفظ الفلتر «${name.trim()}».' : 'تعذّر حفظ الفلتر على الجهاز.'),
      ),
    );
  }

  Future<void> _openSheet() async {
    await AppFilterSheet.show<void>(
      context: context,
      builder: (_) => _ReadingsFilterSheet(state: widget.state, cubit: widget.cubit),
    );
  }

  @override
  Widget build(BuildContext context) {
    final state = widget.state;
    final cubit = widget.cubit;
    final active = <Widget>[
      if (_squareName != null)
        AppFilterChip(
          label: _squareName!,
          icon: Icons.grid_view_rounded,
          selected: true,
          onRemove: () => cubit.applyFilters(
            squareId: null,
            fromDate: state.fromDate,
            toDate: state.toDate,
          ),
        ),
      if (_hasDates)
        AppFilterChip(
          label: _dateSummary,
          icon: Icons.event_rounded,
          selected: true,
          onRemove: () => cubit.applyFilters(
            squareId: state.squareFilter,
            fromDate: null,
            toDate: null,
          ),
        ),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        const SizedBox(height: 10),
        AppFilterBar(
          controller: _search,
          hint: 'كود العداد، المشترك، أو المربع/المسار',
          activeCount: _sheetFacets,
          onOpenFilters: _openSheet,
          onChanged: cubit.setQuery,
          trailing: IconButton(
            tooltip: 'حفظ الفلاتر الحالية',
            icon: const Icon(Icons.bookmark_add_outlined),
            onPressed: () => _savePreset(context),
          ),
        ),
        const SizedBox(height: 8),
        AppFilterChips(
          children: <Widget>[
            AppFilterChip(
              label: 'الكل',
              selected: state.invoiceFilter == ReadingInvoiceFilter.all,
              onTap: () => cubit.setInvoiceFilter(ReadingInvoiceFilter.all),
            ),
            AppFilterChip(
              label: 'مفوترة',
              selected: state.invoiceFilter == ReadingInvoiceFilter.invoiced,
              onTap: () => cubit.setInvoiceFilter(ReadingInvoiceFilter.invoiced),
            ),
            AppFilterChip(
              label: 'غير مفوترة',
              selected: state.invoiceFilter == ReadingInvoiceFilter.notInvoiced,
              onTap: () => cubit.setInvoiceFilter(ReadingInvoiceFilter.notInvoiced),
            ),
          ],
        ),
        if (state.presets.isNotEmpty) ...<Widget>[
          const SizedBox(height: 2),
          AppFilterChips(
            children: <Widget>[
              for (final preset in state.presets)
                AppFilterChip(
                  label: preset.name,
                  icon: Icons.bookmark_outline_rounded,
                  selected: false,
                  onTap: () => cubit.applyPreset(preset),
                  onRemove: () => cubit.deletePreset(preset.id),
                ),
            ],
          ),
        ],
        AppActiveFilters(filters: active, onClearAll: cubit.clearAllFilters),
        if (state.isMutating ||
            (state.status == ReadingsLoadStatus.loading && state.readings.isNotEmpty))
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: LinearProgressIndicator(
              minHeight: 1.5,
              backgroundColor: Colors.transparent,
              color: Theme.of(context).colorScheme.primary.withValues(alpha: .5),
            ),
          ),
        Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 10, 16, 2),
          child: Row(
            children: <Widget>[
              Text(
                state.status == ReadingsLoadStatus.loading
                    ? 'جارٍ التحديث…'
                    : '${state.readings.length} قراءة',
                style: Theme.of(context).textTheme.labelSmall,
              ),
              const Spacer(),
              if (state.isSelecting)
                Text(
                  'وضع التحديد',
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                        color: Theme.of(context).colorScheme.primary,
                      ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

class _ReadingsFilterSheet extends StatefulWidget {
  const _ReadingsFilterSheet({required this.state, required this.cubit});

  final ReadingsState state;
  final ReadingsCubit cubit;

  @override
  State<_ReadingsFilterSheet> createState() => _ReadingsFilterSheetState();
}

class _ReadingsFilterSheetState extends State<_ReadingsFilterSheet> {
  late int? _square = widget.state.squareFilter;
  late DateTime? _from = widget.state.fromDate;
  late DateTime? _to = widget.state.toDate;

  Future<void> _pick({required bool from}) async {
    final now = DateTime.now();
    final tomorrow = now.add(const Duration(days: 1));
    final firstDate = from ? DateTime(2000) : (_from ?? DateTime(2000));
    final lastDate = from ? (_to ?? tomorrow) : tomorrow;
    final requested = (from ? _from : _to) ?? now;
    final initial = requested.isBefore(firstDate)
        ? firstDate
        : requested.isAfter(lastDate)
            ? lastDate
            : requested;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: firstDate,
      lastDate: lastDate,
      helpText: from ? 'من تاريخ' : 'إلى تاريخ',
    );
    if (picked == null) return;
    setState(() {
      if (from) {
        _from = picked;
      } else {
        _to = picked;
      }
    });
  }

  void _apply() {
    widget.cubit.applyFilters(squareId: _square, fromDate: _from, toDate: _to);
    Navigator.of(context).maybePop();
  }

  void _reset() {
    widget.cubit.setInvoiceFilter(ReadingInvoiceFilter.all);
    setState(() {
      _square = null;
      _from = null;
      _to = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final state = widget.state;
    return AppFilterSheet(
      title: 'تصفية سجل القراءات',
      resultCountLabel: state.status == ReadingsLoadStatus.loading
          ? 'جارٍ تحديث النتائج…'
          : '${state.readings.length} قراءة مطابقة',
      onApply: _apply,
      onReset: _reset,
      resetEnabled: _square != null ||
          _from != null ||
          _to != null ||
          state.invoiceFilter != ReadingInvoiceFilter.all,
      sections: <Widget>[
        AppFilterSection(
          title: 'حالة الفوترة',
          child: AppSegmentedFilter<ReadingInvoiceFilter>(
            value: state.invoiceFilter,
            onChanged: widget.cubit.setInvoiceFilter,
            options: const <({ReadingInvoiceFilter value, String label, IconData? icon})>[
              (value: ReadingInvoiceFilter.all, label: 'الكل', icon: null),
              (value: ReadingInvoiceFilter.invoiced, label: 'مفوترة', icon: null),
              (value: ReadingInvoiceFilter.notInvoiced, label: 'غير مفوترة', icon: null),
            ],
          ),
        ),
        AppFilterSection(
          title: 'المربع السكني',
          subtitle: 'اختر مربعاً واحداً أو أبقِ البحث في كل المربعات',
          child: Container(
            constraints: const BoxConstraints(maxHeight: 216),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: theme.colorScheme.outlineVariant),
            ),
            child: ListView(
              padding: const EdgeInsets.all(6),
              shrinkWrap: true,
              children: <Widget>[
                AppFilterOptionTile(
                  label: 'كل المربعات',
                  icon: Icons.apps_rounded,
                  selected: _square == null,
                  onTap: () => setState(() => _square = null),
                ),
                const SizedBox(height: 6),
                for (final square in state.squares) ...<Widget>[
                  AppFilterOptionTile(
                    label: square.name,
                    icon: Icons.grid_view_rounded,
                    selected: _square == square.id,
                    onTap: () => setState(() => _square = square.id),
                  ),
                  const SizedBox(height: 6),
                ],
              ],
            ),
          ),
        ),
        AppFilterSection(
          title: 'الفترة',
          subtitle: 'حدّد نطاق القراءات بالتاريخ',
          child: AppFilterDateRange(
            fromLabel: _from == null ? 'بدون حد' : _date(_from!),
            toLabel: _to == null ? 'بدون حد' : _date(_to!),
            onPickFrom: () => _pick(from: true),
            onPickTo: () => _pick(from: false),
            onClear: _from == null && _to == null
                ? null
                : () => setState(() {
                      _from = null;
                      _to = null;
                    }),
          ),
        ),
        const SizedBox(height: 20),
      ],
    );
  }
}

