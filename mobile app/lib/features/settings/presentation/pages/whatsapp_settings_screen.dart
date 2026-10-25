import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/navigation/sub_page.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/settings_entities.dart';
import '../../domain/repositories/settings_repository.dart';
import '../cubit/whatsapp_settings_cubit.dart';
import '../widgets/local_server_control_panel.dart';
import '../widgets/settings_form_widgets.dart';
import '../widgets/whatsapp_template_editor.dart';

class WhatsAppSettingsScreen extends StatelessWidget {
  const WhatsAppSettingsScreen({
    required this.repository,
    this.embedded = false,
    super.key,
  });

  final SettingsRepository repository;
  final bool embedded;

  @override
  Widget build(BuildContext context) {
    return BlocProvider(
      create: (_) => WhatsAppSettingsCubit(repository)..load(),
      child: _WhatsAppSettingsView(embedded: embedded),
    );
  }
}

class _WhatsAppSettingsView extends StatefulWidget {
  const _WhatsAppSettingsView({required this.embedded});

  final bool embedded;

  @override
  State<_WhatsAppSettingsView> createState() => _WhatsAppSettingsViewState();
}

class _WhatsAppSettingsViewState extends State<_WhatsAppSettingsView> {
  final _formKey = GlobalKey<FormState>();
  final _controllers = <String, TextEditingController>{};
  bool _hydrated = false;
  bool _showPhoneId = false;
  bool _showToken = false;

  @override
  void dispose() {
    for (final controller in _controllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<WhatsAppSettingsCubit, WhatsAppSettingsState>(
      listenWhen: (previous, current) =>
          previous.errorMessage != current.errorMessage || (!previous.saved && current.saved),
      listener: (context, state) {
        if (state.errorMessage != null) {
          showAdminSnackBar(context, adminErrorMessage(state.errorMessage!), isError: true);
        } else if (state.saved) {
          showAdminSnackBar(context, 'تم حفظ الواتساب ونشر القوالب الأربعة في كاش التطبيق.');
        }
      },
      builder: (context, state) {
        final settings = state.settings;
        if (settings != null && !_hydrated) _hydrate(settings);
        final body = state.isLoading && settings == null
            ? const SettingsLoadingBody()
            : settings == null
                ? SettingsErrorBody(
                    message: adminErrorMessage(state.errorMessage ?? 'تعذر تحميل الإعدادات.'),
                    onRetry: () => context.read<WhatsAppSettingsCubit>().load(),
                  )
                : Form(
                    key: _formKey,
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.all(16),
                      child: Center(
                        child: ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 1200),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              _basicSettings(context, settings),
                              if (settings.enabled && settings.method == 'local_api') ...[
                                const SizedBox(height: 16),
                                const LocalServerControlPanel(),
                              ],
                              if (settings.enabled && settings.method != 'meta_api') ...[
                                const SizedBox(height: 16),
                                _templatesEditor(settings),
                              ],
                              if (settings.enabled && settings.method == 'meta_api') ...[
                                const SizedBox(height: 16),
                                _metaSettings(settings),
                              ],
                              const SizedBox(height: 24),
                            ],
                          ),
                        ),
                      ),
                    ),
                  );
        final saveBar = settings == null
            ? null
            : SettingsSaveBar(
                isSaving: state.isSaving,
                onSave: () {
                  if (_formKey.currentState?.validate() ?? false) {
                    context.read<WhatsAppSettingsCubit>().save();
                  }
                },
              );
        if (widget.embedded) {
          return Scaffold(body: body, bottomNavigationBar: saveBar);
        }
        return SubPageScaffold(
          title: 'إعدادات تنبيهات الواتساب',
          subtitle: 'محرك الإرسال وقوالب الرسائل',
          child: Column(
            children: [
              Expanded(child: body),
              if (saveBar != null) saveBar,
            ],
          ),
        );
      },
    );
  }

  Widget _basicSettings(BuildContext context, WhatsAppSettings settings) {
    final cubit = context.read<WhatsAppSettingsCubit>();
    return SettingsSectionCard(
      title: 'الضبط الأساسي',
      subtitle: 'اختر محرك الإرسال المناسب؛ التقارير والقوالب تستخدم الإعداد نفسه.',
      children: [
        AppSettingRow(
          icon: Icons.chat_outlined,
          color: Theme.of(context).colorScheme.primary,
          showChevron: false,
          title: 'تفعيل خدمة الواتساب بشكل عام',
          trailing: Switch(
            value: settings.enabled,
            onChanged: (value) => cubit.update(settings.copyWith(enabled: value)),
          ),
        ),
        if (settings.enabled) ...[
          DropdownButtonFormField<String>(
            key: ValueKey(settings.method),
            initialValue: settings.method,
            decoration: const InputDecoration(
              labelText: 'طريقة الإرسال المعتمدة',
            ),
            items: const [
              DropdownMenuItem(value: 'click_to_chat', child: Text('الرابط المباشر (مجاناً وبدون قيود)')),
              DropdownMenuItem(value: 'meta_api', child: Text('Meta Cloud API (يرفق PDF)')),
              DropdownMenuItem(value: 'local_api', child: Text('الخادم المحلي (مجاني + PDF)')),
            ],
            onChanged: (value) => cubit.update(settings.copyWith(method: value)),
          ),
          if (settings.usesAutomaticSending) ...[
            AppSettingRow(
              icon: Icons.send_outlined,
              color: Theme.of(context).colorScheme.primary,
              showChevron: false,
              title: 'إرسال الفاتورة تلقائياً عند الإصدار',
              trailing: Switch(
                value: settings.autoSendInvoice,
                onChanged: (value) => cubit.update(settings.copyWith(autoSendInvoice: value)),
              ),
            ),
            AppSettingRow(
              icon: Icons.payments_outlined,
              color: Theme.of(context).colorScheme.primary,
              showChevron: false,
              title: 'إرسال سند القبض تلقائياً عند السداد',
              trailing: Switch(
                value: settings.autoSendPayment,
                onChanged: (value) => cubit.update(settings.copyWith(autoSendPayment: value)),
              ),
            ),
          ],
        ],
      ],
    );
  }

  Widget _templatesEditor(WhatsAppSettings settings) {
    final definitions = [
      (
        title: 'فاتورة العداد',
        field: 'invoiceMessage',
        variables: WhatsAppTemplateSamples.invoiceVariables,
      ),
      (
        title: 'سند القبض',
        field: 'paymentMessage',
        variables: WhatsAppTemplateSamples.paymentVariables,
      ),
      (
        title: 'فاتورة الاشتراك',
        field: 'subscriptionMessage',
        variables: WhatsAppTemplateSamples.subscriptionVariables,
      ),
      (
        title: 'فاتورة الوايت',
        field: 'tankerMessage',
        variables: WhatsAppTemplateSamples.tankerVariables,
      ),
    ];
    return SettingsSectionCard(
      title: 'قوالب الرسائل والمعاينة الحية',
      subtitle: 'تدعم المتغيرات والبلوكات الشرطية، وتُحفظ بالمفاتيح المتوافقة مع API الحالي.',
      children: [
        DefaultTabController(
          length: definitions.length,
          child: Column(
            children: [
              TabBar(
                isScrollable: true,
                tabAlignment: TabAlignment.start,
                tabs: [for (final item in definitions) Tab(text: item.title)],
              ),
              SizedBox(
                height: 610,
                child: TabBarView(
                  children: [
                    for (final item in definitions)
                      WhatsAppTemplateEditor(
                        key: ValueKey('template-${item.field}'),
                        controller: _controllers[item.field]!,
                        variables: item.variables,
                        sampleValues: WhatsAppTemplateSamples.common,
                        onChanged: (value) => _updateTemplate(settings, item.field, value),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _metaSettings(WhatsAppSettings settings) {
    final cubit = context.read<WhatsAppSettingsCubit>();
    return SettingsSectionCard(
      title: 'إعدادات Meta Cloud API',
      subtitle: 'تُحفظ الأسرار على الخادم ولا تدخل كاش الإعدادات المشترك.',
      children: [
        SettingsFormGrid(
          children: [
            SettingsTextField(
              controller: _controllers['phoneNumberId']!,
              label: 'Phone Number ID',
              forceLtr: true,
              required: true,
              obscureText: !_showPhoneId,
              suffixIcon: IconButton(
                onPressed: () => setState(() => _showPhoneId = !_showPhoneId),
                icon: Icon(_showPhoneId ? Icons.visibility_off : Icons.visibility),
              ),
              onChanged: (value) => cubit.update(settings.copyWith(phoneNumberId: value)),
            ),
            SettingsTextField(
              controller: _controllers['accessToken']!,
              label: 'Access Token',
              forceLtr: true,
              helperText: 'اتركه فارغاً للاحتفاظ بالرمز المحفوظ في الخادم',
              obscureText: !_showToken,
              suffixIcon: IconButton(
                onPressed: () => setState(() => _showToken = !_showToken),
                icon: Icon(_showToken ? Icons.visibility_off : Icons.visibility),
              ),
              onChanged: (value) => cubit.update(settings.copyWith(accessToken: value)),
            ),
            SettingsTextField(
              controller: _controllers['invoiceTemplate']!,
              label: 'قالب الفاتورة (Invoice Template)',
              forceLtr: true,
              required: true,
              helperText: 'Header من نوع Document وBody بثلاثة متغيرات.',
              onChanged: (value) => cubit.update(settings.copyWith(invoiceTemplate: value)),
            ),
            SettingsTextField(
              controller: _controllers['paymentTemplate']!,
              label: 'قالب سند القبض (Payment Template)',
              forceLtr: true,
              required: true,
              helperText: 'Header من نوع Document وBody بثلاثة متغيرات.',
              onChanged: (value) => cubit.update(settings.copyWith(paymentTemplate: value)),
            ),
          ],
        ),
        const _MetaTemplateGuide(
          title: 'النص المقترح لقالب الفاتورة',
          text: 'أهلاً بك {{1}}،\nتم إصدار فاتورتك الجديدة رقم {{2}}.\nالمبلغ الإجمالي المطلوب: {{3}} ريال.\nيرجى الاطلاع على الفاتورة المرفقة (PDF).',
        ),
        const _MetaTemplateGuide(
          title: 'النص المقترح لقالب السند',
          text: 'أهلاً بك {{1}}،\nاستلمنا دفعتك بموجب سند رقم {{2}}.\nالمبلغ المدفوع: {{3}} ريال.\nيرجى الاطلاع على السند المرفق (PDF).',
        ),
      ],
    );
  }

  void _updateTemplate(WhatsAppSettings settings, String field, String value) {
    final updated = switch (field) {
      'invoiceMessage' => settings.copyWith(invoiceMessage: value),
      'paymentMessage' => settings.copyWith(paymentMessage: value),
      'subscriptionMessage' => settings.copyWith(subscriptionMessage: value),
      _ => settings.copyWith(tankerMessage: value),
    };
    context.read<WhatsAppSettingsCubit>().update(updated);
  }

  void _hydrate(WhatsAppSettings settings) {
    final values = {
      'invoiceMessage': settings.invoiceMessage,
      'paymentMessage': settings.paymentMessage,
      'subscriptionMessage': settings.subscriptionMessage,
      'tankerMessage': settings.tankerMessage,
      'phoneNumberId': settings.phoneNumberId,
      'accessToken': settings.accessToken,
      'invoiceTemplate': settings.invoiceTemplate,
      'paymentTemplate': settings.paymentTemplate,
    };
    for (final entry in values.entries) {
      _controllers[entry.key] = TextEditingController(text: entry.value);
    }
    _hydrated = true;
  }
}

class _MetaTemplateGuide extends StatelessWidget {
  const _MetaTemplateGuide({required this.title, required this.text});

  final String title;
  final String text;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      padding: const EdgeInsets.all(12),
      child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: [
                Expanded(child: Text(title, style: const TextStyle(fontWeight: FontWeight.w600))),
                AppIconButton(
                  tooltip: 'نسخ النص',
                  onPressed: () async {
                    await Clipboard.setData(ClipboardData(text: text));
                    if (context.mounted) showAdminSnackBar(context, 'تم نسخ نص القالب.');
                  },
                  icon: Icons.copy_outlined,
                  size: 38,
                ),
              ],
            ),
            SelectableText(
              text,
              style: const TextStyle(fontFamily: 'monospace', height: 1.5, fontSize: 11.5),
            ),
          ],
        ),
    );
  }
}
