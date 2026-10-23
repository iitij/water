import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/widgets/app_button.dart';
import '../../../../core/widgets/app_numeric_field.dart';
import '../../../../core/widgets/app_text_field.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
import '../../../../features/admin_shared/presentation/widgets/admin_widgets.dart';
import '../../../squares/domain/entities/square.dart';
import '../../domain/entities/subscriber.dart';
import '../../domain/repositories/subscribers_repository.dart';

/// نموذج إضافة/تعديل مشترك (هاتف يمني +967، تاريخ بدء الاشتراك، مربع، حالة).
class SubscriberFormScreen extends StatefulWidget {
  const SubscriberFormScreen({super.key, this.existing, this.repository});
  final Subscriber? existing;
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
  bool _squaresLoading = true;

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
    _squareId = e?.squareId;
    _status = e?.status ?? SubscriberStatus.active;
    _startDate = e?.subscriptionStartDate != null ? DateTime.tryParse(e!.subscriptionStartDate!) : null;
    _resolveRepository().getSquaresLookup().then((s) {
      if (mounted) setState(() { _squares = s; _squaresLoading = false; });
    }).catchError((_) {
      if (mounted) setState(() => _squaresLoading = false);
    });
  }

  @override
  Widget build(BuildContext context) {
    final isEdit = widget.existing != null;
    return Scaffold(
      appBar: AppBar(title: Text(isEdit ? 'تعديل المشترك' : 'مشترك جديد')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            AppTextField(
              controller: _name,
              label: 'اسم المشترك الرباعي *',
              validator: (v) => (v == null || v.trim().isEmpty) ? 'الاسم مطلوب' : null,
            ),
            const SizedBox(height: 14),
            AppTextField(
              controller: _phone,
              label: 'رقم الهاتف (+967)',
              keyboardType: TextInputType.phone,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.left,
              onChanged: (v) => _phone.value = TextEditingValue(
                text: normalizeDigits(v),
                selection: TextSelection.collapsed(offset: normalizeDigits(v).length),
              ),
            ),
            const SizedBox(height: 14),
            if (_squaresLoading)
              const ShimmerSkeleton(height: 58)
            else
              DropdownButtonFormField<int>(
                value: _squareId,
                isDense: true,
                decoration: _formDropdownDecoration('المربع السكني *'),
                items: [for (final s in _squares) DropdownMenuItem(value: s.id, child: Text(s.name))],
                onChanged: (v) => setState(() => _squareId = v),
                validator: (v) => v == null ? 'اختر المربع السكني' : null,
              ),
            const SizedBox(height: 14),
            DropdownButtonFormField<SubscriberStatus>(
              value: _status,
              isDense: true,
              decoration: _formDropdownDecoration('حالة المشترك'),
              items: [for (final s in SubscriberStatus.values) DropdownMenuItem(value: s, child: Text(s.label))],
              onChanged: (v) => setState(() => _status = v ?? _status),
            ),
            const SizedBox(height: 14),
            _DateField(
              label: 'تاريخ بدء الاشتراك',
              value: _startDate,
              onPick: (d) => setState(() => _startDate = d),
            ),
            const SizedBox(height: 14),
            AppTextField(controller: _address, label: 'العنوان التفصيلي', maxLines: 2),
            const SizedBox(height: 24),
            AppButton(
              label: _saving ? 'جارٍ الحفظ...' : 'حفظ المشترك',
              loading: _saving,
              onPressed: _saving ? null : _save,
            ),
          ],
        ),
      ),
    );
  }

  InputDecoration _formDropdownDecoration(String label) {
    return InputDecoration(
      filled: true,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(16)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16)),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(16),
        borderSide: const BorderSide(color: AppColors.primary, width: 1.7),
      ),
      labelText: label,
      labelStyle: const TextStyle(color: AppColors.neutral),
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

/// حقل تاريخ عصري: سطر قابل للنقر يفتح منقب التاريخ.
class _DateField extends StatelessWidget {
  const _DateField({required this.label, required this.value, required this.onPick});
  final String label;
  final DateTime? value;
  final ValueChanged<DateTime?> onPick;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Theme.of(context).dividerColor.withValues(alpha: .7)),
      ),
      child: Row(
        children: [
          Icon(Icons.calendar_month_outlined, color: AppColors.primary, size: 22),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: const TextStyle(fontSize: 11, color: AppColors.neutral)),
                const SizedBox(height: 2),
                Text(
                  value == null ? 'غير محدد' : value!.toIso8601String().substring(0, 10),
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ],
            ),
          ),
          GestureDetector(
            onTap: () async {
              final picked = await showDatePicker(
                context: context,
                firstDate: DateTime(2000),
                lastDate: DateTime(2100),
                initialDate: value ?? DateTime.now(),
              );
              if (picked != null) onPick(picked);
            },
            child: const Padding(
              padding: EdgeInsets.all(8),
              child: Icon(Icons.chevron_left_rounded, color: AppColors.neutral),
            ),
          ),
        ],
      ),
    );
  }
}
