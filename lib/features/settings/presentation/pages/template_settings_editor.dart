import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:image_picker/image_picker.dart';

import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/settings_entities.dart';
import '../../domain/repositories/settings_repository.dart';
import '../cubit/template_settings_cubit.dart';
import '../widgets/field_config_tile.dart';
import '../widgets/setting_image_field.dart';
import '../widgets/settings_form_widgets.dart';
import '../widgets/template_preview.dart';

class FieldDefinition {
  const FieldDefinition(this.title, this.key);

  final String title;
  final String key;
}

class TemplateSettingsEditor extends StatefulWidget {
  const TemplateSettingsEditor({
    required this.repository,
    required this.kind,
    this.embedded = false,
    this.mediaBaseUrl,
    this.imagePicker,
    super.key,
  });

  final SettingsRepository repository;
  final TemplateKind kind;
  final bool embedded;
  final String? mediaBaseUrl;
  final ImagePicker? imagePicker;

  @override
  State<TemplateSettingsEditor> createState() => _TemplateSettingsEditorState();
}

class _TemplateSettingsEditorState extends State<TemplateSettingsEditor> {
  final _formKey = GlobalKey<FormState>();
  final _controllers = <String, TextEditingController>{};
  late final TemplateSettingsCubit _cubit;
  bool _hydrated = false;

  bool get _isInvoice => widget.kind == TemplateKind.invoice;
  String get _prefix => _isInvoice ? 'inv' : 'rect';
  String get _documentLabel => _isInvoice ? 'الفاتورة' : 'السند';

  @override
  void initState() {
    super.initState();
    _cubit = TemplateSettingsCubit(widget.repository, widget.kind)..load();
  }

  @override
  void dispose() {
    for (final controller in _controllers.values) {
      controller.dispose();
    }
    unawaited(_cubit.close());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return BlocProvider.value(
      value: _cubit,
      child: BlocConsumer<TemplateSettingsCubit, TemplateSettingsState>(
        listenWhen: (previous, current) =>
            previous.errorMessage != current.errorMessage || (!previous.saved && current.saved),
        listener: (context, state) {
          if (state.errorMessage != null) {
            showAdminSnackBar(context, adminErrorMessage(state.errorMessage!), isError: true);
          } else if (state.saved) {
            showAdminSnackBar(context, 'تم حفظ تصميم $_documentLabel وتحديث الكاش المحلي.');
          }
        },
        builder: (context, state) {
          final settings = state.settings;
          if (settings != null && !_hydrated) {
            _hydrate(settings);
          }
          return Scaffold(
            appBar: widget.embedded
                ? null
                : AppBar(
                    title: Text(_isInvoice ? 'إعدادات وتصميم الفاتورة' : 'إعدادات وتصميم سند القبض'),
                  ),
            body: state.isLoading && settings == null
                ? const SettingsLoadingBody()
                : settings == null
                    ? SettingsErrorBody(
                        message: adminErrorMessage(state.errorMessage ?? 'تعذر تحميل الإعدادات.'),
                        onRetry: _cubit.load,
                      )
                    : LayoutBuilder(
                        builder: (context, constraints) => _buildEditor(
                          context,
                          settings,
                          showSidePreview: constraints.maxWidth >= 1100,
                        ),
                      ),
            bottomNavigationBar: settings == null
                ? null
                : SettingsSaveBar(
                    isSaving: state.isSaving,
                    enabled: state.uploadingImages.isEmpty,
                    onSave: () {
                      if (_formKey.currentState?.validate() ?? false) _cubit.save();
                    },
                  ),
          );
        },
      ),
    );
  }

  Widget _buildEditor(
    BuildContext context,
    TemplateSettings settings, {
    required bool showSidePreview,
  }) {
    final tabs = _isInvoice ? _invoiceTabs(settings) : _receiptTabs(settings);
    final editor = DefaultTabController(
      length: tabs.length,
      child: Column(
        children: [
          Material(
            color: Theme.of(context).colorScheme.surface,
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          _isInvoice ? 'تبويبات تصميم الفاتورة السبعة' : 'تبويبات تصميم السند الخمسة',
                          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                                fontWeight: FontWeight.w800,
                              ),
                        ),
                      ),
                      if (!showSidePreview)
                        OutlinedButton.icon(
                          onPressed: () => _showPreview(context, settings),
                          icon: const Icon(Icons.visibility_outlined),
                          label: const Text('معاينة حية'),
                        ),
                    ],
                  ),
                ),
                TabBar(
                  isScrollable: true,
                  tabAlignment: TabAlignment.start,
                  tabs: [for (final tab in tabs) Tab(text: tab.title, icon: Icon(tab.icon))],
                ),
              ],
            ),
          ),
          Expanded(
            child: Form(
              key: _formKey,
              child: TabBarView(
                children: [
                  for (final tab in tabs)
                    SingleChildScrollView(
                      key: PageStorageKey('${widget.kind.name}-${tab.title}'),
                      padding: const EdgeInsets.all(16),
                      child: Center(
                        child: ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 1000),
                          child: tab.child,
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
    if (!showSidePreview) return editor;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(flex: 3, child: editor),
        Container(width: 1, color: Theme.of(context).colorScheme.outlineVariant),
        Expanded(
          flex: 2,
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(20),
            child: Column(
              children: [
                const AdminSectionHeader(
                  title: 'معاينة حية',
                  subtitle: 'تتحدث فور تعديل أي مسمى أو مفتاح إظهار.',
                  icon: Icons.visibility_outlined,
                ),
                const SizedBox(height: 16),
                TemplatePreview(settings: settings),
              ],
            ),
          ),
        ),
      ],
    );
  }

  List<_EditorTab> _invoiceTabs(TemplateSettings settings) {
    return [
      _EditorTab(
        'الأساسيات والشعارات',
        Icons.business_outlined,
        Column(
          children: [
            SettingsSectionCard(
              title: 'النصوص الأساسية',
              children: [
                SettingsFormGrid(
                  children: [
                    for (final field in const {
                      'invoice_project_name_ar': 'اسم المشروع (عربي)',
                      'invoice_project_name_en': 'اسم المشروع (إنجليزي)',
                      'invoice_address_ar': 'العنوان (عربي)',
                      'invoice_address_en': 'العنوان (إنجليزي)',
                      'invoice_phone_1': 'الهاتف 1',
                      'invoice_phone_2': 'الهاتف 2',
                      'invoice_collector_name': 'اسم المحصل الافتراضي',
                    }.entries)
                      _textField(field.key, field.value, forceLtr: field.key.endsWith('_en') || field.key.contains('phone')),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 14),
            SettingsSectionCard(
              title: 'الصور والشعارات',
              children: [
                _imageField('invoice_logo', 'شعار المشروع (الرئيسي)', settings),
                _imageField('site_favicon', 'أيقونة الموقع (Favicon)', settings),
                _imageField(
                  'invoice_watermark',
                  'العلامة المائية',
                  settings,
                  helperText: 'تظهر كخلفية شفافة خلف بيانات الفاتورة.',
                ),
              ],
            ),
          ],
        ),
      ),
      _EditorTab(
        'عناوين الأقسام',
        Icons.title_rounded,
        SettingsSectionCard(
          title: 'عناوين أقسام الفاتورة',
          children: [
            SettingsFormGrid(
              children: [
                _textField('inv_title_main', 'عنوان الفاتورة الرئيسي', required: true),
                _textField('inv_title_subscriber', 'عنوان قسم بيانات المشترك', required: true),
                _textField('inv_title_consumption', 'عنوان قسم تفاصيل الاستهلاك', required: true),
                _textField('inv_title_total', 'نص إجمالي الفاتورة', required: true),
              ],
            ),
          ],
        ),
      ),
      _fieldTab(
        'حقول المشترك والفاتورة',
        Icons.person_outline,
        settings,
        const [
          'bill_number', 'date_from', 'date_to', 'sub_name', 'meter_no', 'sub_phone',
          'square', 'address', 'account_no', 'tenant', 'tenant_phone',
        ],
        const [
          'رقم الفاتورة', 'من تاريخ', 'إلى تاريخ', 'اسم المشترك', 'رقم العداد', 'الهاتف',
          'المربع', 'العنوان', 'رقم الحساب', 'المستأجر', 'هاتف المستأجر',
        ],
      ),
      _fieldTab(
        'فاتورة العداد',
        Icons.table_chart_outlined,
        settings,
        const ['prev_read', 'curr_read', 'units', 'unit_price', 'cons_value', 'sub_fee', 'tanker', 'arrears', 'credit_applied'],
        const ['القراءة السابقة', 'القراءة الحالية', 'وحدات الاستهلاك', 'سعر الوحدة', 'قيمة الاستهلاك', 'الاشتراك', 'رسوم الوايت', 'المتأخرات', 'الرصيد المخصوم'],
      ),
      _specialInvoiceTab(
        'فاتورة الاشتراك',
        Icons.request_quote_outlined,
        settings,
        'sub',
        'فاتورة الاشتراك',
      ),
      _specialInvoiceTab(
        'فاتورة الوايت',
        Icons.local_shipping_outlined,
        settings,
        'tanker',
        'فاتورة الوايت',
      ),
      _fieldTab(
        'تذييل الفاتورة',
        Icons.vertical_align_bottom_rounded,
        settings,
        const ['collector', 'signature', 'thank_you', 'notes'],
        const ['المحصل', 'توقيع المشترك', 'رسالة الشكر', 'الملاحظات العامة'],
      ),
    ];
  }

  List<_EditorTab> _receiptTabs(TemplateSettings settings) {
    return [
      _EditorTab(
        'الأساسيات المشتركة',
        Icons.business_outlined,
        SettingsSectionCard(
          title: 'النصوص والشعارات المشتركة مع الفاتورة',
          children: [
            SettingsFormGrid(
              children: [
                for (final field in const {
                  'invoice_project_name_ar': 'اسم المشروع (عربي)',
                  'invoice_project_name_en': 'اسم المشروع (إنجليزي)',
                  'invoice_address_ar': 'العنوان (عربي)',
                  'invoice_address_en': 'العنوان (إنجليزي)',
                  'invoice_phone_1': 'الهاتف 1',
                  'invoice_phone_2': 'الهاتف 2',
                }.entries)
                  _textField(field.key, field.value, forceLtr: field.key.endsWith('_en') || field.key.contains('phone')),
              ],
            ),
            _imageField('invoice_logo', 'شعار المشروع (الرئيسي)', settings),
            _imageField('invoice_watermark', 'العلامة المائية', settings),
          ],
        ),
      ),
      _EditorTab(
        'عناوين السند',
        Icons.title_rounded,
        Column(
          children: [
            SettingsSectionCard(
              title: 'عنوان السند',
              children: [_textField('rect_title_main', 'عنوان السند الرئيسي', required: true)],
            ),
            _customField(
              settings,
              'عنوان قسم بيانات المشترك',
              'title_subscriber',
              labelKey: 'rect_title_subscriber',
            ),
            _customField(
              settings,
              'عنوان قسم بيانات الدفع',
              'title_payment',
              labelKey: 'rect_title_payment',
            ),
          ],
        ),
      ),
      _fieldTab(
        'حقول السند والمشترك',
        Icons.person_outline,
        settings,
        const ['receipt_number', 'receipt_date', 'sub_name', 'sub_phone', 'square', 'account_no', 'tenant', 'tenant_phone'],
        const ['رقم السند', 'تاريخ السند', 'اسم المشترك', 'الهاتف', 'المربع السكني', 'رقم الحساب', 'المستأجر', 'هاتف المستأجر'],
      ),
      _EditorTab(
        'بيانات الدفع والمبالغ',
        Icons.payments_outlined,
        Column(
          children: [
            _fieldsGrid(
              settings,
              const ['payment_method', 'linked_invoice', 'reference', 'invoice_total', 'prev_payments', 'amount_received', 'remaining_invoice', 'outstanding_total', 'credit_balance', 'reason'],
              const ['طريقة الدفع', 'الفاتورة المرتبطة', 'رقم الحوالة / الشيك', 'إجمالي الفاتورة', 'مدفوعات سابقة', 'المبلغ المستلم', 'المتبقي من الفاتورة', 'إجمالي المتبقي للحساب', 'رصيد دائن للمشترك', 'وذلك مقابل'],
            ),
            SettingsSectionCard(
              title: 'المبلغ كتابةً بالتفقيط',
              children: [
                _customField(settings, 'المبلغ المستلم كتابةً', 'amount_in_words'),
                _textField('rect_amount_words_suffix', 'اللاحقة (مثل: ريال يمني لا غير)', required: true),
              ],
            ),
          ],
        ),
      ),
      _fieldTab(
        'التذييل والملاحظات',
        Icons.vertical_align_bottom_rounded,
        settings,
        const ['notes', 'collector_sig', 'receiver_sig'],
        const ['الملاحظات', 'توقيع المحصل', 'توقيع المستلم'],
      ),
    ];
  }

  _EditorTab _specialInvoiceTab(
    String title,
    IconData icon,
    TemplateSettings settings,
    String prefix,
    String sectionTitle,
  ) {
    return _EditorTab(
      title,
      icon,
      Column(
        children: [
          SettingsSectionCard(
            title: sectionTitle,
            children: [
              SettingsFormGrid(
                children: [
                  _textField('${prefix}_inv_title_main', 'عنوان الفاتورة الرئيسي', required: true),
                  _textField('${prefix}_inv_title_details', 'عنوان قسم التفاصيل', required: true),
                ],
              ),
            ],
          ),
          const SizedBox(height: 12),
          SettingsFormGrid(
            children: [
              _specialField(settings, 'قيمة الفاتورة', '${prefix}_show_amount', '${prefix}_lbl_amount'),
              _specialField(settings, 'المتأخرات', '${prefix}_show_arrears', '${prefix}_lbl_arrears'),
              _specialField(settings, 'الملاحظات', '${prefix}_show_notes', '${prefix}_lbl_notes'),
            ],
          ),
        ],
      ),
    );
  }

  _EditorTab _fieldTab(
    String title,
    IconData icon,
    TemplateSettings settings,
    List<String> keys,
    List<String> titles,
  ) {
    return _EditorTab(title, icon, _fieldsGrid(settings, keys, titles));
  }

  Widget _fieldsGrid(TemplateSettings settings, List<String> keys, List<String> titles) {
    return SettingsFormGrid(
      minItemWidth: 360,
      children: [
        for (var index = 0; index < keys.length; index++)
          _customField(settings, titles[index], keys[index]),
      ],
    );
  }

  Widget _customField(
    TemplateSettings settings,
    String title,
    String key, {
    String? labelKey,
  }) {
    return _specialField(
      settings,
      title,
      '${_prefix}_show_$key',
      labelKey ?? '${_prefix}_lbl_$key',
    );
  }

  Widget _specialField(
    TemplateSettings settings,
    String title,
    String showKey,
    String labelKey,
  ) {
    return FieldConfigTile(
      title: title,
      showKey: showKey,
      labelKey: labelKey,
      visible: settings.boolean(showKey),
      labelController: _controllers[labelKey]!,
      documentLabel: _documentLabel,
      onVisibilityChanged: (value) => _cubit.updateValue(showKey, value),
      onLabelChanged: (value) => _cubit.updateValue(labelKey, value),
    );
  }

  Widget _textField(
    String key,
    String label, {
    bool required = false,
    bool forceLtr = false,
  }) {
    return SettingsTextField(
      controller: _controllers[key]!,
      label: label,
      required: required,
      forceLtr: forceLtr,
      onChanged: (value) => _cubit.updateValue(key, value),
    );
  }

  Widget _imageField(
    String key,
    String label,
    TemplateSettings settings, {
    String? helperText,
  }) {
    return SettingImageField(
      label: label,
      settingKey: key,
      path: settings.text(key),
      helperText: helperText,
      mediaBaseUrl: widget.mediaBaseUrl,
      picker: widget.imagePicker,
      isUploading: context.watch<TemplateSettingsCubit>().state.uploadingImages.contains(key),
      onSelected: (file) async {
        final bytes = await file.readAsBytes();
        await _cubit.uploadImage(key, bytes, file.name);
      },
    );
  }

  void _hydrate(TemplateSettings settings) {
    const imageKeys = {'invoice_logo', 'invoice_watermark', 'site_favicon'};
    for (final entry in settings.values.entries) {
      if (entry.value is bool || imageKeys.contains(entry.key)) continue;
      _controllers[entry.key] = TextEditingController(text: entry.value?.toString() ?? '');
    }
    _hydrated = true;
  }

  void _showPreview(BuildContext context, TemplateSettings settings) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: .85,
        maxChildSize: .95,
        builder: (context, controller) => SingleChildScrollView(
          controller: controller,
          padding: const EdgeInsets.all(20),
          child: TemplatePreview(settings: _cubit.state.settings!),
        ),
      ),
    );
  }
}

class _EditorTab {
  const _EditorTab(this.title, this.icon, this.child);

  final String title;
  final IconData icon;
  final Widget child;
}
