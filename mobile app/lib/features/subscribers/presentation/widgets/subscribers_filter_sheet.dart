part of '../pages/subscribers_screen.dart';

/// تصفية المشتركين — مسودة واحدة تُطبَّق مرة واحدة من تذييل الورقة.
class _SubscribersFilterSheet extends StatefulWidget {
  const _SubscribersFilterSheet({required this.state, required this.cubit});

  final SubscribersState state;
  final SubscribersCubit cubit;

  @override
  State<_SubscribersFilterSheet> createState() =>
      _SubscribersFilterSheetState();
}

class _SubscribersFilterSheetState extends State<_SubscribersFilterSheet> {
  late int? _square = widget.state.squareId;
  late SubscriberStatus? _status = widget.state.status;

  void _apply() {
    widget.cubit.filterSquare(_square);
    widget.cubit.filterStatus(_status);
    Navigator.of(context).maybePop();
  }

  void _reset() => setState(() {
        _square = null;
        _status = null;
      });

  @override
  Widget build(BuildContext context) {
    return AppFilterSheet(
      title: 'تصفية المشتركين',
      resultCountLabel: '${widget.state.subscribers.length} مشترك في القائمة',
      onApply: _apply,
      onReset: _reset,
      resetEnabled: _square != null || _status != null,
      sections: <Widget>[
        AppFilterSection(
          title: 'حالة الاشتراك',
          child: Column(
            children: <Widget>[
              AppFilterOptionTile(
                label: 'كل الحالات',
                icon: Icons.apps_rounded,
                selected: _status == null,
                onTap: () => setState(() => _status = null),
              ),
              const SizedBox(height: 6),
              for (final status in SubscriberStatus.values) ...<Widget>[
                AppFilterOptionTile(
                  label: status.label,
                  icon: status == SubscriberStatus.active
                      ? Icons.check_circle_outline_rounded
                      : status == SubscriberStatus.suspended
                          ? Icons.pause_circle_outline_rounded
                          : Icons.remove_circle_outline_rounded,
                  selected: _status == status,
                  onTap: () => setState(() => _status = status),
                ),
                const SizedBox(height: 6),
              ],
            ],
          ),
        ),
        AppFilterSection(
          title: 'المربع السكني',
          subtitle: 'اختر مربعاً واحداً لعرض مشتركيه فقط',
          child: AppFilterDropdown<int?>(
            label: 'المربع',
            icon: Icons.grid_view_rounded,
            hint: 'كل المربعات',
            value: widget.state.squares.any((s) => s.id == _square)
                ? _square
                : null,
            items: <DropdownMenuItem<int?>>[
              const DropdownMenuItem<int?>(
                value: null,
                child: Text('كل المربعات'),
              ),
              ...widget.state.squares.map(
                (square) => DropdownMenuItem<int?>(
                  value: square.id,
                  child: Text(square.name, overflow: TextOverflow.ellipsis),
                ),
              ),
            ],
            onChanged: (value) => setState(() => _square = value),
          ),
        ),
        const SizedBox(height: 16),
      ],
    );
  }
}
