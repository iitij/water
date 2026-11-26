import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../../../../core/navigation/sub_page.dart';
import '../../../../core/theme/app_colors.dart';

import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/utils/compound_search_parser.dart';
import '../../../../core/widgets/empty_state_widget.dart';
import '../../data/models/meter.dart';
import '../../domain/meters_repository.dart';
import 'meter_replacement_dialog.dart';

class MeterFormScreen extends StatefulWidget {
  const MeterFormScreen({
    required this.repository,
    this.meter,
    this.initialSubscriberId,
    this.onSaved,
    super.key,
  });

  final MetersRepository repository;
  final Meter? meter;
  final int? initialSubscriberId;
  /// Receives the persisted meter only after a successful save while mounted.
  final ValueChanged<Meter>? onSaved;

  @override
  State<MeterFormScreen> createState() => _MeterFormScreenState();
}

class _MeterFormScreenState extends State<MeterFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _uniqueCodeController = TextEditingController();
  final _pathController = TextEditingController();
  final _initialController = TextEditingController(text: '0');
  DateTime? _installedAt;
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

  @override
  void initState() {
    super.initState();
    final meter = widget.meter;
    _uniqueCodeController.text = meter?.uniqueCode ?? '';
    _pathController.text = meter?.pathNumber?.toString() ?? '';
    _status = meter?.status ?? 'active';
    _loadFormOptions();
  }

  Future<void> _loadFormOptions() async {
    try {
      final subscribers = await widget.repository.getSubscribers();
      if (!mounted) return;
      final targetSubscriberId =
          widget.meter?.subscriberId ?? widget.initialSubscriberId;
      final preselected = subscribers.cast<SubscriberOption?>().firstWhere(
            (subscriber) => subscriber?.id == targetSubscriberId,
            orElse: () => null,
          );
      setState(() {
        _subscribers = subscribers;
        _selectedSubscriber = preselected;
        _isLoading = false;
      });
      if (preselected != null && (preselected.squareId == null || preselected.meterCount == 0)) {
        unawaited(_loadSubscriberDetails(preselected));
      }
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
    final editingSameMeter = widget.meter != null &&
        widget.meter!.id == subscriber.currentMeterId;
    if (subscriber.hasCurrentMeter && !editingSameMeter) {
      _showMessage('هذا المشترك مرتبط بعداد حالي. لا يمكن إضافة عداد ثانٍ.');
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
      final savedMeter = await widget.repository.saveMeter(
        MeterDraft(
          uniqueCode: _uniqueCodeController.text.trim(),
          subscriberId: subscriber.id,
          pathNumber: pathNumber,
          status: _status,
          initialReading: widget.meter == null ? double.parse(CompoundSearchQuery.normalizeDigits(_initialController.text.trim())) : null,
          installedAt: widget.meter == null ? _installedAt : null,
        ),
        meterId: widget.meter?.id,
      );
      if (!mounted) return;
      widget.onSaved?.call(savedMeter);
      if (mounted) Navigator.of(context).pop(true);
    } catch (error) {
      if (mounted) {
        setState(() => _isSaving = false);
        _showMessage(_errorMessage(error));
      }
    }
  }

  Future<void> _replaceCurrentMeter() async {
    final old = widget.meter;
    if (old == null || old.removedAt != null || _isSaving) return;
    final replacement = await showDialog<Meter>(
      context: context,
      barrierDismissible: false,
      builder: (_) => MeterReplacementDialog(
        meter: old,
        repository: widget.repository,
      ),
    );
    if (replacement == null || !mounted) return;
    widget.onSaved?.call(replacement);
    Navigator.of(context).pop(true);
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
    _initialController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.rtl,
      child: SubPageScaffold(
        title: widget.meter == null ? 'إضافة عداد' : 'تعديل العداد',
        child: _isLoading
            ? const Center(child: AppLoader(label: 'جارٍ تحميل بيانات النموذج…'))
            : _loadError != null
                ? EmptyStateWidget(
                    icon: Icons.cloud_off_outlined,
                    title: 'تعذر تحميل النموذج',
                    message: _loadError!,
                    action: TextButton.icon(
                      onPressed: _isLoading ? null : _loadFormOptions,
                      icon: const Icon(Icons.refresh_rounded, size: 18),
                      label: const Text('إعادة المحاولة'),
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
                                const SectionHeading(
                                  icon: Icons.speed_rounded,
                                  title: 'بيانات العداد',
                                ),
                                const SizedBox(height: 8),
                                AppCard(
                                  child: Column(
                                    children: [
                                      TextFormField(
                                        controller: _uniqueCodeController,
                                        textDirection: TextDirection.ltr,
                                        decoration: const InputDecoration(
                                          labelText: 'رقم العداد',
                                          prefixIcon: Icon(Icons.speed_outlined),
                                        ),
                                        textInputAction: TextInputAction.next,
                                        validator: (value) => value == null || value.trim().isEmpty
                                            ? 'رقم العداد مطلوب.'
                                            : value.trim().length > 50
                                                ? 'الحد الأقصى 50 حرفاً.'
                                                : null,
                                      ),
                                      const SizedBox(height: 12),
                                      TextFormField(
                                        controller: _pathController,
                                        textDirection: TextDirection.ltr,
                                        keyboardType: TextInputType.number,
                                        decoration: InputDecoration(
                                          labelText: 'رقم المسار (اختياري)',
                                          hintText: 'مثال: 1',
                                          prefixIcon: const Icon(Icons.route_outlined),
                                          suffixIcon: _isCheckingPath
                                              ? const Padding(
                                                  padding: EdgeInsets.all(12),
                                                  child: SizedBox(
                                                    width: 16,
                                                    height: 16,
                                                    child: CircularProgressIndicator(strokeWidth: 2),
                                                  ),
                                                )
                                              : _pathConflict == true
                                                  ? Icon(Icons.error_outline,
                                                      color: Theme.of(context).colorScheme.error)
                                                  : _pathConflict == false &&
                                                          _pathController.text.isNotEmpty
                                                      ? const Icon(Icons.check_circle_outline,
                                                          color: AppColors.success)
                                                      : null,
                                          helperText: _pathConflict == true
                                              ? 'رقم المسار مستخدم بالفعل في هذا المربع.'
                                              : 'يجب أن يكون رقم المسار فريداً داخل المربع السكني.',
                                          helperStyle: TextStyle(
                                            color: _pathConflict == true
                                                ? Theme.of(context).colorScheme.error
                                                : null,
                                          ),
                                        ),
                                        onChanged: _schedulePathValidation,
                                        validator: (value) {
                                          final text = value?.trim() ?? '';
                                          if (text.isEmpty) return null;
                                          if (int.tryParse(CompoundSearchQuery.normalizeDigits(text)) ==
                                              null) {
                                            return 'أدخل رقم مسار صحيحاً.';
                                          }
                                          if (_pathConflict == true) {
                                            return 'هذا المسار مستخدم في المربع نفسه.';
                                          }
                                          return null;
                                        },
                                      ),
                                      const SizedBox(height: 12),
                                      DropdownButtonFormField<int>(
                                        key: ValueKey<int?>(_selectedSubscriber?.id),
                                        initialValue: _selectedSubscriber?.id,
                                        isExpanded: true,
                                        decoration: InputDecoration(
                                          labelText: 'المشترك المالك',
                                          prefixIcon: const Icon(Icons.person_outline),
                                          helperText: widget.meter == null
                                              ? null
                                              : 'يمكنك نقل ملكية العداد لمشترك آخر؛ سيتم التحقق من توافر رقم المسار في مربع المشترك الجديد.',
                                        ),
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
                                        onChanged: (id) => _onSubscriberChanged(
                                          _subscribers.cast<SubscriberOption?>().firstWhere(
                                                (item) => item?.id == id,
                                                orElse: () => null,
                                              ),
                                        ),
                                      ),
                                      if (_selectedSubscriber?.squareName != null) ...[
                                        const SizedBox(height: 6),
                                        Align(
                                          alignment: Alignment.centerRight,
                                          child: Text('المربع: ${_selectedSubscriber!.squareName}'),
                                        ),
                                      ],
                                      const SizedBox(height: 12),
                                      if (widget.meter == null) ...[
                                        TextFormField(
                                          controller: _initialController,
                                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                          decoration: const InputDecoration(labelText: 'القراءة الابتدائية'),
                                          validator: (value) => RegExp(r'^\d{1,10}(\.\d{1,2})?$').hasMatch(
                                            CompoundSearchQuery.normalizeDigits(value?.trim() ?? ''))
                                              ? null : 'أدخل قراءة غير سالبة بمنزلتين عشريتين كحد أقصى.',
                                        ),
                                        const SizedBox(height: 12),
                                        ListTile(
                                          title: const Text('تاريخ تركيب العداد'),
                                          subtitle: Text(_installedAt?.toIso8601String().split('T').first
                                              ?? 'يُستخدم تاريخ بدء الخدمة الموثق إن لم يُحدد'),
                                          trailing: const Icon(Icons.calendar_today_outlined),
                                          onTap: () async {
                                            final date = await showDatePicker(context: context,
                                              initialDate: _installedAt ?? DateTime.now(),
                                              firstDate: DateTime(1900), lastDate: DateTime.now());
                                            if (mounted && date != null) setState(() => _installedAt = date);
                                          },
                                        ),
                                        const SizedBox(height: 12),
                                      ],
                                      DropdownButtonFormField<String>(
                                        key: ValueKey<String>('status-$_status'),
                                        initialValue: _status,
                                        decoration: const InputDecoration(
                                          labelText: 'حالة العداد',
                                          prefixIcon: Icon(Icons.toggle_on_outlined),
                                        ),
                                        items: const [
                                          DropdownMenuItem(value: 'active', child: Text('نشط')),
                                          DropdownMenuItem(value: 'inactive', child: Text('غير نشط')),
                                        ],
                                        onChanged: (value) {
                                          if (value != null) setState(() => _status = value);
                                        },
                                      ),
                                    ],
                                  ),
                                ),
                                const SizedBox(height: 24),
                                AppPrimaryButton(
                                  label: _isSaving ? 'جارٍ الحفظ…' : 'حفظ بيانات العداد',
                                  icon: Icons.save_outlined,
                                  loading: _isSaving,
                                  onPressed: _isSaving || _isCheckingPath ? null : () => _save(),
                                ),
                                if (widget.meter != null && widget.meter!.removedAt == null) ...[
                                  const SizedBox(height: 12),
                                  AppSecondaryButton(
                                    label: 'استبدال العداد مع حفظ تاريخه',
                                    icon: Icons.swap_horiz_rounded,
                                    onPressed: _isSaving ? null : _replaceCurrentMeter,
                                  ),
                                ],
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
}
