import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:get_it/get_it.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/utils/compound_search_parser.dart';
import '../../../../core/widgets/empty_state_widget.dart';
import '../../../../core/widgets/info_notice.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
import '../../../../core/widgets/status_badge.dart';
import '../../../meters/data/models/meter.dart';
import '../../../offline_sync/services/field_data_sync_service.dart';
import '../../data/models/reading.dart';
import '../../data/reading_fault_log.dart';
import '../../data/services/reading_invoice_actions_service.dart';
import '../../domain/reading_fault.dart';
import '../../domain/readings_repository.dart';

part '../widgets/submit_reading_widgets.dart';

part '../widgets/submit_reading_fault_sheet.dart';

class SubmitReadingScreen extends StatefulWidget {
  const SubmitReadingScreen({
    required this.meter,
    required this.readingsRepository,
    this.invoiceActions,
    this.onNextPath,
    this.faultLog,
    this.fieldDataSync,
    super.key,
  });

  final Meter meter;
  final ReadingsRepository readingsRepository;
  final ReadingInvoiceActionsService? invoiceActions;
  final Future<Meter?> Function(Meter currentMeter)? onNextPath;

  /// سجل «تعذّر القراءة». يُمرَّر في الاختبارات، ويُستخدم الافتراضي على الجهاز.
  final ReadingFaultLog? faultLog;

  /// خدمة مزامنة الأعطال مع الخادم. تُمرَّر في الاختبارات، ويُستخدم المسجّل
  /// في الحاوية على الجهاز.
  final FieldDataSyncService? fieldDataSync;

  @override
  State<SubmitReadingScreen> createState() => _SubmitReadingScreenState();
}

class _SubmitReadingScreenState extends State<SubmitReadingScreen> {
  final _formKey = GlobalKey<FormState>();
  final _currentReadingController = TextEditingController();
  final _subscriptionInstallmentController = TextEditingController();
  final _tankerInstallmentController = TextEditingController();
  final _imagePicker = ImagePicker();

  late Meter _meter;
  late final ReadingFaultLog _faultLog =
      widget.faultLog ?? SharedPreferencesReadingFaultLog();
  late final FieldDataSyncService? _fieldDataSync = widget.fieldDataSync ?? _resolveSync();
  ReadingFault? _todayFault;
  MeterPeriodResponse? _period;
  ReadingSaveResult? _saveResult;
  DateTime _readingDate = DateTime.now();
  String? _imagePath;
  String? _errorMessage;
  bool _isLoadingPeriod = true;
  bool _isSaving = false;
  bool _includeSubscriptionFee = false;
  bool _includeTankerFee = false;
  bool _isRunningInvoiceAction = false;
  bool _isRecordingFault = false;

  double get _currentReading => double.tryParse(
        CompoundSearchQuery.normalizeDigits(_currentReadingController.text)
            .replaceAll(',', '.'),
      ) ??
      0;
  double get _consumption => _currentReading - (_period?.lastReading ?? _meter.lastReading);

  @override
  void initState() {
    super.initState();
    _meter = widget.meter;
    _loadPeriod();
    _loadTodaysFault();
  }

  /// رفع الأعطال المتراكمة للخادم بصمت — تُعاد المحاولة عند عودة الاتصال.
  Future<void> _syncFaultsQuietly() async {
    final sync = _fieldDataSync;
    if (sync == null) return;
    try {
      await sync.syncReadingFaults();
    } catch (_) {
      // الرفع غير الحرج — السجل المحلي هو المرجع حتى تتم المزامنة.
    }
  }

  FieldDataSyncService? _resolveSync() {
    try {
      return GetIt.I.isRegistered<FieldDataSyncService>()
          ? GetIt.I<FieldDataSyncService>()
          : null;
    } catch (_) {
      return null;
    }
  }

  /// هل سُجّل تعذّر قراءة لهذا العداد اليوم؟ يُعرض كتنبيه حتى لا يُنسى العداد.
  Future<void> _loadTodaysFault() async {
    final now = DateTime.now();
    try {
      final faults = await _faultLog.forDay(now);
      if (!mounted) return;
      final mine = faults.where((fault) => fault.meterId == _meter.id).toList();
      setState(() => _todayFault = mine.isEmpty ? null : mine.first);
    } catch (_) {
      // السجل المحلي لا يجوز أن يمنع تسجيل قراءة.
      if (mounted) setState(() => _todayFault = null);
    }
  }

  /// تسجيل تعذّر القراءة: نطلب السبب، ونحفظ محلياً، ثم ننتقل للعداد التالي.
  Future<void> _recordFault() async {
    if (_isSaving || _isRecordingFault) return;
    final draft = await showModalBottomSheet<_FaultDraft>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _FaultSheet(
        meterCode: _meter.uniqueCode,
        subscriberName: _meter.subscriberName,
      ),
    );
    if (draft == null || !mounted) return;
    setState(() => _isRecordingFault = true);
    try {
      final fault = ReadingFault(
        meterId: _meter.id,
        meterCode: _meter.uniqueCode,
        subscriberName: _meter.subscriberName,
        squareId: _meter.squareId,
        reason: draft.reason,
        note: draft.note,
        recordedAt: DateTime.now(),
      );
      await _faultLog.record(fault);
      if (!mounted) return;
      setState(() {
        _todayFault = fault;
        _isRecordingFault = false;
      });
      _showMessage('سُجّل تعذّر القراءة — سيُرفع للخادم ويظهر العداد للمشرف كمتأخر.');
      // الرفع الفوري للخادم؛ عند الفشل (أوفلاين) يُعاد المحاولة تلقائياً
      // عند عودة الاتصال عبر FieldDataSyncService.
      unawaited(_syncFaultsQuietly());
    } catch (error) {
      if (!mounted) return;
      setState(() => _isRecordingFault = false);
      _showMessage('تعذر حفظ تعذّر القراءة على الجهاز: $error');
    }
  }

  Future<void> _loadPeriod() async {
    setState(() {
      _isLoadingPeriod = true;
      _errorMessage = null;
      _period = null;
      _saveResult = null;
      _imagePath = null;
      _currentReadingController.clear();
    });
    try {
      final period = await widget.readingsRepository.getMeterPeriod(_meter);
      if (!mounted) return;
      setState(() {
        _period = period;
        _includeSubscriptionFee = period.hasSubscriptionFee;
        _includeTankerFee = period.hasTankerFee;
        _subscriptionInstallmentController.text =
            period.subscriptionInstallment > 0 ? _formatNumber(period.subscriptionInstallment) : '0';
        _tankerInstallmentController.text =
            period.tankerInstallment > 0 ? _formatNumber(period.tankerInstallment) : '0';
        _isLoadingPeriod = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _errorMessage = _humanMessage(error);
        _isLoadingPeriod = false;
      });
    }
  }

  Future<void> _chooseImage() async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (context) => Directionality(
        textDirection: TextDirection.rtl,
        child: SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              ListTile(
                leading: const Icon(Icons.camera_alt_outlined),
                title: const Text('التقاط صورة بالكاميرا'),
                onTap: () => Navigator.pop(context, ImageSource.camera),
              ),
              ListTile(
                leading: const Icon(Icons.photo_library_outlined),
                title: const Text('اختيار صورة من المعرض'),
                onTap: () => Navigator.pop(context, ImageSource.gallery),
              ),
            ],
          ),
        ),
      ),
    );
    if (source == null) return;

    try {
      final photo = await _imagePicker.pickImage(
        source: source,
        imageQuality: 85,
        maxWidth: 2200,
      );
      if (photo == null || !mounted) return;
      final supportDirectory = await getApplicationSupportDirectory();
      final directory = Directory(
        '${supportDirectory.path}${Platform.pathSeparator}reading_images',
      );
      await directory.create(recursive: true);
      final outputPath = '${directory.path}${Platform.pathSeparator}'
          'reading_${_meter.id}_${DateTime.now().microsecondsSinceEpoch}.jpg';
      final compressed = await FlutterImageCompress.compressAndGetFile(
        photo.path,
        outputPath,
        minWidth: 1280,
        minHeight: 960,
        quality: 75,
        format: CompressFormat.jpeg,
      );
      final durableImage = compressed == null
          ? await File(photo.path).copy(outputPath)
          : File(compressed.path);
      if (!mounted) return;
      setState(() => _imagePath = durableImage.path);
    } catch (error) {
      if (mounted) _showMessage('تعذر التقاط أو ضغط الصورة: ${_humanMessage(error)}');
    }
  }

  Future<void> _selectReadingDate() async {
    final selected = await showDatePicker(
      context: context,
      initialDate: _readingDate,
      firstDate: DateTime(2000),
      lastDate: DateTime.now().add(const Duration(days: 1)),
      helpText: 'اختر تاريخ القراءة الفعلي',
    );
    if (selected != null) setState(() => _readingDate = selected);
  }

  Future<void> _save() async {
    if (_isSaving || _period == null || !_formKey.currentState!.validate()) return;
    final previousReading = _period!.lastReading;
    final currentReading = _currentReading;
    if (currentReading < previousReading) {
      _showMessage('القراءة الحالية يجب أن تكون أكبر من أو تساوي القراءة السابقة ($previousReading).');
      return;
    }

    final parsedSubInstallment = double.tryParse(
          CompoundSearchQuery.normalizeDigits(_subscriptionInstallmentController.text.trim())
              .replaceAll(',', '.'),
        ) ??
        _period!.subscriptionInstallment;
    final parsedTankerInstallment = double.tryParse(
          CompoundSearchQuery.normalizeDigits(_tankerInstallmentController.text.trim())
              .replaceAll(',', '.'),
        ) ??
        _period!.tankerInstallment;

    setState(() {
      _isSaving = true;
      _errorMessage = null;
    });
    try {
      final result = await widget.readingsRepository.submitReading(
        ReadingSubmission(
          meterId: _meter.id,
          readingDate: _readingDate,
          periodStart: _period!.periodStart,
          periodEnd: _period!.periodEnd,
          previousReading: previousReading,
          currentReading: currentReading,
          imagePath: _imagePath,
          autoIssueInvoice: _period!.autoIssueInvoice,
          hasSubscriptionFee: _period!.autoIssueInvoice && _includeSubscriptionFee,
          subscriptionInstallment:
              _period!.autoIssueInvoice && _includeSubscriptionFee
                  ? parsedSubInstallment
                  : 0,
          hasTankerFee: _period!.autoIssueInvoice && _includeTankerFee,
          tankerInstallment: _period!.autoIssueInvoice && _includeTankerFee
              ? parsedTankerInstallment
              : 0,
        ),
      );
      if (!mounted) return;
      setState(() {
        _saveResult = result;
        _isSaving = false;
      });
      _showMessage(result.message ??
          (result.isPendingSync ? 'تم حفظ القراءة أوفلاين.' : 'تم حفظ القراءة بنجاح.'));
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _errorMessage = _humanMessage(error);
        _isSaving = false;
      });
    }
  }

  Future<void> _nextPath() async {
    final nextPath = widget.onNextPath;
    if (nextPath == null) return;
    try {
      final nextMeter = await nextPath(_meter);
      if (!mounted) return;
      if (nextMeter == null) {
        _showMessage('لا يوجد عداد تالٍ في هذا المربع.');
        return;
      }
      setState(() {
        _meter = nextMeter;
        _readingDate = DateTime.now();
        _todayFault = null;
      });
      await _loadPeriod();
      await _loadTodaysFault();
    } catch (error) {
      if (mounted) _showMessage(_humanMessage(error));
    }
  }

  Future<void> _runInvoiceAction(Future<void> Function(InvoiceSummary) action) async {
    final invoice = _saveResult?.invoice;
    if (invoice == null) return;
    final service = widget.invoiceActions;
    if (service == null) {
      _showMessage('خدمة الطباعة والواتساب ستُربط من وحدة الفواتير.');
      return;
    }
    setState(() => _isRunningInvoiceAction = true);
    try {
      await action(invoice);
    } catch (error) {
      if (mounted) _showMessage(_humanMessage(error));
    } finally {
      if (mounted) setState(() => _isRunningInvoiceAction = false);
    }
  }

  String _humanMessage(Object error) {
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
    return 'تعذر تنفيذ العملية. تحقق من البيانات أو الاتصال بالخادم.';
  }

  void _showMessage(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  void dispose() {
    _currentReadingController.dispose();
    _subscriptionInstallmentController.dispose();
    _tankerInstallmentController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(
          appBar: AppBar(
            title: Text(_saveResult == null ? 'تسجيل قراءة' : 'نتيجة القراءة'),
            actions: [
              if (_saveResult != null)
                IconButton(
                  tooltip: 'إدخال قراءة جديدة',
                  onPressed: _loadPeriod,
                  icon: const Icon(Icons.add_chart_outlined),
                ),
            ],
          ),
          body: _isLoadingPeriod
              ? const Center(child: CircularProgressIndicator())
              : _period == null
                  ? _loadErrorView()
                  : _saveResult == null
                      ? _readingForm()
                      : _savedView(),
        ),
      );

  Widget _readingForm() {
    final period = _period!;
    return LayoutBuilder(
      builder: (context, constraints) {
        final maxWidth = constraints.maxWidth >= 840 ? 740.0 : double.infinity;
        return Center(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: maxWidth),
            child: Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  _meterHeader(),
                  if (_todayFault != null) ...[
                    const SizedBox(height: 10),
                    _NoticeBanner(
                      text: 'تعذّرت قراءة هذا العداد اليوم: ${_todayFault!.reason.label}.'
                          '${_todayFault!.note == null ? '' : ' (${_todayFault!.note})'}',
                      color: AppColors.warning,
                      icon: Icons.report_gmailerrorred_outlined,
                    ),
                  ],
                  if (period.isOfflineEstimate) ...[
                    const SizedBox(height: 10),
                    const _NoticeBanner(
                      text: 'تعمل دون اتصال — تم حساب الفترة من البيانات المحلية.',
                      color: AppColors.info,
                      icon: Icons.cloud_off_outlined,
                    ),
                  ],
                  const SizedBox(height: 14),
                  _periodCard(period),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(child: _readingValueCard('القراءة السابقة', period.lastReading)),
                      const SizedBox(width: 10),
                      Expanded(
                        child: _readingValueCard(
                          'الاستهلاك الحالي',
                          _consumption < 0 ? 0 : _consumption,
                          accent: true,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  InkWell(
                    onTap: _selectReadingDate,
                    borderRadius: BorderRadius.circular(AppRadius.md),
                    child: InputDecorator(
                      decoration: const InputDecoration(
                        labelText: 'تاريخ القراءة الفعلي',
                        prefixIcon: Icon(Icons.calendar_month_outlined),
                        suffixIcon: Icon(Icons.expand_more_rounded, size: 20),
                      ),
                      child: Text(_formatDate(_readingDate)),
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _currentReadingController,
                    autofocus: true,
                    textDirection: TextDirection.ltr,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(
                      labelText: 'القراءة الحالية',
                      hintText: 'أدخل الرقم الظاهر على العداد',
                      prefixIcon: Icon(Icons.speed_outlined),
                      suffixText: 'وحدة',
                    ),
                    onChanged: (_) => setState(() {}),
                    validator: (value) {
                      final current = double.tryParse(
                        CompoundSearchQuery.normalizeDigits(value ?? '').replaceAll(',', '.'),
                      );
                      if (current == null) return 'أدخل قراءة رقمية صحيحة.';
                      if (current < period.lastReading) {
                        return 'القراءة لا يمكن أن تقل عن السابقة (${period.lastReading}).';
                      }
                      return null;
                    },
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'الاستهلاك المتوقع: ${_consumption < 0 ? 0 : _consumption} وحدة',
                    textDirection: TextDirection.rtl,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          color: Theme.of(context).colorScheme.primary,
                          fontWeight: FontWeight.w600,
                        ),
                  ),
                  if (period.autoIssueInvoice) ...[
                    const SizedBox(height: 18),
                    const _NoticeBanner(
                      text: 'تنبيه الفوترة التلقائية: سيصدر النظام فاتورة فور حفظ هذه القراءة.',
                      color: AppColors.warning,
                      icon: Icons.warning_amber_rounded,
                    ),
                    const SizedBox(height: 8),
                    if (period.hasSubscriptionFee)
                      _feeOption(
                        title: 'إضافة قسط رسوم الاشتراك',
                        maxValue: period.subscriptionInstallment,
                        controller: _subscriptionInstallmentController,
                        selected: _includeSubscriptionFee,
                        onChanged: (value) => setState(() => _includeSubscriptionFee = value),
                      ),
                    if (period.hasTankerFee)
                      _feeOption(
                        title: 'إضافة رسوم الوايت',
                        maxValue: period.tankerInstallment,
                        controller: _tankerInstallmentController,
                        selected: _includeTankerFee,
                        onChanged: (value) => setState(() => _includeTankerFee = value),
                      ),
                  ],
                  const SizedBox(height: 18),
                  _imageControl(),
                  if (_errorMessage != null) ...[
                    const SizedBox(height: 12),
                    Text(
                      _errorMessage!,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: Theme.of(context).colorScheme.error,
                          ),
                    ),
                  ],
                  const SizedBox(height: 24),
                  SizedBox(
                    height: 50,
                    child: FilledButton.icon(
                      onPressed: _isSaving ? null : _save,
                      icon: _isSaving
                          ? const SizedBox.square(
                              dimension: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.check_rounded, size: 20),
                      label: Text(_isSaving ? 'جارٍ حفظ القراءة…' : 'حفظ القراءة'),
                    ),
                  ),
                  const SizedBox(height: 10),
                  TextButton.icon(
                    onPressed: (_isSaving || _isRecordingFault) ? null : _recordFault,
                    icon: const Icon(Icons.report_gmailerrorred_outlined, size: 20),
                    label: Text(
                      _isRecordingFault ? 'جارٍ حفظ التعذّر…' : 'تعذّر قراءة العداد (عطل/إغلاق)',
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'تُسجَّل القراءة المتعذرة على الجهاز فقط ولا تُرسل كقراءة،'
                    ' حتى لا يُحتسب استهلاك غير حقيقي.',
                    textDirection: TextDirection.rtl,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: Theme.of(context).colorScheme.onSurfaceVariant,
                        ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _meterHeader() {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return AppCard(
      child: Row(
        children: <Widget>[
          const AppGlyph(icon: Icons.speed_rounded, size: 44),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  _meter.subscriberName ?? 'مشترك',
                  style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 3),
                Row(
                  children: <Widget>[
                    LtrNumberText(
                      _meter.uniqueCode,
                      style: theme.textTheme.bodySmall?.copyWith(
                        fontWeight: FontWeight.w500,
                        color: scheme.onSurface,
                      ),
                    ),
                    if (_meter.pathNumber != null) ...<Widget>[
                      const SizedBox(width: 6),
                      Text('· مسار ${_meter.pathNumber}', style: theme.textTheme.labelSmall),
                    ],
                  ],
                ),
                if (_meter.tenantName?.isNotEmpty == true)
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Text('المستأجر: ${_meter.tenantName}', style: theme.textTheme.labelSmall),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _periodCard(MeterPeriodResponse period) {
    final theme = Theme.of(context);
    return AppCard(
      child: Row(
        children: <Widget>[
          const AppGlyph(icon: Icons.date_range_rounded, size: 38, neutral: true),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text('فترة الاستهلاك', style: theme.textTheme.labelSmall),
                const SizedBox(height: 2),
                LtrNumberText(
                  '${_formatDate(period.periodStart)}  —  ${_formatDate(period.periodEnd)}',
                  style: theme.textTheme.bodySmall?.copyWith(
                    fontWeight: FontWeight.w500,
                    color: theme.colorScheme.onSurface,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _readingValueCard(String title, double value, {bool accent = false}) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(13, 11, 13, 12),
      color: accent ? AppTint.soft(scheme.primary, context) : null,
      borderColor: accent ? scheme.primary.withValues(alpha: .18) : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            title,
            style: theme.textTheme.labelSmall?.copyWith(
              color: accent ? scheme.primary : scheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 4),
          LtrNumberText(
            _formatNumber(value),
            style: TextStyle(
              fontSize: 22,
              height: 1.15,
              fontWeight: FontWeight.w600,
              letterSpacing: -.4,
              color: accent ? scheme.primary : scheme.onSurface,
              fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }

  Widget _feeOption({
    required String title,
    required double maxValue,
    required TextEditingController controller,
    required bool selected,
    required ValueChanged<bool> onChanged,
  }) =>
      AppCard(
        padding: EdgeInsets.zero,
        child: Column(
          children: <Widget>[
            SwitchListTile.adaptive(
              value: selected,
              onChanged: onChanged,
              contentPadding: const EdgeInsetsDirectional.fromSTEB(14, 2, 8, 2),
              title: Text(
                title,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w500),
              ),
              subtitle: Text(
                'إجمالي المتبقي: ${_formatNumber(maxValue)} ريال',
                style: Theme.of(context).textTheme.labelSmall,
              ),
              secondary: const AppGlyph(
                icon: Icons.receipt_long_rounded,
                size: 36,
                neutral: true,
              ),
            ),
            if (selected)
              Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(14, 0, 14, 14),
                child: TextFormField(
                  controller: controller,
                  textDirection: TextDirection.ltr,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: InputDecoration(
                    labelText: 'مبلغ القسط المطلوب إضافته للفاتورة',
                    suffixText: 'ريال',
                    helperText:
                        'يمكنك تعديل المبلغ لتحصيل قسط جزئي (الحد الأعلى ${_formatNumber(maxValue)} ريال)',
                    isDense: true,
                  ),
                  validator: (val) {
                    if (!selected) return null;
                    final parsed = double.tryParse(
                      CompoundSearchQuery.normalizeDigits(val ?? '').replaceAll(',', '.'),
                    );
                    if (parsed == null || parsed < 0) {
                      return 'أدخل مبلغاً رقمياً صالحاً.';
                    }
                    if (maxValue > 0 && parsed > maxValue) {
                      return 'لا يمكن تجاوز إجمالي المتبقي (${_formatNumber(maxValue)}).';
                    }
                    return null;
                  },
                ),
              ),
          ],
        ),
      );

  Widget _imageControl() {
    final path = _imagePath;
    final scheme = Theme.of(context).colorScheme;
    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(12, 10, 8, 10),
      child: Row(
        children: <Widget>[
          if (path == null)
            const AppGlyph(icon: Icons.photo_camera_outlined, size: 42, neutral: true)
          else
            ClipRRect(
              borderRadius: BorderRadius.circular(AppRadius.md),
              child: Image.file(File(path), width: 44, height: 44, fit: BoxFit.cover),
            ),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  path == null ? 'صورة العداد' : 'صورة مرفقة',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w500,
                      ),
                ),
                Text(
                  path == null ? 'اختيارية — تساعد على المراجعة' : 'تم ضغط الصورة تلقائياً',
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              ],
            ),
          ),
          IconButton(
            tooltip: path == null ? 'التقاط أو اختيار صورة' : 'استبدال الصورة',
            onPressed: _chooseImage,
            icon: Icon(
              path == null ? Icons.add_a_photo_outlined : Icons.sync_rounded,
              size: 19,
              color: scheme.onSurfaceVariant,
            ),
          ),
          if (path != null)
            IconButton(
              tooltip: 'إزالة الصورة',
              onPressed: () => setState(() => _imagePath = null),
              icon: Icon(Icons.close_rounded, size: 19, color: scheme.onSurfaceVariant),
            ),
        ],
      ),
    );
  }

  Widget _savedView() {
    final result = _saveResult!;
    final invoice = result.invoice;
    return LayoutBuilder(
      builder: (context, constraints) {
        final maxWidth = constraints.maxWidth >= 840 ? 740.0 : double.infinity;
        return Center(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: maxWidth),
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                AppCard(
                  color: AppTint.soft(
                    result.isPendingSync ? AppColors.warning : AppColors.success,
                    context,
                  ),
                  borderColor: (result.isPendingSync ? AppColors.warning : AppColors.success)
                      .withValues(alpha: .22),
                  child: Row(
                    children: <Widget>[
                      AppGlyph(
                        icon: result.isPendingSync
                            ? Icons.cloud_upload_outlined
                            : Icons.check_rounded,
                        tone: result.isPendingSync ? AppColors.warning : AppColors.success,
                        size: 42,
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            Text(
                              result.isPendingSync ? 'بانتظار المزامنة' : 'تم حفظ القراءة',
                              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                                    fontWeight: FontWeight.w600,
                                  ),
                            ),
                            if (result.message != null)
                              Padding(
                                padding: const EdgeInsets.only(top: 2),
                                child: Text(
                                  result.message!,
                                  style: Theme.of(context).textTheme.bodySmall,
                                ),
                              ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                if (invoice != null && result.invoiceIssued) ...[
                  const SizedBox(height: 12),
                  _invoiceSummary(invoice),
                ],
                const SizedBox(height: 12),
                AppCard(
                  child: Column(
                    children: [
                      _summaryRow('العداد', _meter.uniqueCode, ltr: true),
                        _summaryRow('القراءة السابقة', _formatNumber(result.reading.previousReading), ltr: true),
                        _summaryRow('القراءة الحالية', _formatNumber(result.reading.currentReading), ltr: true),
                        _summaryRow('الاستهلاك', '${_formatNumber(result.reading.consumptionUnits)} وحدة', ltr: true),
                      _summaryRow(
                        'الفترة',
                        '${_formatDate(result.reading.periodStart)} — ${_formatDate(result.reading.periodEnd)}',
                        ltr: true,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
                if (invoice != null && result.invoiceIssued) ...[
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      OutlinedButton.icon(
                        onPressed: _isRunningInvoiceAction
                            ? null
                            : () => _runInvoiceAction((item) => widget.invoiceActions!.printInvoice(item)),
                        icon: const Icon(Icons.print_outlined),
                        label: const Text('طباعة الفاتورة'),
                      ),
                      OutlinedButton.icon(
                        onPressed: _isRunningInvoiceAction
                            ? null
                            : () => _runInvoiceAction((item) => widget.invoiceActions!.shareOnWhatsApp(item)),
                        icon: const Icon(Icons.chat_outlined),
                        label: const Text('واتساب'),
                      ),
                    ],
                  ),
                  if (_isRunningInvoiceAction) const ShimmerSkeleton(height: 4),
                ],
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: _loadPeriod,
                        icon: const Icon(Icons.add_chart_outlined),
                        label: const Text('قراءة جديدة'),
                      ),
                    ),
                    if (widget.onNextPath != null) ...[
                      const SizedBox(width: 10),
                      Expanded(
                        child: FilledButton.icon(
                          onPressed: _nextPath,
                          icon: const Icon(Icons.skip_next_outlined),
                          label: const Text('المسار التالي'),
                        ),
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _invoiceSummary(InvoiceSummary invoice) => AppCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                AppGlyph(
                  icon: Icons.receipt_long_rounded,
                  tone: Theme.of(context).colorScheme.primary,
                  size: 34,
                ),
                const SizedBox(width: 9),
                Expanded(
                  child: Text(
                    'الفاتورة الصادرة ${invoice.billNumber == null ? '' : '• ${invoice.billNumber}'}',
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Divider(height: 18, color: Theme.of(context).colorScheme.outlineVariant),
            _summaryRow('المشترك', invoice.subscriberName ?? _meter.subscriberName ?? '—'),
            _summaryRow('كود العداد', invoice.meterCode ?? _meter.uniqueCode, ltr: true),
            _summaryRow(
              'الاستهلاك',
              '${_formatNumber(invoice.consumptionUnits ?? _saveResult!.reading.consumptionUnits)} وحدة',
            ),
            _summaryRow(
              'إجمالي المطلوب',
              '${_formatNumber(invoice.totalAmount)} ريال',
              emphasis: true,
            ),
          ],
        ),
      );

  Widget _summaryRow(String label, String value, {bool ltr = false, bool emphasis = false}) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final style = TextStyle(
      fontSize: emphasis ? 14 : 12.5,
      height: 1.4,
      fontWeight: emphasis ? FontWeight.w600 : FontWeight.w500,
      color: emphasis ? scheme.onSurface : scheme.onSurfaceVariant,
      fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
    );
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: <Widget>[
          Expanded(child: Text(label, style: theme.textTheme.bodySmall)),
          if (ltr)
            Directionality(textDirection: TextDirection.ltr, child: Text(value, style: style))
          else
            Text(value, style: style),
        ],
      ),
    );
  }

  Widget _loadErrorView() => EmptyStateWidget(
        icon: Icons.error_outline_rounded,
        title: 'تعذر جلب فترة القراءة',
        message: _errorMessage ?? 'تحقق من الاتصال ثم أعد المحاولة.',
        action: TextButton.icon(
          onPressed: _loadPeriod,
          icon: const Icon(Icons.refresh_rounded, size: 18),
          label: const Text('إعادة المحاولة'),
        ),
      );

  static String _formatDate(DateTime value) =>
      '${value.year.toString().padLeft(4, '0')}-'
      '${value.month.toString().padLeft(2, '0')}-'
      '${value.day.toString().padLeft(2, '0')}';

  static String _formatNumber(num value) =>
      value.toStringAsFixed(value % 1 == 0 ? 0 : 2);
}

