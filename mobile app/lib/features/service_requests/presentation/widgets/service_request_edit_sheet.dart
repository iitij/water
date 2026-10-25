import 'package:flutter/material.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
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
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xxl)),
      ),
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

  Future<void> _pickDate() async {
    final selected = await showDatePicker(
      context: context, initialDate: _date,
      firstDate: DateTime(1900), lastDate: DateTime(2200),
    );
    if (selected != null && mounted) setState(() => _date = selected);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return PopScope(
      canPop: !_saving,
      child: Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Flexible(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
                child: Form(
                  key: _form,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        'تعديل طلب الخدمة',
                        style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600),
                      ),
                      const Gap(4),
                      Text(
                        _subscriberName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall,
                      ),
                      if (widget.request.canEdit) Align(
                        alignment: AlignmentDirectional.centerStart,
                        child: TextButton.icon(
                          onPressed: _saving ? null : () => setState(() => _changingSubscriber = !_changingSubscriber),
                          icon: const Icon(Icons.person_search_outlined, size: 18),
                          label: Text(_changingSubscriber ? 'إغلاق اختيار المشترك' : 'تغيير المشترك'),
                        ),
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
                      const Gap(12),
                      AppNumericField(
                        controller: _amount, label: 'المبلغ (ريال)', allowDecimal: true,
                        enabled: !_saving && widget.request.canEdit,
                        validator: (value) {
                          final amount = double.tryParse(ArabicToEnglishDigitsFormatter.normalize(value ?? '').trim());
                          return amount == null || !amount.isFinite || amount <= 0 ? 'أدخل مبلغاً أكبر من صفر' : null;
                        },
                      ),
                      const Gap(8),
                      AppSettingRow(
                        icon: Icons.calendar_month_rounded,
                        color: scheme.primary,
                        title: 'تاريخ الخدمة',
                        subtitle: _date.toIso8601String().substring(0, 10),
                        showChevron: false,
                        onTap: _saving || !widget.request.canEdit ? null : _pickDate,
                      ),
                      const Gap(8),
                      DropdownButtonFormField<TankerStatus>(
                        initialValue: _status,
                        decoration: const InputDecoration(labelText: 'حالة الطلب'),
                        items: [for (final status in widget.request.canEdit
                            ? TankerStatus.values : [widget.request.status])
                          DropdownMenuItem(value: status, child: Text(status.label))],
                        onChanged: _saving || !widget.request.canEdit ? null : (value) => setState(() => _status = value!),
                      ),
                      const Gap(12),
                      TextFormField(controller: _notes, enabled: !_saving && widget.request.canEdit,
                        maxLines: 3, decoration: const InputDecoration(labelText: 'ملاحظات')),
                      if (_error != null) Padding(
                        padding: const EdgeInsets.only(top: 12),
                        child: AppNotice(
                          title: 'تعذر الحفظ',
                          message: _error!,
                          icon: Icons.error_outline_rounded,
                          color: AppColors.danger,
                        ),
                      ),
                      if (!widget.request.canEdit) const Padding(
                        padding: EdgeInsets.only(top: 12), child: Text('لا يمكن تعديل طلب مرتبط بفاتورة.')),
                    ],
                  ),
                ),
              ),
            ),
            SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    AppPrimaryButton(
                      label: _saving ? 'جارٍ الحفظ…' : 'حفظ التعديلات',
                      loading: _saving,
                      onPressed: _saving || !widget.request.canEdit ? null : _save,
                    ),
                    const Gap(4),
                    TextButton(
                      onPressed: _saving ? null : () => Navigator.pop(context, false),
                      child: const Text('إلغاء'),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
