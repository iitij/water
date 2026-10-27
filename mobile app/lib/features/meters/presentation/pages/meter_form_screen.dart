import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../../../../core/l10n/field_ux_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/utils/compound_search_parser.dart';
import '../../../../core/widgets/empty_state_widget.dart';
import '../../data/models/meter.dart';
import '../../domain/meters_repository.dart';

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
    final countAfterSave =
        widget.meter != null && widget.meter!.subscriberId == selected.id
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
    setState(() {
      _isLoading = true;
      _loadError = null;
    });
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
      if (preselected != null &&
          (preselected.squareId == null || preselected.meterCount == 0)) {
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
    final pathNumber = int.tryParse(
      CompoundSearchQuery.normalizeDigits(rawValue.trim()),
    );
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

  Future<void> _save({bool addAnother = false}) async {
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
      _showMessage(
        'تعذر تحديد مربع المشترك للتحقق من تكرار المسار. أعد اختيار المشترك أو اترك رقم المسار فارغاً.',
      );
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
          tenantName: _showTenantFields ? _tenantNameController.text : null,
          tenantPhone: _showTenantFields ? _tenantPhoneController.text : null,
          status: _status,
        ),
        meterId: widget.meter?.id,
      );
      if (!mounted) return;
      widget.onSaved?.call(savedMeter);
      if (addAnother && widget.meter == null) {
        // سلسلة «حفظ وإضافة عداد آخر» (توازي Filament: Create & create another):
        // يُحفظ العداد ثم يُفرَّغ النموذج ويبقى المشترك نفسه محدداً.
        _formKey.currentState!.reset();
        _uniqueCodeController.clear();
        _pathController.clear();
        _tenantNameController.clear();
        _tenantPhoneController.clear();
        setState(() {
          _isSaving = false;
          _status = 'active';
          _pathConflict = null;
        });
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('تم حفظ العداد — أضف العداد التالي للمشترك نفسه.'),
          ),
        );
        _schedulePathValidation('');
        return;
      }
      if (mounted) Navigator.of(context).pop(true);
    } catch (error) {
      if (mounted) {
        setState(() => _isSaving = false);
        _showMessage(_errorMessage(error));
      }
    }
  }

  void _showMessage(String message) {
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
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

  Widget _ownerSelector() => FormField<int>(
    key: ValueKey<int?>(_selectedSubscriber?.id),
    initialValue: _selectedSubscriber?.id,
    validator: (value) => value == null ? FieldUxStrings.ownerRequired : null,
    builder: (field) => Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        AppCard(
          onTap: _isSaving ? null : _chooseOwner,
          child: Row(
            children: [
              const Icon(Icons.person_search_outlined),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      FieldUxStrings.selectedOwner,
                      style: Theme.of(context).textTheme.labelMedium,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      _selectedSubscriber?.name ?? FieldUxStrings.chooseOwner,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    if (_selectedSubscriber?.squareName != null)
                      Text(_selectedSubscriber!.squareName!),
                    const SizedBox(height: 4),
                    Text(
                      _selectedSubscriber == null
                          ? FieldUxStrings.ownerHelp
                          : FieldUxStrings.changeOwner,
                    ),
                  ],
                ),
              ),
              const Icon(Icons.chevron_left_rounded),
            ],
          ),
        ),
        if (widget.meter != null)
          const Padding(
            padding: EdgeInsets.only(top: 8),
            child: Text(FieldUxStrings.transferOwnerHelp),
          ),
        if (field.hasError)
          Padding(
            padding: const EdgeInsets.all(8),
            child: Text(
              field.errorText!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ),
      ],
    ),
  );

  Future<void> _chooseOwner() async {
    final selected = await showModalBottomSheet<SubscriberOption>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => _OwnerPicker(
        subscribers: _subscribers,
        selectedId: _selectedSubscriber?.id,
      ),
    );
    if (selected != null && mounted) _onSubscriberChanged(selected);
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.rtl,
      child: SubPageScaffold(
        title: widget.meter == null ? 'إضافة عداد' : 'تعديل العداد',
        child: _isLoading
            ? const Center(
                child: AppLoader(label: 'جارٍ تحميل بيانات النموذج…'),
              )
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
                autovalidateMode: AutovalidateMode.onUserInteraction,
                child: LayoutBuilder(
                  builder: (context, constraints) {
                    final maxWidth = constraints.maxWidth >= 840
                        ? 760.0
                        : double.infinity;
                    return Center(
                      child: ConstrainedBox(
                        constraints: BoxConstraints(maxWidth: maxWidth),
                        child: ListView(
                          padding: const EdgeInsets.all(16),
                          keyboardDismissBehavior:
                              ScrollViewKeyboardDismissBehavior.onDrag,
                          children: [
                            const Text(FieldUxStrings.meterGuide),
                            const SizedBox(height: 16),
                            _ownerSelector(),
                            const SizedBox(height: 20),
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
                                    validator: (value) =>
                                        value == null || value.trim().isEmpty
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
                                      prefixIcon: const Icon(
                                        Icons.route_outlined,
                                      ),
                                      suffixIcon: _isCheckingPath
                                          ? const Padding(
                                              padding: EdgeInsets.all(12),
                                              child: SizedBox(
                                                width: 16,
                                                height: 16,
                                                child:
                                                    CircularProgressIndicator(
                                                      strokeWidth: 2,
                                                    ),
                                              ),
                                            )
                                          : _pathConflict == true
                                          ? Icon(
                                              Icons.error_outline,
                                              color: Theme.of(
                                                context,
                                              ).colorScheme.error,
                                            )
                                          : _pathConflict == false &&
                                                _pathController.text.isNotEmpty
                                          ? const Icon(
                                              Icons.check_circle_outline,
                                              color: AppColors.success,
                                            )
                                          : null,
                                      helperMaxLines: 3,
                                      errorMaxLines: 3,
                                      helperText: _pathConflict == true
                                          ? 'رقم المسار مستخدم بالفعل في هذا المربع.'
                                          : 'يجب أن يكون رقم المسار فريداً داخل المربع السكني.',
                                      helperStyle: TextStyle(
                                        color: _pathConflict == true
                                            ? Theme.of(
                                                context,
                                              ).colorScheme.error
                                            : null,
                                      ),
                                    ),
                                    onChanged: _schedulePathValidation,
                                    validator: (value) {
                                      final text = value?.trim() ?? '';
                                      if (text.isEmpty) return null;
                                      if (int.tryParse(
                                            CompoundSearchQuery.normalizeDigits(
                                              text,
                                            ),
                                          ) ==
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
                                  if (_selectedSubscriber?.squareName !=
                                      null) ...[
                                    const SizedBox(height: 6),
                                    Align(
                                      alignment: Alignment.centerRight,
                                      child: Text(
                                        'المربع: ${_selectedSubscriber!.squareName}',
                                      ),
                                    ),
                                  ],
                                  const SizedBox(height: 12),
                                  DropdownButtonFormField<String>(
                                    key: ValueKey<String>('status-$_status'),
                                    initialValue: _status,
                                    decoration: const InputDecoration(
                                      labelText: 'حالة العداد',
                                      prefixIcon: Icon(
                                        Icons.toggle_on_outlined,
                                      ),
                                    ),
                                    items: const [
                                      DropdownMenuItem(
                                        value: 'active',
                                        child: Text('نشط'),
                                      ),
                                      DropdownMenuItem(
                                        value: 'inactive',
                                        child: Text('غير نشط'),
                                      ),
                                    ],
                                    onChanged: (value) {
                                      if (value != null) {
                                        setState(() => _status = value);
                                      }
                                    },
                                  ),
                                ],
                              ),
                            ),
                            if (_showTenantFields) ...[
                              const SizedBox(height: 20),
                              const SectionHeading(
                                icon: Icons.person_pin_outlined,
                                title: 'بيانات المستأجر (اختيارية)',
                              ),
                              const SizedBox(height: 8),
                              AppCard(
                                child: Column(
                                  children: [
                                    TextFormField(
                                      controller: _tenantNameController,
                                      textInputAction: TextInputAction.next,
                                      decoration: const InputDecoration(
                                        labelText: 'اسم المستأجر',
                                        prefixIcon: Icon(
                                          Icons.person_pin_outlined,
                                        ),
                                      ),
                                      onChanged: (_) => setState(() {}),
                                    ),
                                    const SizedBox(height: 12),
                                    TextFormField(
                                      controller: _tenantPhoneController,
                                      textDirection: TextDirection.ltr,
                                      keyboardType: TextInputType.phone,
                                      decoration: const InputDecoration(
                                        labelText: 'هاتف المستأجر',
                                        prefixIcon: Icon(Icons.phone_outlined),
                                        hintText: '+967…',
                                      ),
                                      onChanged: (_) => setState(() {}),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                            const SizedBox(height: 24),
                            AppPrimaryButton(
                              label: _isSaving
                                  ? 'جارٍ الحفظ…'
                                  : 'حفظ بيانات العداد',
                              icon: Icons.save_outlined,
                              loading: _isSaving,
                              onPressed: _isSaving || _isCheckingPath
                                  ? null
                                  : () => _save(),
                            ),
                            if (widget.meter == null) ...[
                              const SizedBox(height: 10),
                              AppSecondaryButton(
                                label: 'حفظ وإضافة عداد آخر',
                                icon: Icons.playlist_add_outlined,
                                onPressed: _isSaving || _isCheckingPath
                                    ? null
                                    : () => _save(addAnother: true),
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

class _OwnerPicker extends StatefulWidget {
  const _OwnerPicker({required this.subscribers, required this.selectedId});
  final List<SubscriberOption> subscribers;
  final int? selectedId;

  @override
  State<_OwnerPicker> createState() => _OwnerPickerState();
}

class _OwnerPickerState extends State<_OwnerPicker> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final matches = widget.subscribers
        .where(
          (subscriber) => '${subscriber.name} ${subscriber.squareName ?? ''}'
              .toLowerCase()
              .contains(_query.trim().toLowerCase()),
        )
        .toList(growable: false);
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Padding(
        padding: EdgeInsets.only(
          bottom: MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: SizedBox(
          height: MediaQuery.sizeOf(context).height * .7,
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      FieldUxStrings.chooseOwner,
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      decoration: const InputDecoration(
                        labelText: FieldUxStrings.searchOwner,
                        prefixIcon: Icon(Icons.search_rounded),
                      ),
                      onChanged: (value) => setState(() => _query = value),
                    ),
                  ],
                ),
              ),
              Expanded(
                child: matches.isEmpty
                    ? const Center(child: Text(FieldUxStrings.noOwnerMatches))
                    : ListView.builder(
                        keyboardDismissBehavior:
                            ScrollViewKeyboardDismissBehavior.onDrag,
                        itemCount: matches.length,
                        itemBuilder: (context, index) {
                          final subscriber = matches[index];
                          return ListTile(
                            title: Text(subscriber.name),
                            subtitle: subscriber.squareName == null
                                ? null
                                : Text(subscriber.squareName!),
                            selected: subscriber.id == widget.selectedId,
                            trailing: subscriber.id == widget.selectedId
                                ? const Icon(Icons.check_circle_outline)
                                : null,
                            onTap: () => Navigator.of(context).pop(subscriber),
                          );
                        },
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
