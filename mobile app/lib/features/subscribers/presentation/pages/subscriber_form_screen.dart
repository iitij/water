import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/l10n/app_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/permissions/permission_service.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../meters/domain/meters_repository.dart';
import '../../../meters/presentation/pages/meter_form_screen.dart';
import '../../../squares/domain/entities/square.dart';
import '../../domain/entities/subscriber.dart';
import '../../domain/repositories/subscribers_repository.dart';

/// SCR-06 — نموذج إضافة/تعديل مشترك (هاتف يمني +967، تاريخ بدء الاشتراك، مربع، حالة).
class SubscriberFormScreen extends StatefulWidget {
  const SubscriberFormScreen({super.key, this.existing, this.initialSquareId, this.repository,
    this.metersRepository, this.permissions});
  final Subscriber? existing;
  final int? initialSquareId;
  final SubscribersRepository? repository;
  final MetersRepository? metersRepository;
  final PermissionService? permissions;

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
  Subscriber? _savedSubscriber;

  PermissionService? get _permissions => widget.permissions ??
      (GetIt.I.isRegistered<PermissionService>() ? GetIt.I<PermissionService>() : null);

  /// «حفظ وإضافة آخر» — يبقى النموذج مفتوحاً بمعبّأ مسبقاً بنفس المربع والحالة،
  /// لأن مُدخل البيانات يسجّل عشرات المشتركين في المربع نفسه الواحد تلو الآخر.
  bool _keepOpen = false;

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

  Future<void> _pickStartDate() async {
    final picked = await showDatePicker(
      context: context,
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
      initialDate: _startDate ?? DateTime.now(),
    );
    if (picked != null) setState(() => _startDate = picked);
  }

  @override
  void dispose() {
    _name.dispose(); _phone.dispose(); _address.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isEdit = widget.existing != null;
    final scheme = Theme.of(context).colorScheme;
    return SubPageScaffold(
      title: isEdit ? 'تعديل مشترك' : 'مشترك جديد',
      subtitle: isEdit ? null : 'بيانات المشترك وربطه بالمربع السكني',
      child: Form(
        key: _formKey,
        child: AppPageBody(
          bottomPadding: 44,
          children: [
            AppCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SectionHeading(
                    icon: Icons.person_outline_rounded,
                    title: 'بيانات المشترك',
                  ),
                  const Gap(14),
                  TextFormField(
                    controller: _name,
                    decoration: const InputDecoration(
                      labelText: 'اسم المشترك الرباعي *',
                      prefixIcon: Icon(Icons.badge_outlined),
                    ),
                    validator: (v) => (v == null || v.trim().isEmpty) ? 'الاسم مطلوب' : null,
                  ),
                  const Gap(12),
                  Directionality(
                    textDirection: TextDirection.ltr,
                    child: TextFormField(
                      controller: _phone,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(
                        labelText: 'رقم الهاتف (+967)',
                        prefixIcon: Icon(Icons.phone_outlined),
                      ),
                      onChanged: (v) => _phone.value = TextEditingValue(
                        text: normalizeDigits(v),
                        selection: TextSelection.collapsed(offset: normalizeDigits(v).length),
                      ),
                    ),
                  ),
                  const Gap(12),
                  TextFormField(
                    controller: _address,
                    maxLines: 2,
                    decoration: const InputDecoration(
                      labelText: 'العنوان التفصيلي',
                      prefixIcon: Icon(Icons.location_on_outlined),
                    ),
                  ),
                ],
              ),
            ),
            const Gap(12),
            AppCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SectionHeading(
                    icon: Icons.grid_view_rounded,
                    title: 'الربط والحالة',
                  ),
                  const Gap(14),
                  DropdownButtonFormField<int>(
                    key: ValueKey<int?>(_squareId),
                    initialValue: _squareId,
                    decoration: const InputDecoration(labelText: 'المربع السكني *'),
                    items: [for (final s in _squares) DropdownMenuItem(value: s.id, child: Text(s.displayName))],
                    onChanged: (v) => setState(() => _squareId = v),
                    validator: (v) => v == null ? 'اختر المربع السكني' : null,
                  ),
                  const Gap(12),
                  DropdownButtonFormField<SubscriberStatus>(
                    key: ValueKey<SubscriberStatus>(_status),
                    initialValue: _status,
                    decoration: const InputDecoration(labelText: 'حالة المشترك'),
                    items: [for (final s in SubscriberStatus.values) DropdownMenuItem(value: s, child: Text(s.label))],
                    onChanged: (v) => setState(() => _status = v ?? _status),
                  ),
                  const Gap(4),
                  AppSettingRow(
                    icon: Icons.calendar_month_rounded,
                    color: scheme.primary,
                    title: 'تاريخ بدء الاشتراك',
                    subtitle: _startDate == null
                        ? 'غير محدد'
                        : _startDate!.toIso8601String().substring(0, 10),
                    onTap: _pickStartDate,
                  ),
                ],
              ),
            ),
            const Gap(20),
            AppPrimaryButton(
              label: _saving ? 'جارٍ الحفظ...' : 'حفظ المشترك',
              icon: Icons.save_outlined,
              loading: _saving,
              onPressed: _saving ? null : _save,
            ),
            if (widget.existing == null) ...<Widget>[
              if (_permissions?.can('Create:Meter') == true) ...[
                const Gap(8),
                AppSecondaryButton(
                  label: AppStrings.of(context).saveAndAddMeter,
                  icon: Icons.gas_meter_outlined,
                  onPressed: _saving ? null : _saveAndAddMeter,
                ),
              ],
              const Gap(8),
              AppSecondaryButton(
                label: AppStrings.of(context).saveAndAddAnother,
                icon: Icons.playlist_add_rounded,
                onPressed: _saving ? null : _saveAndAddAnother,
              ),
              const Gap(4),
              Text(
                'يحفظ المشترك الحالي ويفتح نموذجاً جديداً بنفس المربع والحالة.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ],
        ),
      ),
    );
  }

  /// يحفظ ثم يُفرّغ الحقول ويُبقي المربع/الحالة/التاريخ كما هي.
  Future<void> _saveAndAddAnother() async {
    if (_saving) return;
    _keepOpen = true;
    final saved = await _save();
    if (saved && mounted) {
      setState(() {
        _name.clear();
        _phone.text = '+967';
        _address.clear();
      });
      _formKey.currentState?.reset();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تم الحفظ — النموذج جاهز للمشترك التالي.')),
      );
    }
  }

  /// يعيد `true` عند نجاح الحفظ (يُستخدم من «حفظ وإضافة آخر»).
  Future<bool> _save() async {
    if (_saving) return false;
    if (!_formKey.currentState!.validate()) return false;
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
        _savedSubscriber = await repo.createSubscriber(draft);
      } else {
        await repo.updateSubscriber(draft);
      }
      if (!mounted) return false;
      if (!_keepOpen) {
        // الخروج يُدمّر الشاشة، فلا setState بعده — وإلا انفجر «setState after dispose».
        final router = GoRouter.maybeOf(context);
        if (router != null && router.canPop()) {
          context.pop(true);
        } else {
          Navigator.of(context).pop(true);
        }
        return true;
      }
      setState(() => _saving = false);
      return true;
    } catch (e) {
      if (mounted) {
        setState(() => _saving = false);
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
      return false;
    } finally {
      _keepOpen = false;
    }
  }

  Future<void> _saveAndAddMeter() async {
    if (_saving || _permissions?.can('Create:Meter') != true) return;
    final meters = widget.metersRepository ?? GetIt.I<MetersRepository>();
    _keepOpen = true;
    if (!await _save() || !mounted) return;
    final subscriber = _savedSubscriber!;
    setState(() => _saving = true);
    await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => MeterFormScreen(
      repository: meters, initialSubscriberId: subscriber.id,
    )));
    if (!mounted) return;
    // Both persisted records survive cancellation; do not offer to re-create
    // the same subscriber by leaving its populated create form behind.
    Navigator.of(context).pop(true);
  }
}
