import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:get_it/get_it.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';

import '../../../../core/l10n/app_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
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
  late final FieldDataSyncService? _fieldDataSync =
      widget.fieldDataSync ?? _resolveSync();
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

  double get _currentReading =>
      double.tryParse(
        CompoundSearchQuery.normalizeDigits(
          _currentReadingController.text,
        ).replaceAll(',', '.'),
      ) ??
      0;
  double get _consumption =>
      _currentReading - (_period?.lastReading ?? _meter.lastReading);

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
      _showMessage(
        'سُجّل تعذّر القراءة — سيُرفع للخادم ويظهر العداد للمشرف كمتأخر.',
      );
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
            period.subscriptionInstallment > 0
            ? _formatNumber(period.subscriptionInstallment)
            : '0';
        _tankerInstallmentController.text = period.tankerInstallment > 0
            ? _formatNumber(period.tankerInstallment)
            : '0';
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
          child: Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 36,
                    height: 4,
                    decoration: BoxDecoration(
                      color: Theme.of(context).dividerColor,
                      borderRadius: BorderRadius.circular(AppRadius.pill),
                    ),
                  ),
                ),
                const SizedBox(height: 10),
                _imageSourceRow(
                  icon: Icons.camera_alt_outlined,
                  label: 'التقاط صورة بالكاميرا',
                  onTap: () => Navigator.pop(context, ImageSource.camera),
                ),
                const AppDivider(indent: 8, endIndent: 8),
                _imageSourceRow(
                  icon: Icons.photo_library_outlined,
                  label: 'اختيار صورة من المعرض',
                  onTap: () => Navigator.pop(context, ImageSource.gallery),
                ),
              ],
            ),
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
      final outputPath =
          '${directory.path}${Platform.pathSeparator}'
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
      if (mounted) {
        _showMessage('تعذر التقاط أو ضغط الصورة: ${_humanMessage(error)}');
      }
    }
  }

  /// صف اختيار مصدر الصورة داخل ورقة الإرفاق.
  Widget _imageSourceRow({
    required IconData icon,
    required String label,
    required VoidCallback onTap,
  }) {
    final theme = Theme.of(context);
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 12),
        child: Row(
          children: <Widget>[
            AppGlyph(icon: icon, size: 38),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                label,
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w500,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _save() async {
    if (_isSaving || _period == null || !_formKey.currentState!.validate()) {
      return;
    }
    final previousReading = _period!.lastReading;
    final currentReading = _currentReading;
    if (currentReading < previousReading) {
      _showMessage(
        'القراءة الحالية يجب أن تكون أكبر من أو تساوي القراءة السابقة ($previousReading).',
      );
      return;
    }

    final parsedSubInstallment =
        double.tryParse(
          CompoundSearchQuery.normalizeDigits(
            _subscriptionInstallmentController.text.trim(),
          ).replaceAll(',', '.'),
        ) ??
        _period!.subscriptionInstallment;
    final parsedTankerInstallment =
        double.tryParse(
          CompoundSearchQuery.normalizeDigits(
            _tankerInstallmentController.text.trim(),
          ).replaceAll(',', '.'),
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
          hasSubscriptionFee:
              _period!.autoIssueInvoice && _includeSubscriptionFee,
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
      _showMessage(
        result.message ??
            (result.isPendingSync
                ? 'تم حفظ القراءة أوفلاين.'
                : 'تم حفظ القراءة بنجاح.'),
      );
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _errorMessage = _humanMessage(error);
        _isSaving = false;
      });
    }
  }

  Future<void> _chooseReadingDate() async {
    if (_isSaving || _period == null) return;
    final selected = await showDatePicker(
      context: context,
      initialDate: DateUtils.dateOnly(_readingDate),
      firstDate: DateTime(1900),
      lastDate: DateTime(2100, 12, 31),
      helpText: AppStrings.of(context).readingActualDateChange,
    );
    if (selected == null || !mounted || _isSaving) return;
    // The actual observation date is independent of the approved billing
    // period. Do not recalculate periodStart/periodEnd when it changes.
    setState(() => _readingDate = selected);
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

  Future<void> _runInvoiceAction(
    Future<void> Function(InvoiceSummary) action,
  ) async {
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
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
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
    child: SubPageScaffold(
      title: _saveResult == null ? 'تسجيل قراءة' : 'نتيجة القراءة',
      actions: [
        if (_saveResult != null)
          AppIconButton(
            tooltip: 'إدخال قراءة جديدة',
            onPressed: () {
              _readingDate = DateTime.now();
              _loadPeriod();
            },
            icon: Icons.add_chart_outlined,
            size: 38,
          ),
      ],
      child: _isLoadingPeriod
          ? const Center(child: AppLoader(label: 'جارٍ تحميل فترة القراءة…'))
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
                      text:
                          'تعذّرت قراءة هذا العداد اليوم: ${_todayFault!.reason.label}.'
                          '${_todayFault!.note == null ? '' : ' (${_todayFault!.note})'}',
                      color: AppColors.warning,
                      icon: Icons.report_gmailerrorred_outlined,
                    ),
                  ],
                  if (period.isOfflineEstimate) ...[
                    const SizedBox(height: 10),
                    const _NoticeBanner(
                      text:
                          'تعمل دون اتصال — تم حساب الفترة من البيانات المحلية.',
                      color: AppColors.info,
                      icon: Icons.cloud_off_outlined,
                    ),
                  ],
                  const SizedBox(height: 16),
                  const SectionHeading(
                    icon: Icons.speed_rounded,
                    title: 'بيانات القراءة',
                  ),
                  const SizedBox(height: 8),
                  _periodCard(period),
                  const SizedBox(height: 10),
                  _readingDateControl(),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: _readingValueCard(
                          'القراءة السابقة',
                          period.lastReading,
                        ),
                      ),
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
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _currentReadingController,
                    autofocus: true,
                    textDirection: TextDirection.ltr,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    style: const TextStyle(
                      fontSize: 19,
                      fontWeight: FontWeight.w600,
                      letterSpacing: .3,
                      fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
                    ),
                    decoration: const InputDecoration(
                      labelText: 'القراءة الحالية',
                      hintText: 'أدخل الرقم الظاهر على العداد',
                      prefixIcon: Icon(Icons.speed_outlined),
                      suffixText: 'وحدة',
                      contentPadding: EdgeInsetsDirectional.fromSTEB(
                        14,
                        19,
                        14,
                        19,
                      ),
                    ),
                    onChanged: (_) => setState(() {}),
                    validator: (value) {
                      final current = double.tryParse(
                        CompoundSearchQuery.normalizeDigits(
                          value ?? '',
                        ).replaceAll(',', '.'),
                      );
                      if (current == null) return 'أدخل قراءة رقمية صحيحة.';
                      if (current < period.lastReading) {
                        return 'القراءة لا يمكن أن تقل عن السابقة (${period.lastReading}).';
                      }
                      return null;
                    },
                  ),
                  const SizedBox(height: 10),
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 6,
                      ),
                      decoration: BoxDecoration(
                        color: AppTint.soft(
                          Theme.of(context).colorScheme.primary,
                          context,
                        ),
                        borderRadius: BorderRadius.circular(AppRadius.pill),
                      ),
                      child: Text(
                        'الاستهلاك المتوقع: ${_consumption < 0 ? 0 : _consumption} وحدة',
                        textDirection: TextDirection.rtl,
                        style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          color: Theme.of(context).colorScheme.primary,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ),
                  if (period.autoIssueInvoice) ...[
                    const SizedBox(height: 20),
                    const SectionHeading(
                      icon: Icons.receipt_long_rounded,
                      title: 'الفوترة والأقساط',
                    ),
                    const SizedBox(height: 8),
                    const _NoticeBanner(
                      text:
                          'تنبيه الفوترة التلقائية: سيصدر النظام فاتورة فور حفظ هذه القراءة.',
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
                        onChanged: (value) =>
                            setState(() => _includeSubscriptionFee = value),
                      ),
                    if (period.hasTankerFee)
                      _feeOption(
                        title: 'إضافة رسوم الوايت',
                        maxValue: period.tankerInstallment,
                        controller: _tankerInstallmentController,
                        selected: _includeTankerFee,
                        onChanged: (value) =>
                            setState(() => _includeTankerFee = value),
                      ),
                  ],
                  const SizedBox(height: 14),
                  _imageControl(),
                  if (_errorMessage != null) ...[
                    const SizedBox(height: 12),
                    _NoticeBanner(
                      text: _errorMessage!,
                      color: Theme.of(context).colorScheme.error,
                      icon: Icons.error_outline_rounded,
                    ),
                  ],
                  const SizedBox(height: 22),
                  AppPrimaryButton(
                    label: _isSaving ? 'جارٍ حفظ القراءة…' : 'حفظ القراءة',
                    icon: Icons.check_rounded,
                    loading: _isSaving,
                    onPressed: _isSaving ? null : _save,
                  ),
                  const SizedBox(height: 10),
                  _faultEntryRow(),
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
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
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
                      Text(
                        '· مسار ${_meter.pathNumber}',
                        style: theme.textTheme.labelSmall,
                      ),
                    ],
                  ],
                ),
                if (_meter.tenantName?.isNotEmpty == true)
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Text(
                      'المستأجر: ${_meter.tenantName}',
                      style: theme.textTheme.labelSmall,
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// نقطة دخول «تعذّر القراءة» كصف هادئ: أيقونة + عنوان + سطر توضيحي.
  Widget _faultEntryRow() {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(12, 8, 8, 8),
      onTap: (_isSaving || _isRecordingFault) ? null : _recordFault,
      child: Row(
        children: <Widget>[
          AppGlyph(
            icon: Icons.report_gmailerrorred_outlined,
            tone: scheme.error,
            size: 38,
          ),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  _isRecordingFault
                      ? 'جارٍ حفظ التعذّر…'
                      : 'تعذّر قراءة العداد (عطل/إغلاق)',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  'تُسجَّل القراءة المتعذرة على الجهاز فقط ولا تُرسل كقراءة،'
                  ' حتى لا يُحتسب استهلاك غير حقيقي.',
                  style: theme.textTheme.labelSmall,
                ),
              ],
            ),
          ),
          const SizedBox(width: 4),
          Icon(
            Icons.chevron_left_rounded,
            size: 18,
            color: scheme.onSurfaceVariant.withValues(alpha: .7),
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
          const AppGlyph(
            icon: Icons.date_range_rounded,
            size: 38,
            neutral: true,
          ),
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

  Widget _readingDateControl() {
    final strings = AppStrings.of(context);
    return Semantics(
      button: true,
      label: strings.readingActualDateChange,
      child: InkWell(
        key: const ValueKey('actual-reading-date'),
        onTap: _isSaving ? null : _chooseReadingDate,
        borderRadius: BorderRadius.circular(AppRadius.md),
        child: InputDecorator(
          decoration: InputDecoration(
            labelText: strings.readingActualDateLabel,
            helperText: strings.readingActualDateHelp,
            helperMaxLines: 3,
            enabled: !_isSaving,
            prefixIcon: const Icon(Icons.calendar_month_outlined),
            suffixIcon: const Icon(Icons.edit_calendar_outlined),
          ),
          child: LtrNumberText(_formatDate(_readingDate)),
        ),
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
  }) => AppCard(
    padding: EdgeInsets.zero,
    child: Column(
      children: <Widget>[
        InkWell(
          onTap: () => onChanged(!selected),
          borderRadius: const BorderRadius.vertical(
            top: Radius.circular(AppRadius.xl),
          ),
          child: Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(14, 10, 14, 10),
            child: Row(
              children: <Widget>[
                const AppGlyph(
                  icon: Icons.receipt_long_rounded,
                  size: 36,
                  neutral: true,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(
                        title,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        'إجمالي المتبقي: ${_formatNumber(maxValue)} ريال',
                        style: Theme.of(context).textTheme.labelSmall,
                      ),
                    ],
                  ),
                ),
                Switch.adaptive(value: selected, onChanged: onChanged),
              ],
            ),
          ),
        ),
        if (selected)
          Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(14, 0, 14, 14),
            child: TextFormField(
              controller: controller,
              textDirection: TextDirection.ltr,
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
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
                  CompoundSearchQuery.normalizeDigits(
                    val ?? '',
                  ).replaceAll(',', '.'),
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
    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(12, 10, 8, 10),
      child: Row(
        children: <Widget>[
          if (path == null)
            const AppGlyph(
              icon: Icons.photo_camera_outlined,
              size: 42,
              neutral: true,
            )
          else
            ClipRRect(
              borderRadius: BorderRadius.circular(AppRadius.md),
              child: Image.file(
                File(path),
                width: 44,
                height: 44,
                fit: BoxFit.cover,
              ),
            ),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  path == null ? 'صورة العداد' : 'صورة مرفقة',
                  style: Theme.of(
                    context,
                  ).textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w500),
                ),
                Text(
                  path == null
                      ? 'اختيارية — تساعد على المراجعة'
                      : 'تم ضغط الصورة تلقائياً',
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              ],
            ),
          ),
          AppIconButton(
            tooltip: path == null ? 'التقاط أو اختيار صورة' : 'استبدال الصورة',
            onPressed: _chooseImage,
            icon: path == null
                ? Icons.add_a_photo_outlined
                : Icons.sync_rounded,
            size: 38,
          ),
          if (path != null) ...<Widget>[
            const SizedBox(width: 6),
            AppIconButton(
              tooltip: 'إزالة الصورة',
              onPressed: () => setState(() => _imagePath = null),
              icon: Icons.close_rounded,
              size: 38,
            ),
          ],
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
                    result.isPendingSync
                        ? AppColors.warning
                        : AppColors.success,
                    context,
                  ),
                  borderColor:
                      (result.isPendingSync
                              ? AppColors.warning
                              : AppColors.success)
                          .withValues(alpha: .22),
                  child: Row(
                    children: <Widget>[
                      AppGlyph(
                        icon: result.isPendingSync
                            ? Icons.cloud_upload_outlined
                            : Icons.check_rounded,
                        tone: result.isPendingSync
                            ? AppColors.warning
                            : AppColors.success,
                        size: 42,
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            Text(
                              result.isPendingSync
                                  ? 'بانتظار المزامنة'
                                  : 'تم حفظ القراءة',
                              style: Theme.of(context).textTheme.titleSmall
                                  ?.copyWith(fontWeight: FontWeight.w600),
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
                      _summaryRow(
                        'القراءة السابقة',
                        _formatNumber(result.reading.previousReading),
                        ltr: true,
                      ),
                      _summaryRow(
                        'القراءة الحالية',
                        _formatNumber(result.reading.currentReading),
                        ltr: true,
                      ),
                      _summaryRow(
                        'الاستهلاك',
                        '${_formatNumber(result.reading.consumptionUnits)} وحدة',
                        ltr: true,
                      ),
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
                  Row(
                    children: <Widget>[
                      Expanded(
                        child: AppSecondaryButton(
                          label: 'طباعة الفاتورة',
                          icon: Icons.print_outlined,
                          onPressed: _isRunningInvoiceAction
                              ? null
                              : () => _runInvoiceAction(
                                  (item) =>
                                      widget.invoiceActions!.printInvoice(item),
                                ),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: AppSecondaryButton(
                          label: 'واتساب',
                          icon: Icons.chat_outlined,
                          onPressed: _isRunningInvoiceAction
                              ? null
                              : () => _runInvoiceAction(
                                  (item) => widget.invoiceActions!
                                      .shareOnWhatsApp(item),
                                ),
                        ),
                      ),
                    ],
                  ),
                  if (_isRunningInvoiceAction) ...<Widget>[
                    const SizedBox(height: 8),
                    const ShimmerSkeleton(height: 4),
                  ],
                ],
                const SizedBox(height: 10),
                Row(
                  children: <Widget>[
                    Expanded(
                      child: AppSecondaryButton(
                        label: 'قراءة جديدة',
                        icon: Icons.add_chart_outlined,
                        onPressed: _loadPeriod,
                      ),
                    ),
                    if (widget.onNextPath != null) ...<Widget>[
                      const SizedBox(width: 10),
                      Expanded(
                        child: AppPrimaryButton(
                          label: 'المسار التالي',
                          icon: Icons.skip_next_outlined,
                          onPressed: _nextPath,
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
                style: Theme.of(
                  context,
                ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
        const SizedBox(height: 6),
        Divider(
          height: 18,
          color: Theme.of(context).colorScheme.outlineVariant,
        ),
        _summaryRow(
          'المشترك',
          invoice.subscriberName ?? _meter.subscriberName ?? '—',
        ),
        _summaryRow(
          'كود العداد',
          invoice.meterCode ?? _meter.uniqueCode,
          ltr: true,
        ),
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

  Widget _summaryRow(
    String label,
    String value, {
    bool ltr = false,
    bool emphasis = false,
  }) {
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
            Directionality(
              textDirection: TextDirection.ltr,
              child: Text(value, style: style),
            )
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
