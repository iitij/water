import 'package:flutter/material.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/widgets/app_numeric_field.dart';
import '../../../../shared/widgets/subscriber_meter_selector.dart';
import '../../../subscribers/domain/repositories/subscribers_repository.dart';
import '../../domain/entities/service_request.dart';

class ServiceRequestEditSheet extends StatefulWidget {
  const ServiceRequestEditSheet({super.key, required this.request, required this.onSave, this.subscribers});
  final ServiceRequest request;
  final Future<void> Function(ServiceRequest) onSave;
  final SubscribersRepository? subscribers;

  static Future<bool?> show(BuildContext context, {
    required ServiceRequest request,
    required Future<void> Function(ServiceRequest) onSave,
  }) {
    if (!request.canEdit) return Future.value(false);
    return showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      isDismissible: false,
      enableDrag: false,
      builder: (_) => ServiceRequestEditSheet(request: request, onSave: onSave),
    );
  }

  @override
  State<ServiceRequestEditSheet> createState() => _ServiceRequestEditSheetState();
}

class _ServiceRequestEditSheetState extends State<ServiceRequestEditSheet> {
  final _form = GlobalKey<FormState>();
  late final _amount = TextEditingController(text: widget.request.amount.toString());
  late final _notes = TextEditingController(text: widget.request.notes ?? '');
  late DateTime _date = DateTime.tryParse(widget.request.serviceDate) ?? DateTime.now();
  late TankerStatus _status = widget.request.status;
  late int _subscriberId = widget.request.subscriberId;
  late String _subscriberName = widget.request.subscriberName ?? 'مشترك #${widget.request.subscriberId}';
  bool _changingSubscriber = false;
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _amount.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_saving || !widget.request.canEdit || !_form.currentState!.validate()) return;
    setState(() { _saving = true; _error = null; });
    try {
      await widget.onSave(ServiceRequest(
        id: widget.request.id,
        subscriberId: _subscriberId,
        subscriberName: _subscriberName,
        amount: double.parse(ArabicToEnglishDigitsFormatter.normalize(_amount.text).trim()),
        serviceDate: _date.toIso8601String().substring(0, 10),
        status: _status,
        notes: _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      ));
      if (mounted) {
        setState(() => _saving = false);
        Navigator.pop(context, true);
      }
    } catch (error) {
      if (mounted) setState(() { _saving = false; _error = friendlyError(error); });
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !_saving,
    child: Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
        child: Form(
          key: _form,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('تعديل طلب الخدمة', style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: 16),
              Text(_subscriberName, style: Theme.of(context).textTheme.titleMedium),
              if (widget.request.canEdit) TextButton.icon(
                onPressed: _saving ? null : () => setState(() => _changingSubscriber = !_changingSubscriber),
                icon: const Icon(Icons.person_search_outlined),
                label: Text(_changingSubscriber ? 'إغلاق اختيار المشترك' : 'تغيير المشترك'),
              ),
              if (_changingSubscriber && !_saving) SizedBox(
                height: 240,
                child: SubscriberMeterSelectorWidget(
                  repository: widget.subscribers,
                  showMeterPicker: false,
                  onSelected: (subscriber, _) => setState(() {
                    _subscriberId = subscriber.id;
                    _subscriberName = subscriber.name;
                    _changingSubscriber = false;
                  }),
                ),
              ),
              const SizedBox(height: 12),
              AppNumericField(
                controller: _amount, label: 'المبلغ (ريال)', allowDecimal: true,
                enabled: !_saving && widget.request.canEdit,
                validator: (value) {
                  final amount = double.tryParse(ArabicToEnglishDigitsFormatter.normalize(value ?? '').trim());
                  return amount == null || !amount.isFinite || amount <= 0 ? 'أدخل مبلغاً أكبر من صفر' : null;
                },
              ),
              const SizedBox(height: 16),
              OutlinedButton.icon(
                onPressed: _saving || !widget.request.canEdit ? null : () async {
                  final selected = await showDatePicker(
                    context: context, initialDate: _date,
                    firstDate: DateTime(1900), lastDate: DateTime(2200),
                  );
                  if (selected != null && mounted) setState(() => _date = selected);
                },
                icon: const Icon(Icons.calendar_today_outlined),
                label: Text('تاريخ الخدمة: ${_date.toIso8601String().substring(0, 10)}'),
              ),
              const SizedBox(height: 16),
              DropdownButtonFormField<TankerStatus>(
                initialValue: _status,
                decoration: const InputDecoration(labelText: 'حالة الطلب'),
                items: [for (final status in widget.request.canEdit
                    ? TankerStatus.values : [widget.request.status])
                  DropdownMenuItem(value: status, child: Text(status.label))],
                onChanged: _saving || !widget.request.canEdit ? null : (value) => setState(() => _status = value!),
              ),
              const SizedBox(height: 16),
              TextFormField(controller: _notes, enabled: !_saving && widget.request.canEdit,
                maxLines: 3, decoration: const InputDecoration(labelText: 'ملاحظات')),
              if (_error != null) Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
              ),
              if (!widget.request.canEdit) const Padding(
                padding: EdgeInsets.only(top: 12), child: Text('لا يمكن تعديل طلب مرتبط بفاتورة.')),
              const SizedBox(height: 20),
              FilledButton(
                onPressed: _saving || !widget.request.canEdit ? null : _save,
                child: Text(_saving ? 'جارٍ الحفظ…' : 'حفظ التعديلات'),
              ),
              TextButton(onPressed: _saving ? null : () => Navigator.pop(context, false), child: const Text('إلغاء')),
            ],
          ),
        ),
      ),
    ),
  );
}
