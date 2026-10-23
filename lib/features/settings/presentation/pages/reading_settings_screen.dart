import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/settings_entities.dart';
import '../../domain/repositories/settings_repository.dart';
import '../cubit/reading_settings_cubit.dart';
import '../widgets/settings_form_widgets.dart';

class ReadingSettingsScreen extends StatelessWidget {
  const ReadingSettingsScreen({
    required this.repository,
    this.embedded = false,
    super.key,
  });

  final SettingsRepository repository;
  final bool embedded;

  @override
  Widget build(BuildContext context) {
    return BlocProvider(
      create: (_) => ReadingSettingsCubit(repository)..load(),
      child: _ReadingSettingsView(embedded: embedded),
    );
  }
}

class _ReadingSettingsView extends StatefulWidget {
  const _ReadingSettingsView({required this.embedded});

  final bool embedded;

  @override
  State<_ReadingSettingsView> createState() => _ReadingSettingsViewState();
}

class _ReadingSettingsViewState extends State<_ReadingSettingsView> {
  final _graceController = TextEditingController();
  bool _hydrated = false;

  @override
  void dispose() {
    _graceController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<ReadingSettingsCubit, ReadingSettingsState>(
      listenWhen: (previous, current) =>
          previous.errorMessage != current.errorMessage || (!previous.saved && current.saved),
      listener: (context, state) {
        if (state.errorMessage != null) {
          showAdminSnackBar(context, adminErrorMessage(state.errorMessage!), isError: true);
        } else if (state.saved) {
          showAdminSnackBar(context, 'تم حفظ دورة القراءات وتحديث كاش الوحدات الميدانية.');
        }
      },
      builder: (context, state) {
        final settings = state.settings;
        if (settings != null && !_hydrated) {
          _graceController.text = settings.gracePeriod.toString();
          _hydrated = true;
        }
        return Scaffold(
          appBar: widget.embedded ? null : AppBar(title: const Text('إعدادات فترة القراءات')),
          body: state.isLoading && settings == null
              ? const SettingsLoadingBody()
              : settings == null
                  ? SettingsErrorBody(
                      message: adminErrorMessage(state.errorMessage ?? 'تعذر تحميل الإعدادات.'),
                      onRetry: () => context.read<ReadingSettingsCubit>().load(),
                    )
                  : SingleChildScrollView(
                      padding: const EdgeInsets.all(16),
                      child: Center(
                        child: ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 900),
                          child: SettingsSectionCard(
                            title: 'دورة القراءات والفوترة',
                            subtitle: 'تنعكس هذه القيم على حساب فترات القراءة وإصدار الفواتير مباشرة.',
                            children: [
                              SwitchListTile(
                                value: settings.autoIssueInvoiceOnReading,
                                onChanged: (value) => _update(
                                  context,
                                  settings.copyWith(autoIssueInvoiceOnReading: value),
                                ),
                                title: const Text('إصدار الفاتورة تلقائياً عند إدخال القراءة'),
                                subtitle: const Text('إنشاء فاتورة العداد فور حفظ قراءة جديدة.'),
                              ),
                              const Divider(),
                              DropdownButtonFormField<String>(
                                key: ValueKey(settings.readingPeriod),
                                initialValue: settings.readingPeriod,
                                decoration: const InputDecoration(
                                  labelText: 'دورة القراءات',
                                  border: OutlineInputBorder(),
                                ),
                                items: const [
                                  DropdownMenuItem(value: 'weekly', child: Text('أسبوعية (كل 7 أيام)')),
                                  DropdownMenuItem(value: 'ten_days', child: Text('كل 10 أيام')),
                                  DropdownMenuItem(value: 'half_monthly', child: Text('نصف شهرية (كل 15 يوم)')),
                                  DropdownMenuItem(value: 'monthly', child: Text('شهرية')),
                                ],
                                onChanged: (value) => _update(
                                  context,
                                  settings.copyWith(readingPeriod: value),
                                ),
                              ),
                              if (settings.readingPeriod == 'monthly') ...[
                                const SizedBox(height: 16),
                                DropdownButtonFormField<String>(
                                  key: ValueKey(settings.readingDay),
                                  initialValue: settings.readingDay,
                                  decoration: const InputDecoration(
                                    labelText: 'يوم إدخال القراءة',
                                    border: OutlineInputBorder(),
                                    helperText: 'يحدد نهاية فترة الاستهلاك في الدورة الشهرية.',
                                  ),
                                  items: [
                                    for (var day = 20; day <= 28; day++)
                                      DropdownMenuItem(value: '$day', child: Text('يوم $day')),
                                    const DropdownMenuItem(
                                      value: 'last_day',
                                      child: Text('آخر يوم في الشهر'),
                                    ),
                                  ],
                                  onChanged: (value) => _update(
                                    context,
                                    settings.copyWith(readingDay: value),
                                  ),
                                ),
                              ],
                              const SizedBox(height: 16),
                              Directionality(
                                textDirection: TextDirection.ltr,
                                child: TextFormField(
                                  controller: _graceController,
                                  keyboardType: TextInputType.number,
                                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                                  decoration: const InputDecoration(
                                    labelText: 'مدة السماح (أيام)',
                                    helperText: 'الأيام بعد إصدار الفاتورة قبل اعتبارها متأخرة.',
                                    border: OutlineInputBorder(),
                                  ),
                                  onChanged: (value) => _update(
                                    context,
                                    settings.copyWith(gracePeriod: int.tryParse(value) ?? 0),
                                  ),
                                ),
                              ),
                              SwitchListTile(
                                value: settings.strictOverdueOnNewInvoice,
                                onChanged: (value) => _update(
                                  context,
                                  settings.copyWith(strictOverdueOnNewInvoice: value),
                                ),
                                title: const Text('اعتبار الفاتورة السابقة متأخرة عند إصدار فاتورة جديدة'),
                                subtitle: const Text('يتجاوز هذا الخيار مدة السماح المتبقية.'),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
          bottomNavigationBar: settings == null
              ? null
              : SettingsSaveBar(
                  isSaving: state.isSaving,
                  onSave: () => context.read<ReadingSettingsCubit>().save(),
                ),
        );
      },
    );
  }

  void _update(BuildContext context, ReadingSettings settings) {
    context.read<ReadingSettingsCubit>().update(settings);
  }
}
