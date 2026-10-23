import 'package:flutter/material.dart';
import '../../core/fouad/fouad_module.dart';
import '../../features/subscribers/domain/entities/subscriber.dart';
import '../../features/subscribers/domain/repositories/subscribers_repository.dart';

/// مكون اختيار المشترك/المستأجر وعداده القابل للبحث — يسلّمه فؤاد لأنور وأكرم (الخطة 5.2/ثالثاً).
/// يعرض المشتركين مع بحث فوري، وعند اختيار مشترك يعرض عداداته (قائمة اختيار ثانية اختيارية).
class SubscriberMeterSelectorWidget extends StatefulWidget {
  const SubscriberMeterSelectorWidget({
    super.key,
    this.repository,
    required this.onSelected,
    this.showMeterPicker = true,
  });

  final SubscribersRepository? repository;
  final void Function(Subscriber subscriber, MeterBalance? meter) onSelected;
  final bool showMeterPicker;

  @override
  State<SubscriberMeterSelectorWidget> createState() => _SubscriberMeterSelectorWidgetState();
}

class _SubscriberMeterSelectorWidgetState extends State<SubscriberMeterSelectorWidget> {
  List<Subscriber> _results = [];
  List<MeterBalance> _meters = [];
  Subscriber? _selected;
  bool _loading = false;

  SubscribersRepository get _repo =>
      widget.repository ?? FouadModule.deps.subscribers;

  @override
  void initState() {
    super.initState();
    _search('');
  }

  Future<void> _search(String q) async {
    setState(() => _loading = true);
    try {
      final results = await _repo.getSubscribers(query: q);
      if (mounted) {
        setState(() {
          _results = results;
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _pick(Subscriber s) async {
    setState(() {
      _selected = s;
      _meters = [];
    });
    if (widget.showMeterPicker) {
      try {
        final detail = await _repo.getSubscriber360(s.id);
        if (mounted) setState(() => _meters = detail.summary.meterBalances);
      } catch (_) {}
    }
    widget.onSelected(s, null);
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          decoration: const InputDecoration(
            labelText: 'بحث عن مشترك (الاسم / الهاتف / كود العداد)',
            prefixIcon: Icon(Icons.search),
            border: OutlineInputBorder(),
          ),
          onChanged: _search,
        ),
        if (_loading) const LinearProgressIndicator(),
        Flexible(
          child: ListView.builder(
            shrinkWrap: true,
            itemCount: _results.length,
            itemBuilder: (_, i) {
              final s = _results[i];
              return ListTile(
                title: Text(s.name),
                subtitle: Text('${s.phone ?? ''} • ${s.squareName ?? ''} • ${s.meterNumber ?? ''}'),
                selected: _selected?.id == s.id,
                onTap: () => _pick(s),
              );
            },
          ),
        ),
        if (_selected != null && _meters.isNotEmpty) ...[
          const Divider(),
          const Text('اختر العداد:', style: TextStyle(fontWeight: FontWeight.bold)),
          for (final m in _meters)
            ListTile(
              title: Text(m.title),
              subtitle: Text('دائن: ${m.credit} • مدين: ${m.debit}'),
              onTap: () => widget.onSelected(_selected!, m),
            ),
        ],
      ],
    );
  }
}

/// فتح منتقي المشترك/العداد كنافذة سفلية جاهزة للاستخدام من وحدات الزملاء.
Future<({Subscriber subscriber, MeterBalance? meter})?> showSubscriberMeterPicker(
  BuildContext context, {
  SubscribersRepository? repository,
}) {
  ({Subscriber subscriber, MeterBalance? meter})? result;
  return showModalBottomSheet(
    context: context,
    isScrollControlled: true,
    builder: (sheetCtx) => Padding(
      padding: const EdgeInsets.all(16),
      child: SubscriberMeterSelectorWidget(
        repository: repository,
        onSelected: (s, m) {
          result = (subscriber: s, meter: m);
          if (m != null) Navigator.pop(sheetCtx, result);
        },
      ),
    ),
  ).then((_) => result);
}
