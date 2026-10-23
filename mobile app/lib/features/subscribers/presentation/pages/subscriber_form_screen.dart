import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../squares/domain/entities/square.dart';
import '../../domain/entities/subscriber.dart';
import '../../domain/repositories/subscribers_repository.dart';

/// SCR-06 — نموذج إضافة/تعديل مشترك (هاتف يمني +967، تاريخ بدء الاشتراك، مربع، حالة).
class SubscriberFormScreen extends StatefulWidget {
  const SubscriberFormScreen({super.key, this.existing, this.initialSquareId, this.repository});
  final Subscriber? existing;
  final int? initialSquareId;
  final SubscribersRepository? repository;

  @override
  State<SubscriberFormScreen> createState() => _SubscriberFormScreenState();
}

class _SubscriberFormScreenState extends State<SubscriberFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _phone;
  late final TextEditingController _address;
  int? _squareId;
  SubscriberStatus _status = SubscriberStatus.active;
  DateTime? _startDate;
  List<Square> _squares = [];
  bool _saving = false;

  SubscribersRepository _resolveRepository() {
    if (widget.repository != null) return widget.repository!;
    try {
      return context.read<SubscribersRepository>();
    } catch (_) {
      return FouadModule.deps.subscribers;
    }
  }

  @override
  void initState() {
    super.initState();
    final e = widget.existing;
    _name = TextEditingController(text: e?.name ?? '');
    _phone = TextEditingController(text: e?.phone ?? '+967');
    _address = TextEditingController(text: e?.address ?? '');
    _squareId = e?.squareId ?? widget.initialSquareId;
    _status = e?.status ?? SubscriberStatus.active;
    _startDate = e?.subscriptionStartDate != null
        ? DateTime.tryParse(e!.subscriptionStartDate!)
        : (e == null ? DateTime.now() : null);
    _resolveRepository().getSquaresLookup().then((s) {
      if (mounted) setState(() => _squares = s);
    });
  }

  @override
  Widget build(BuildContext context) {
    final isEdit = widget.existing != null;
    return Scaffold(
      appBar: AppBar(title: Text(isEdit ? 'تعديل مشترك' : 'مشترك جديد')),
      body: Form(
        key: _formKey,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          TextFormField(
            controller: _name,
            decoration: const InputDecoration(labelText: 'اسم المشترك الرباعي *', border: OutlineInputBorder()),
            validator: (v) => (v == null || v.trim().isEmpty) ? 'الاسم مطلوب' : null,
          ),
          const SizedBox(height: 12),
          Directionality(
            textDirection: TextDirection.ltr,
            child: TextFormField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'رقم الهاتف (+967)', border: OutlineInputBorder()),
              onChanged: (v) => _phone.value = TextEditingValue(
                text: normalizeDigits(v),
                selection: TextSelection.collapsed(offset: normalizeDigits(v).length),
              ),
            ),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<int>(
            key: ValueKey<int?>(_squareId),
            initialValue: _squareId,
            decoration: const InputDecoration(labelText: 'المربع السكني *', border: OutlineInputBorder()),
            items: [for (final s in _squares) DropdownMenuItem(value: s.id, child: Text(s.name))],
            onChanged: (v) => setState(() => _squareId = v),
            validator: (v) => v == null ? 'اختر المربع السكني' : null,
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<SubscriberStatus>(
            key: ValueKey<SubscriberStatus>(_status),
            initialValue: _status,
            decoration: const InputDecoration(labelText: 'حالة المشترك', border: OutlineInputBorder()),
            items: [for (final s in SubscriberStatus.values) DropdownMenuItem(value: s, child: Text(s.label))],
            onChanged: (v) => setState(() => _status = v ?? _status),
          ),
          const SizedBox(height: 12),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('تاريخ بدء الاشتراك'),
            subtitle: Text(_startDate == null ? 'غير محدد' : _startDate!.toIso8601String().substring(0, 10)),
            trailing: const Icon(Icons.calendar_month),
            onTap: () async {
              final picked = await showDatePicker(
                context: context,
                firstDate: DateTime(2000),
                lastDate: DateTime(2100),
                initialDate: _startDate ?? DateTime.now(),
              );
              if (picked != null) setState(() => _startDate = picked);
            },
          ),
          TextFormField(
            controller: _address,
            maxLines: 2,
            decoration: const InputDecoration(labelText: 'العنوان التفصيلي', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 20),
          FilledButton(
            onPressed: _saving ? null : _save,
            child: Text(_saving ? 'جارٍ الحفظ...' : 'حفظ المشترك'),
          ),
        ]),
      ),
    );
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final repo = _resolveRepository();
    final draft = Subscriber(
      id: widget.existing?.id ?? 0,
      name: _name.text.trim(),
      phone: _phone.text.trim().isEmpty ? null : _phone.text.trim(),
      address: _address.text.trim().isEmpty ? null : _address.text.trim(),
      squareId: _squareId,
      status: _status,
      subscriptionStartDate: _startDate?.toIso8601String().substring(0, 10),
    );
    try {
      if (widget.existing == null) {
        await repo.createSubscriber(draft);
      } else {
        await repo.updateSubscriber(draft);
      }
      if (!mounted) return;
      final router = GoRouter.maybeOf(context);
      if (router != null && router.canPop()) {
        context.pop(true);
      } else {
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      if (mounted) {
        setState(() => _saving = false);
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    }
  }
}
