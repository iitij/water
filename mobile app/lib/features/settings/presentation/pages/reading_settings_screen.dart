import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/l10n/laravel_screen_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
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
          showAdminSnackBar(context, 'تم حفظ جدول زيارات القراءات وتحديث كاش الوحدات الميدانية.');
        }
      },
      builder: (context, state) {
        final settings = state.settings;
        if (settings != null && !_hydrated) {
          _graceController.text = settings.gracePeriod.toString();
          _hydrated = true;
        }
        final body = state.isLoading && settings == null
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
                          title: 'جدول زيارات قارئ العدادات',
                          subtitle: 'تحدد موعد الزيارة المتوقع فقط؛ تبدأ فترة الاستهلاك بالقراءة السابقة وتنتهي بالحالية.',
                          children: [
                            AppSettingRow(
                              icon: Icons.receipt_long_outlined,
                              color: Theme.of(context).colorScheme.primary,
                              showChevron: false,
                              title: 'إصدار الفاتورة تلقائياً عند إدخال القراءة',
                              subtitle: 'إنشاء فاتورة العداد فور حفظ قراءة جديدة.',
                              trailing: Switch(
                                value: settings.autoIssueInvoiceOnReading,
                                onChanged: (value) => _update(
                                  context,
                                  settings.copyWith(autoIssueInvoiceOnReading: value),
                                ),
                              ),
                            ),
                            const AppDivider(),
                            DropdownButtonFormField<String>(
                              isExpanded: true,
                              key: ValueKey(settings.readingPeriod),
                              initialValue: settings.readingPeriod,
                              decoration: const InputDecoration(
                                labelText: 'وتيرة زيارات القراءة',
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
                                isExpanded: true,
                                key: ValueKey(settings.readingDay),
                                initialValue: settings.readingDay,
                                decoration: const InputDecoration(
                                  labelText: 'يوم إدخال القراءة',
                                  helperText: 'موعد الزيارة الشهري المتوقع، وليس نهاية الفترة المحاسبية.',
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
                                ),
                                onChanged: (value) => _update(
                                  context,
                                  settings.copyWith(gracePeriod: int.tryParse(value) ?? 0),
                                ),
                              ),
                            ),
                            const SizedBox(height: 8),
                            AppSettingRow(
                              icon: Icons.rule_rounded,
                              color: Theme.of(context).colorScheme.primary,
                              showChevron: false,
                              title: 'اعتبار الفاتورة السابقة متأخرة عند إصدار فاتورة جديدة',
                              subtitle: 'يتجاوز هذا الخيار مدة السماح المتبقية.',
                              trailing: Switch(
                                value: settings.strictOverdueOnNewInvoice,
                                onChanged: (value) => _update(
                                  context,
                                  settings.copyWith(strictOverdueOnNewInvoice: value),
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  );
        final saveBar = settings == null
            ? null
            : SettingsSaveBar(
                isSaving: state.isSaving,
                onSave: () => context.read<ReadingSettingsCubit>().save(),
              );
        if (widget.embedded) {
          return Scaffold(body: body, bottomNavigationBar: saveBar);
        }
        return SubPageScaffold(
          title: LaravelScreenStrings.readingSettings,
          subtitle: 'جدولة الزيارات والفوترة الآلية',
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

  void _update(BuildContext context, ReadingSettings settings) {
    context.read<ReadingSettingsCubit>().update(settings);
  }
}
