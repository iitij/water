import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';

import '../../../../core/utils/compound_search_parser.dart';
import '../../../meters/data/models/meter.dart';
import '../../data/models/reading.dart';
import '../../data/services/reading_invoice_actions_service.dart';
import '../../domain/readings_repository.dart';

class SubmitReadingScreen extends StatefulWidget {
  const SubmitReadingScreen({
    required this.meter,
    required this.readingsRepository,
    this.invoiceActions,
    this.onNextPath,
    super.key,
  });

  final Meter meter;
  final ReadingsRepository readingsRepository;
  final ReadingInvoiceActionsService? invoiceActions;
  final Future<Meter?> Function(Meter currentMeter)? onNextPath;

  @override
  State<SubmitReadingScreen> createState() => _SubmitReadingScreenState();
}

class _SubmitReadingScreenState extends State<SubmitReadingScreen> {
  final _formKey = GlobalKey<FormState>();
  final _currentReadingController = TextEditingController();
  final _imagePicker = ImagePicker();

  late Meter _meter;
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
                  ? _period!.subscriptionInstallment
                  : 0,
          hasTankerFee: _period!.autoIssueInvoice && _includeTankerFee,
          tankerInstallment: _period!.autoIssueInvoice && _includeTankerFee
              ? _period!.tankerInstallment
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
      });
      await _loadPeriod();
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
                  if (period.isOfflineEstimate) ...[
                    const SizedBox(height: 10),
                    const _NoticeBanner(
                      text: 'تعمل دون اتصال — تم حساب الفترة من البيانات المحلية.',
                      color: Color(0xFF0E7490),
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
                    borderRadius: BorderRadius.circular(12),
                    child: InputDecorator(
                      decoration: const InputDecoration(
                        labelText: 'تاريخ القراءة الفعلي',
                        prefixIcon: Icon(Icons.calendar_month_outlined),
                        border: OutlineInputBorder(),
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
                    decoration: InputDecoration(
                      labelText: 'القراءة الحالية',
                      hintText: 'أدخل الرقم الظاهر على العداد',
                      prefixIcon: const Icon(Icons.speed_outlined),
                      suffixText: 'وحدة',
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
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
                          fontWeight: FontWeight.w700,
                        ),
                  ),
                  if (period.autoIssueInvoice) ...[
                    const SizedBox(height: 18),
                    const _NoticeBanner(
                      text: 'تنبيه الفوترة التلقائية: سيصدر النظام فاتورة فور حفظ هذه القراءة.',
                      color: Color(0xFFF59E0B),
                      icon: Icons.warning_amber_rounded,
                    ),
                    const SizedBox(height: 8),
                    if (period.hasSubscriptionFee)
                      _feeOption(
                        title: 'إضافة قسط رسوم الاشتراك',
                        value: period.subscriptionInstallment,
                        selected: _includeSubscriptionFee,
                        onChanged: (value) => setState(() => _includeSubscriptionFee = value),
                      ),
                    if (period.hasTankerFee)
                      _feeOption(
                        title: 'إضافة رسوم الوايت',
                        value: period.tankerInstallment,
                        selected: _includeTankerFee,
                        onChanged: (value) => setState(() => _includeTankerFee = value),
                      ),
                  ],
                  const SizedBox(height: 18),
                  _imageControl(),
                  if (_errorMessage != null) ...[
                    const SizedBox(height: 12),
                    Text(_errorMessage!, style: const TextStyle(color: Colors.red)),
                  ],
                  const SizedBox(height: 24),
                  FilledButton.icon(
                    onPressed: _isSaving ? null : _save,
                    icon: _isSaving
                        ? const SizedBox.square(
                            dimension: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.save_outlined),
                    label: Text(_isSaving ? 'جارٍ حفظ القراءة…' : 'حفظ القراءة'),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _meterHeader() => Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              CircleAvatar(
                backgroundColor: Theme.of(context).colorScheme.primaryContainer,
                child: Icon(Icons.speed, color: Theme.of(context).colorScheme.primary),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(_meter.subscriberName ?? 'مشترك', style: Theme.of(context).textTheme.titleMedium),
                    const SizedBox(height: 3),
                    Directionality(
                      textDirection: TextDirection.ltr,
                      child: Text(
                        '${_meter.uniqueCode}${_meter.pathNumber == null ? '' : '  •  المسار ${_meter.pathNumber}'}',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                    ),
                    if (_meter.tenantName?.isNotEmpty == true)
                      Text('المستأجر: ${_meter.tenantName}'),
                  ],
                ),
              ),
            ],
          ),
        ),
      );

  Widget _periodCard(MeterPeriodResponse period) => Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              const Icon(Icons.date_range_outlined),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('فترة الاستهلاك'),
                    const SizedBox(height: 4),
                    Directionality(
                      textDirection: TextDirection.ltr,
                      child: Text(
                        '${_formatDate(period.periodStart)}  —  ${_formatDate(period.periodEnd)}',
                        style: Theme.of(context).textTheme.titleSmall?.copyWith(
                              fontWeight: FontWeight.w700,
                            ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      );

  Widget _readingValueCard(String title, double value, {bool accent = false}) => Card(
        color: accent ? Theme.of(context).colorScheme.primaryContainer : null,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 5),
              Directionality(
                textDirection: TextDirection.ltr,
                child: Text(
                  _formatNumber(value),
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
                ),
              ),
            ],
          ),
        ),
      );

  Widget _feeOption({
    required String title,
    required double value,
    required bool selected,
    required ValueChanged<bool> onChanged,
  }) =>
      Card(
        child: SwitchListTile(
          value: selected,
          onChanged: onChanged,
          title: Text(title),
          subtitle: Directionality(
            textDirection: TextDirection.ltr,
            child: Text('${_formatNumber(value)} ريال'),
          ),
          secondary: const Icon(Icons.receipt_long_outlined),
        ),
      );

  Widget _imageControl() {
    final path = _imagePath;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            if (path == null)
              const CircleAvatar(child: Icon(Icons.camera_alt_outlined))
            else
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: Image.file(File(path), width: 58, height: 58, fit: BoxFit.cover),
              ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(path == null ? 'صورة العداد (اختياري)' : 'تم إرفاق صورة مضغوطة'),
            ),
            IconButton(
              tooltip: path == null ? 'التقاط أو اختيار صورة' : 'استبدال الصورة',
              onPressed: _chooseImage,
              icon: const Icon(Icons.add_a_photo_outlined),
            ),
            if (path != null)
              IconButton(
                tooltip: 'إزالة الصورة',
                onPressed: () => setState(() => _imagePath = null),
                icon: const Icon(Icons.close),
              ),
          ],
        ),
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
                Card(
                  color: result.isPendingSync
                      ? const Color(0xFFFFF3D4)
                      : const Color(0xFFE7F6EE),
                  child: Padding(
                    padding: const EdgeInsets.all(18),
                    child: Row(
                      children: [
                        Icon(
                          result.isPendingSync ? Icons.cloud_upload_outlined : Icons.check_circle_outline,
                          size: 34,
                          color: result.isPendingSync ? const Color(0xFFB77900) : Colors.green,
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                result.isPendingSync ? 'بانتظار المزامنة' : 'تم حفظ القراءة',
                                style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold),
                              ),
                              if (result.message != null) Text(result.message!),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                if (invoice != null && result.invoiceIssued) ...[
                  const SizedBox(height: 12),
                  _invoiceSummary(invoice),
                ],
                const SizedBox(height: 12),
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      children: [
                        _summaryRow('العداد', _meter.uniqueCode, ltr: true),
                        _summaryRow('القراءة السابقة', _formatNumber(result.reading.previousReading), ltr: true),
                        _summaryRow('القراءة الحالية', _formatNumber(result.reading.currentReading), ltr: true),
                        _summaryRow('الاستهلاك', '${_formatNumber(result.reading.consumptionUnits)} وحدة', ltr: true),
                        _summaryRow('الفترة', '${_formatDate(result.reading.periodStart)} — ${_formatDate(result.reading.periodEnd)}', ltr: true),
                      ],
                    ),
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
                  if (_isRunningInvoiceAction) const LinearProgressIndicator(),
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

  Widget _invoiceSummary(InvoiceSummary invoice) => Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Icon(Icons.receipt_long_outlined, color: Theme.of(context).colorScheme.primary),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'الفاتورة الصادرة ${invoice.billNumber == null ? '' : '• ${invoice.billNumber}'}',
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold),
                    ),
                  ),
                ],
              ),
              const Divider(height: 20),
              _summaryRow('المشترك', invoice.subscriberName ?? _meter.subscriberName ?? '—'),
              _summaryRow('كود العداد', invoice.meterCode ?? _meter.uniqueCode, ltr: true),
              _summaryRow('الاستهلاك', '${_formatNumber(invoice.consumptionUnits ?? _saveResult!.reading.consumptionUnits)} وحدة'),
              _summaryRow('إجمالي المطلوب', '${_formatNumber(invoice.totalAmount)} ريال', emphasis: true),
            ],
          ),
        ),
      );

  Widget _summaryRow(String label, String value, {bool ltr = false, bool emphasis = false}) =>
      Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(
          children: [
            Expanded(child: Text(label)),
            if (ltr)
              Directionality(
                textDirection: TextDirection.ltr,
                child: Text(
                  value,
                  style: TextStyle(fontWeight: emphasis ? FontWeight.w800 : FontWeight.w500),
                ),
              )
            else
              Text(
                value,
                style: TextStyle(fontWeight: emphasis ? FontWeight.w800 : FontWeight.w500),
              ),
          ],
        ),
      );

  Widget _loadErrorView() => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline, size: 42, color: Colors.red),
              const SizedBox(height: 12),
              Text(_errorMessage ?? 'تعذر جلب فترة القراءة.'),
              const SizedBox(height: 12),
              FilledButton.tonalIcon(
                onPressed: _loadPeriod,
                icon: const Icon(Icons.refresh),
                label: const Text('إعادة المحاولة'),
              ),
            ],
          ),
        ),
      );

  static String _formatDate(DateTime value) =>
      '${value.year.toString().padLeft(4, '0')}-'
      '${value.month.toString().padLeft(2, '0')}-'
      '${value.day.toString().padLeft(2, '0')}';

  static String _formatNumber(num value) =>
      value.toStringAsFixed(value % 1 == 0 ? 0 : 2);
}

class _NoticeBanner extends StatelessWidget {
  const _NoticeBanner({
    required this.text,
    required this.color,
    required this.icon,
  });

  final String text;
  final Color color;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: color.withAlpha(32),
          border: Border.all(color: color.withAlpha(140)),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          children: [
            Icon(icon, color: color),
            const SizedBox(width: 9),
            Expanded(child: Text(text, style: TextStyle(color: color))),
          ],
        ),
      );
}

class RecordReadingSheet {
  const RecordReadingSheet._();

  static Future<ReadingSaveResult?> show(
    BuildContext context, {
    required Meter meter,
    required ReadingsRepository readingsRepository,
    ReadingInvoiceActionsService? invoiceActions,
    Future<Meter?> Function(Meter currentMeter)? onNextPath,
  }) {
    return showModalBottomSheet<ReadingSaveResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (context) => FractionallySizedBox(
        heightFactor: .96,
        child: ClipRRect(
          borderRadius: const BorderRadius.vertical(top: Radius.circular(22)),
          child: SubmitReadingScreen(
            meter: meter,
            readingsRepository: readingsRepository,
            invoiceActions: invoiceActions,
            onNextPath: onNextPath,
          ),
        ),
      ),
    );
  }
}
