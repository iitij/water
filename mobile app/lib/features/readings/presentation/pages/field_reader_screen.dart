import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/database/offline_pack_downloader.dart';
import '../../../../core/l10n/field_experience_strings.dart';
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
    this.initialMeterQuery,
    super.key,
  });

  final MetersRepository metersRepository;
  final ReadingsRepository readingsRepository;
  final ReadingInvoiceActionsService? invoiceActions;
  final OfflinePackDownloader? offlinePackDownloader;

  /// استعلام ابتدائي يُعبّئ حقل البحث (يأتي من Route Mode لتفتح المحطة المختارة).
  final String? initialMeterQuery;

  @override
  Widget build(BuildContext context) => BlocProvider(
    create: (_) =>
        MetersCubit(metersRepository)..setQuery(initialMeterQuery ?? ''),
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
        title: FieldExperienceStrings.title,
        subtitle: FieldExperienceStrings.subtitle,
        actions: [
          BlocBuilder<MetersCubit, MetersState>(
            buildWhen: (prev, curr) =>
                prev.squares != curr.squares ||
                prev.squareFilter != curr.squareFilter,
            builder: (context, state) => AppIconButton(
              icon: Icons.cloud_download_outlined,
              tooltip: FieldExperienceStrings.downloadPack,
              size: 48,
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
            tooltip: FieldExperienceStrings.scanMeter,
            size: 48,
            onPressed: () => _scan(context, cubit),
          ),
        ],
        child: BlocConsumer<MetersCubit, MetersState>(
          listenWhen: (previous, current) =>
              previous.errorMessage != current.errorMessage &&
              current.errorMessage != null,
          listener: (context, state) => ScaffoldMessenger.of(
            context,
          ).showSnackBar(SnackBar(content: Text(state.errorMessage!))),
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
                        _FieldWorkspaceHeader(state: state),
                        AppFilterBar(
                          query: state.query,
                          hint: FieldExperienceStrings.searchHint,
                          onChanged: cubit.setQuery,
                          activeCount: state.squareFilter == null ? 0 : 1,
                          onOpenFilters: () => _openSquareSheet(context, state),
                        ),
                        Padding(
                          padding: const EdgeInsetsDirectional.fromSTEB(
                            16,
                            0,
                            16,
                            16,
                          ),
                          child: Text(
                            FieldExperienceStrings.searchHelp,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ),
                        if (state.status == MetersLoadStatus.loading &&
                            state.meters.isNotEmpty)
                          const Padding(
                            padding: EdgeInsetsDirectional.fromSTEB(
                              16,
                              4,
                              16,
                              4,
                            ),
                            child: LinearProgressIndicator(minHeight: 2),
                          ),
                      ],
                    ),
                  ),
                  if (state.status == MetersLoadStatus.loading &&
                      state.meters.isEmpty)
                    const SliverFillRemaining(
                      hasScrollBody: false,
                      child: Center(
                        child: AppLoader(label: FieldExperienceStrings.loading),
                      ),
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
                                  ? FieldExperienceStrings.noMeters
                                  : FieldExperienceStrings.loadFailed,
                              message:
                                  state.errorMessage ??
                                  FieldExperienceStrings.emptyHelp,
                            ),
                            AppSecondaryButton(
                              label: FieldExperienceStrings.reload,
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
                          onRecordReading: () =>
                              _openReader(context, meter, cubit),
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
      builder: (_) =>
          _SquareFilterSheet(state: state, cubit: context.read<MetersCubit>()),
    );
  }

  Future<void> _openReader(
    BuildContext context,
    Meter meter,
    MetersCubit cubit,
  ) async {
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
          title: FieldExperienceStrings.scanMeter,
          hint: FieldExperienceStrings.scanHint,
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
      title: FieldExperienceStrings.filterSquare,
      resultCountLabel: FieldExperienceStrings.resultCount(
        widget.state.meters.length,
      ),
      onApply: _apply,
      onReset: () => setState(() => _square = null),
      resetEnabled: _square != null,
      sections: <Widget>[
        AppFilterSection(
          title: FieldExperienceStrings.square,
          subtitle: FieldExperienceStrings.squareHelp,
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
                  label: FieldExperienceStrings.allSquares,
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

/// Count describes the returned list, never invented route progress.
class _FieldWorkspaceHeader extends StatelessWidget {
  const _FieldWorkspaceHeader({required this.state});
  final MetersState state;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    String scope = FieldExperienceStrings.allSquares;
    for (final square in state.squares) {
      if (square.id == state.squareFilter) scope = square.name;
    }
    final hasCount =
        state.meters.isNotEmpty || state.status == MetersLoadStatus.success;
    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 12, 16, 14),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  FieldExperienceStrings.matchingMeters,
                  style: theme.textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 3),
                Text(scope, style: theme.textTheme.bodySmall),
              ],
            ),
          ),
          if (hasCount)
            Container(
              constraints: const BoxConstraints(minWidth: 52, minHeight: 48),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              decoration: BoxDecoration(
                color: theme.colorScheme.primaryContainer,
                borderRadius: BorderRadius.circular(14),
              ),
              child: Directionality(
                textDirection: TextDirection.ltr,
                child: Text(
                  '${state.meters.length}',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.headlineSmall?.copyWith(
                    color: theme.colorScheme.onPrimaryContainer,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
