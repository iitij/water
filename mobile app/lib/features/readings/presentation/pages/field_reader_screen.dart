import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/database/offline_pack_downloader.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/widgets/barcode_scanner_screen.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../../meters/data/models/meter.dart';
import '../../../meters/domain/meters_repository.dart';
import '../../../meters/presentation/cubit/meters_cubit.dart';
import '../../../meters/presentation/widgets/meter_card_widget.dart';
import '../../data/models/reading.dart';
import '../../data/services/reading_invoice_actions_service.dart';
import '../../domain/readings_repository.dart';
import '../widgets/offline_pack_dialog.dart';
import 'submit_reading_screen.dart';

class FieldReaderScreen extends StatelessWidget {
  const FieldReaderScreen({
    required this.metersRepository,
    required this.readingsRepository,
    this.invoiceActions,
    this.offlinePackDownloader,
    super.key,
  });

  final MetersRepository metersRepository;
  final ReadingsRepository readingsRepository;
  final ReadingInvoiceActionsService? invoiceActions;
  final OfflinePackDownloader? offlinePackDownloader;

  @override
  Widget build(BuildContext context) => BlocProvider(
        create: (_) => MetersCubit(metersRepository)..load(),
        child: _FieldReaderView(
          readingsRepository: readingsRepository,
          invoiceActions: invoiceActions,
          offlinePackDownloader: offlinePackDownloader,
        ),
      );
}

class _FieldReaderView extends StatelessWidget {
  const _FieldReaderView({
    required this.readingsRepository,
    this.invoiceActions,
    this.offlinePackDownloader,
  });

  final ReadingsRepository readingsRepository;
  final ReadingInvoiceActionsService? invoiceActions;
  final OfflinePackDownloader? offlinePackDownloader;

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<MetersCubit>();
    return Directionality(
      textDirection: TextDirection.rtl,
      child: SubPageScaffold(
        title: 'القارئ الميداني',
        actions: [
          BlocBuilder<MetersCubit, MetersState>(
            buildWhen: (prev, curr) =>
                prev.squares != curr.squares || prev.squareFilter != curr.squareFilter,
            builder: (context, state) => AppIconButton(
              icon: Icons.cloud_download_outlined,
              tooltip: 'تنزيل حزمة العمل أوفلاين',
              size: 38,
              onPressed: () async {
                final res = await OfflinePackDialog.show(
                  context,
                  downloader: offlinePackDownloader,
                  squares: state.squares,
                  initialSquareId: state.squareFilter,
                );
                if (res != null && context.mounted) {
                  await cubit.load();
                }
              },
            ),
          ),
          AppIconButton(
            icon: Icons.qr_code_scanner_rounded,
            tooltip: 'مسح باركود العداد',
            size: 38,
            onPressed: () => _scan(context, cubit),
          ),
        ],
        child: BlocConsumer<MetersCubit, MetersState>(
          listenWhen: (previous, current) =>
              previous.errorMessage != current.errorMessage && current.errorMessage != null,
          listener: (context, state) => ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(state.errorMessage!)),
          ),
          builder: (context, state) {
            return RefreshIndicator(
              onRefresh: cubit.load,
              child: CustomScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                slivers: [
                  SliverToBoxAdapter(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        AppFilterBar(
                          query: state.query,
                          hint: 'المربع/المسار 4/1 أو رقم العداد أو الاسم',
                          onChanged: cubit.setQuery,
                          activeCount: state.squareFilter == null ? 0 : 1,
                          onOpenFilters: () => _openSquareSheet(context, state),
                        ),
                        if (state.status == MetersLoadStatus.loading && state.meters.isNotEmpty)
                          const Padding(
                            padding: EdgeInsetsDirectional.fromSTEB(16, 4, 16, 4),
                            child: LinearProgressIndicator(minHeight: 2),
                          ),
                      ],
                    ),
                  ),
                  if (state.status == MetersLoadStatus.loading && state.meters.isEmpty)
                    const SliverFillRemaining(
                      hasScrollBody: false,
                      child: Center(child: AppLoader(label: 'جارٍ تحميل العدادات…')),
                    )
                  else if (state.meters.isEmpty)
                    SliverFillRemaining(
                      hasScrollBody: false,
                      child: Center(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: <Widget>[
                            AdminEmptyView(
                              icon: state.errorMessage == null
                                  ? Icons.water_drop_outlined
                                  : Icons.cloud_off_outlined,
                              title: state.errorMessage == null
                                  ? 'لا توجد عدادات'
                                  : 'تعذر تحميل العدادات',
                              message: state.errorMessage ??
                                  'حمّل بيانات المربع قبل بدء الجولة الميدانية.',
                            ),
                            AppSecondaryButton(
                              label: 'إعادة التحميل',
                              icon: Icons.refresh_rounded,
                              onPressed: cubit.load,
                              expand: false,
                            ),
                          ],
                        ),
                      ),
                    )
                  else
                    SliverList.builder(
                      itemCount: state.meters.length,
                      itemBuilder: (context, index) {
                        final meter = state.meters[index];
                        return MeterCardWidget(
                          meter: meter,
                          onTap: () => _openReader(context, meter, cubit),
                          onRecordReading: () => _openReader(context, meter, cubit),
                        );
                      },
                    ),
                  const SliverToBoxAdapter(child: SizedBox(height: 32)),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Future<void> _openSquareSheet(BuildContext context, MetersState state) async {
    await AppFilterSheet.show<void>(
      context: context,
      builder: (_) => _SquareFilterSheet(state: state, cubit: context.read<MetersCubit>()),
    );
  }

  Future<void> _openReader(BuildContext context, Meter meter, MetersCubit cubit) async {
    await Navigator.of(context).push<ReadingSaveResult>(
      MaterialPageRoute<ReadingSaveResult>(
        builder: (_) => SubmitReadingScreen(
          meter: meter,
          readingsRepository: readingsRepository,
          invoiceActions: invoiceActions,
          onNextPath: cubit.nextPath,
        ),
      ),
    );
    if (context.mounted) await cubit.load();
  }

  Future<void> _scan(BuildContext context, MetersCubit cubit) async {
    final code = await Navigator.of(context).push<String>(
      MaterialPageRoute<String>(
        builder: (_) => const BarcodeScannerScreen(
          title: 'مسح باركود العداد',
          hint: 'وجّه الكاميرا إلى باركود أو QR الموجود على العداد.',
        ),
      ),
    );
    if (code != null && context.mounted) cubit.setQuery(code);
  }
}

/// ورقة اختيار المربع السكني — مسودة داخلية تُطبَّق مرة واحدة عند «عرض النتائج».
class _SquareFilterSheet extends StatefulWidget {
  const _SquareFilterSheet({required this.state, required this.cubit});

  final MetersState state;
  final MetersCubit cubit;

  @override
  State<_SquareFilterSheet> createState() => _SquareFilterSheetState();
}

class _SquareFilterSheetState extends State<_SquareFilterSheet> {
  late int? _square = widget.state.squareFilter;

  void _apply() {
    if (_square != widget.state.squareFilter) {
      widget.cubit.setSquareFilter(_square);
    }
    Navigator.of(context).maybePop();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return AppFilterSheet(
      title: 'فلترة بالمربع السكني',
      resultCountLabel: '${widget.state.meters.length} عداد مطابق',
      onApply: _apply,
      onReset: () => setState(() => _square = null),
      resetEnabled: _square != null,
      sections: <Widget>[
        AppFilterSection(
          title: 'المربع السكني',
          subtitle: 'اختر مربعاً واحداً أو ابحث في كل المربعات',
          child: Container(
            constraints: const BoxConstraints(maxHeight: 216),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(AppRadius.md),
              border: Border.all(color: theme.colorScheme.outlineVariant),
            ),
            child: ListView(
              padding: const EdgeInsets.all(6),
              shrinkWrap: true,
              children: <Widget>[
                AppFilterOptionTile(
                  label: 'كل المربعات',
                  icon: Icons.apps_rounded,
                  selected: _square == null,
                  onTap: () => setState(() => _square = null),
                ),
                const SizedBox(height: 6),
                for (final square in widget.state.squares) ...<Widget>[
                  AppFilterOptionTile(
                    label: square.name,
                    icon: Icons.location_city_outlined,
                    selected: _square == square.id,
                    onTap: () => setState(() => _square = square.id),
                  ),
                  const SizedBox(height: 6),
                ],
              ],
            ),
          ),
        ),
        const SizedBox(height: 20),
      ],
    );
  }
}
