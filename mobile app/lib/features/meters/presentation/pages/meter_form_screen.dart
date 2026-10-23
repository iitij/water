import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../../../../core/utils/compound_search_parser.dart';
import '../../../../core/widgets/app_text_field.dart';
import '../../../../core/widgets/filled_dropdown.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
import '../../data/models/meter.dart';
import '../../domain/meters_repository.dart';

class MeterFormScreen extends StatefulWidget {
  const MeterFormScreen({
    required this.repository,
    this.meter,
    super.key,
  });

  final MetersRepository repository;
  final Meter? meter;

  @override
  State<MeterFormScreen> createState() => _MeterFormScreenState();
}

class _MeterFormScreenState extends State<MeterFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _uniqueCodeController = TextEditingController();
  final _pathController = TextEditingController();
  final _tenantNameController = TextEditingController();
  final _tenantPhoneController = TextEditingController();
  Timer? _pathValidationTimer;
  int _pathValidationSequence = 0;
  List<SubscriberOption> _subscribers = const [];
  SubscriberOption? _selectedSubscriber;
  String _status = 'active';
  bool _isLoading = true;
  bool _isSaving = false;
  bool _isCheckingPath = false;
  bool? _pathConflict;
  String? _loadError;

  bool get _showTenantFields {
    final selected = _selectedSubscriber;
    if (selected == null) return false;
    final existingMeterCount = selected.meterCount;
    final countAfterSave = widget.meter != null &&
            widget.meter!.subscriberId == selected.id
        ? existingMeterCount
        : existingMeterCount + 1;
    return countAfterSave > 1 ||
        (_tenantNameController.text.trim().isNotEmpty ||
            _tenantPhoneController.text.trim().isNotEmpty);
  }

  @override
  void initState() {
    super.initState();
    final meter = widget.meter;
    _uniqueCodeController.text = meter?.uniqueCode ?? '';
    _pathController.text = meter?.pathNumber?.toString() ?? '';
    _tenantNameController.text = meter?.tenantName ?? '';
    _tenantPhoneController.text = meter?.tenantPhone ?? '';
    _status = meter?.status ?? 'active';
    _loadFormOptions();
  }

  Future<void> _loadFormOptions() async {
    try {
      final subscribers = await widget.repository.getSubscribers();
      if (!mounted) return;
      setState(() {
        _subscribers = subscribers;
        _selectedSubscriber = _subscribers.cast<SubscriberOption?>().firstWhere(
              (subscriber) => subscriber?.id == widget.meter?.subscriberId,
              orElse: () => null,
            );
        _isLoading = false;
      });
      _schedulePathValidation(_pathController.text);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loadError = 'تعذر تحميل المربعات والمشتركين. تحقق من الاتصال.';
        _isLoading = false;
      });
    }
  }

  void _onSubscriberChanged(SubscriberOption? subscriber) {
    setState(() {
      _selectedSubscriber = subscriber;
      _pathConflict = null;
    });
    _schedulePathValidation(_pathController.text);
    if (subscriber != null &&
        (subscriber.squareId == null || subscriber.meterCount == 0)) {
      unawaited(_loadSubscriberDetails(subscriber));
    }
  }

  Future<void> _loadSubscriberDetails(SubscriberOption subscriber) async {
    try {
      final detailed = await widget.repository.getSubscriber(subscriber.id);
      if (!mounted || _selectedSubscriber?.id != subscriber.id) return;
      setState(() {
        _selectedSubscriber = detailed;
        _subscribers = [
          for (final item in _subscribers)
            if (item.id == detailed.id) detailed else item,
        ];
      });
      _schedulePathValidation(_pathController.text);
    } catch (_) {
      // The legacy summary API can omit square data. Keep the form usable, but
      // saving a path number is blocked until its square can be verified.
    }
  }

  void _schedulePathValidation(String rawValue) {
    _pathValidationTimer?.cancel();
    final sequence = ++_pathValidationSequence;
    final pathNumber = int.tryParse(CompoundSearchQuery.normalizeDigits(rawValue.trim()));
    final squareId = _selectedSubscriber?.squareId;
    if (pathNumber == null || squareId == null) {
      setState(() {
        _pathConflict = false;
        _isCheckingPath = false;
      });
      return;
    }
    setState(() {
      _pathConflict = null;
      _isCheckingPath = true;
    });
    _pathValidationTimer = Timer(const Duration(milliseconds: 250), () async {
      try {
        final isAvailable = await widget.repository.isPathNumberAvailable(
          pathNumber: pathNumber,
          squareId: squareId,
          ignoreMeterId: widget.meter?.id,
        );
        if (!mounted || sequence != _pathValidationSequence) return;
        setState(() {
          _pathConflict = !isAvailable;
          _isCheckingPath = false;
        });
      } catch (_) {
        if (!mounted || sequence != _pathValidationSequence) return;
        setState(() {
          _pathConflict = null;
          _isCheckingPath = false;
        });
      }
    });
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    final subscriber = _selectedSubscriber;
    if (subscriber == null) {
      _showMessage('اختر المشترك مالك العداد.');
      return;
    }
    final pathNumber = int.tryParse(
      CompoundSearchQuery.normalizeDigits(_pathController.text.trim()),
    );
    if (pathNumber != null && subscriber.squareId == null) {
      _showMessage('تعذر تحديد مربع المشترك للتحقق من تكرار المسار. أعد اختيار المشترك أو اترك رقم المسار فارغاً.');
      return;
    }
    if (pathNumber != null && subscriber.squareId != null) {
      setState(() => _isCheckingPath = true);
      try {
        final isAvailable = await widget.repository.isPathNumberAvailable(
          pathNumber: pathNumber,
          squareId: subscriber.squareId!,
          ignoreMeterId: widget.meter?.id,
        );
        if (!mounted) return;
        setState(() {
          _isCheckingPath = false;
          _pathConflict = !isAvailable;
        });
        if (!isAvailable) {
          _formKey.currentState!.validate();
          return;
        }
      } catch (error) {
        if (!mounted) return;
        setState(() => _isCheckingPath = false);
        _showMessage(_errorMessage(error));
        return;
      }
    }

    setState(() => _isSaving = true);
    try {
      await widget.repository.saveMeter(
        MeterDraft(
          uniqueCode: _uniqueCodeController.text.trim(),
          subscriberId: subscriber.id,
          pathNumber: pathNumber,
          tenantName: _showTenantFields ? _tenantNameController.text : null,
          tenantPhone: _showTenantFields ? _tenantPhoneController.text : null,
          status: _status,
        ),
        meterId: widget.meter?.id,
      );
      if (mounted) Navigator.of(context).pop(true);
    } catch (error) {
      if (mounted) {
        setState(() => _isSaving = false);
        _showMessage(_errorMessage(error));
      }
    }
  }

  void _showMessage(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  String _errorMessage(Object error) {
    if (error is StateError) return error.message.toString();
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map) {
        final errors = data['errors'];
        if (errors is Map && errors.isNotEmpty) {
          final first = errors.values.first;
          if (first is List && first.isNotEmpty) return first.first.toString();
          return first.toString();
        }
        if (data['message'] != null) return data['message'].toString();
      }
      if (error.response == null) return 'لا يوجد اتصال بالخادم.';
    }
    return 'تعذر حفظ بيانات العداد. تحقق من الاتصال وصحة البيانات.';
  }

  @override
  void dispose() {
    _pathValidationTimer?.cancel();
    _uniqueCodeController.dispose();
    _pathController.dispose();
    _tenantNameController.dispose();
    _tenantPhoneController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        appBar: AppBar(title: Text(widget.meter == null ? 'إضافة عداد' : 'تعديل العداد')),
        body: _isLoading
            ? const ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  SizedBox(height: 60, child: ShimmerSkeleton(radius: 16)),
                  SizedBox(height: 12),
                  SizedBox(height: 60, child: ShimmerSkeleton(radius: 16)),
                  SizedBox(height: 12),
                  SizedBox(height: 60, child: ShimmerSkeleton(radius: 16)),
                  SizedBox(height: 20),
                  SizedBox(height: 60, child: ShimmerSkeleton(radius: 16)),
                ],
              )
            : _loadError != null
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(24),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.wifi_off_outlined, size: 48, color: AppColors.danger),
                          const SizedBox(height: 14),
                          Text(_loadError!, textAlign: TextAlign.center),
                        ],
                      ),
                    ),
                  )
                : Form(
                    key: _formKey,
                    child: LayoutBuilder(
                      builder: (context, constraints) {
                        final maxWidth = constraints.maxWidth >= 840 ? 760.0 : double.infinity;
                        return Center(
                          child: ConstrainedBox(
                            constraints: BoxConstraints(maxWidth: maxWidth),
                            child: ListView(
                              padding: const EdgeInsets.all(16),
                              children: [
                                _sectionHeader(context, 'بيانات العداد'),
                                AppTextField(
                                  controller: _uniqueCodeController,
                                  label: 'رقم العداد',
                                  prefixIcon: Icons.speed_outlined,
                                  textDirection: TextDirection.ltr,
                                  textInputAction: TextInputAction.next,
                                  validator: (value) => value == null || value.trim().isEmpty
                                      ? 'رقم العداد مطلوب.'
                                      : value.trim().length > 50
                                          ? 'الحد الأقصى 50 حرفاً.'
                                          : null,
                                ),
                                const SizedBox(height: 12),
                                AppTextField(
                                  controller: _pathController,
                                  label: 'رقم المسار (اختياري)',
                                  hint: 'مثال: 1',
                                  prefixIcon: Icons.route_outlined,
                                  suffixIcon: _isCheckingPath
                                      ? Padding(
                                          padding: const EdgeInsets.all(12),
                                          child: SizedBox(
                                            width: 16,
                                            height: 16,
                                            child: CircularProgressIndicator(strokeWidth: 2),
                                          ),
                                        )
                                      : _pathConflict == true
                                          ? const Icon(Icons.error_outline, color: Colors.red)
                                          : _pathConflict == false && _pathController.text.isNotEmpty
                                              ? const Icon(Icons.check_circle_outline, color: Colors.green)
                                              : null,
                                  helperText: _pathConflict == true
                                      ? 'رقم المسار مستخدم بالفعل في هذا المربع.'
                                      : 'يجب أن يكون رقم المسار فريداً داخل المربع السكني.',
                                  helperStyle: TextStyle(
                                    color: _pathConflict == true ? Colors.red : null,
                                  ),
                                  textDirection: TextDirection.ltr,
                                  keyboardType: TextInputType.number,
                                  onChanged: _schedulePathValidation,
                                  validator: (value) {
                                    final text = value?.trim() ?? '';
                                    if (text.isEmpty) return null;
                                    if (int.tryParse(CompoundSearchQuery.normalizeDigits(text)) == null) {
                                      return 'أدخل رقم مسار صحيحاً.';
                                    }
                                    if (_pathConflict == true) {
                                      return 'هذا المسار مستخدم في المربع نفسه.';
                                    }
                                    return null;
                                  },
                                ),
                                const SizedBox(height: 12),
                                FilledDropdown<int>(
                                  value: _selectedSubscriber?.id,
                                  label: 'المشترك المالك',
                                  prefixIcon: const Icon(Icons.person_outline),
                                  helperText: widget.meter == null
                                      ? null
                                      : 'تغيير مالك العداد غير مدعوم من واجهة الخادم الحالية.',
                                  items: _subscribers
                                      .map((subscriber) => DropdownMenuItem<int>(
                                            value: subscriber.id,
                                            child: Text(
                                              '${subscriber.name}${subscriber.squareName == null ? '' : ' — ${subscriber.squareName}'}',
                                              overflow: TextOverflow.ellipsis,
                                            ),
                                          ))
                                      .toList(growable: false),
                                  validator: (value) => value == null ? 'اختر المشترك.' : null,
                                  onChanged: widget.meter == null
                                      ? (id) => _onSubscriberChanged(
                                            _subscribers.cast<SubscriberOption?>().firstWhere(
                                              (item) => item?.id == id,
                                              orElse: () => null,
                                            ),
                                          )
                                      : null,
                                ),
                                if (_selectedSubscriber?.squareName != null) ...[
                                  const SizedBox(height: 6),
                                  Align(
                                    alignment: Alignment.centerRight,
                                    child: Text('المربع: ${_selectedSubscriber!.squareName}'),
                                  ),
                                ],
                                if (_showTenantFields) ...[
                                  const SizedBox(height: 20),
                                  _sectionHeader(context, 'بيانات المستأجر (اختيارية)'),
                                  AppTextField(
                                    controller: _tenantNameController,
                                    label: 'اسم المستأجر',
                                    prefixIcon: Icons.person_pin_outlined,
                                    textInputAction: TextInputAction.next,
                                    onChanged: (_) => setState(() {}),
                                  ),
                                  const SizedBox(height: 12),
                                  AppTextField(
                                    controller: _tenantPhoneController,
                                    label: 'هاتف المستأجر',
                                    prefixIcon: Icons.phone_outlined,
                                    hint: '+967…',
                                    textDirection: TextDirection.ltr,
                                    keyboardType: TextInputType.phone,
                                    onChanged: (_) => setState(() {}),
                                  ),
                                ],
                                const SizedBox(height: 20),
                                FilledDropdown<String>(
                                  value: _status,
                                  label: 'حالة العداد',
                                  prefixIcon: const Icon(Icons.toggle_on_outlined),
                                  items: const [
                                    DropdownMenuItem(value: 'active', child: Text('نشط')),
                                    DropdownMenuItem(value: 'inactive', child: Text('غير نشط')),
                                  ],
                                  onChanged: (value) {
                                    if (value != null) setState(() => _status = value);
                                  },
                                ),
                                const SizedBox(height: 28),
                                FilledButton.icon(
                                  onPressed: _isSaving || _isCheckingPath ? null : _save,
                                  icon: _isSaving
                                      ? const SizedBox.square(
                                          dimension: 18,
                                          child: CircularProgressIndicator(strokeWidth: 2),
                                        )
                                      : const Icon(Icons.save_outlined),
                                  label: Text(_isSaving ? 'جارٍ الحفظ…' : 'حفظ بيانات العداد'),
                                ),
                              ],
                            ),
                          ),
                        );
                      },
                    ),
                  ),
      ),
    );
  }

  Widget _sectionHeader(BuildContext context, String text) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Text(text, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold)),
      );
}
