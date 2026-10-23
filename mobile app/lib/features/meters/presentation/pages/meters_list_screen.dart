import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/widgets/search_text_field.dart';
import '../../../readings/data/services/reading_invoice_actions_service.dart';
import '../../../readings/domain/readings_repository.dart';
import '../../../readings/presentation/widgets/record_reading_sheet.dart';
import '../../data/models/meter.dart';
import '../../domain/meters_repository.dart';
import '../cubit/meters_cubit.dart';
import '../widgets/meter_card_widget.dart';
import 'meter_form_screen.dart';

class MetersListScreen extends StatelessWidget {
  const MetersListScreen({
    required this.metersRepository,
    required this.readingsRepository,
    this.invoiceActions,
    this.onOpenFieldReader,
    super.key,
  });

  final MetersRepository metersRepository;
  final ReadingsRepository readingsRepository;
  final ReadingInvoiceActionsService? invoiceActions;
  final VoidCallback? onOpenFieldReader;

  @override
  Widget build(BuildContext context) {
    return BlocProvider(
      create: (_) => MetersCubit(metersRepository)..load(),
      child: _MetersListView(
        metersRepository: metersRepository,
        readingsRepository: readingsRepository,
        invoiceActions: invoiceActions,
        onOpenFieldReader: onOpenFieldReader,
      ),
    );
  }
}

class _MetersListView extends StatelessWidget {
  const _MetersListView({
    required this.metersRepository,
    required this.readingsRepository,
    this.invoiceActions,
    this.onOpenFieldReader,
  });

  final MetersRepository metersRepository;
  final ReadingsRepository readingsRepository;
  final ReadingInvoiceActionsService? invoiceActions;
  final VoidCallback? onOpenFieldReader;

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<MetersCubit>();
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('إدارة العدادات'),
          actions: [
            if (onOpenFieldReader != null)
              IconButton(
                tooltip: 'القارئ الميداني',
                onPressed: onOpenFieldReader,
                icon: const Icon(Icons.qr_code_scanner),
              ),
          ],
        ),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () => _openForm(context),
          icon: const Icon(Icons.add),
          label: const Text('عداد جديد'),
        ),
        body: BlocConsumer<MetersCubit, MetersState>(
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
                  SliverToBoxAdapter(child: _filters(context, state, cubit)),
                  if (state.status == MetersLoadStatus.loading && state.meters.isEmpty)
                    const SliverFillRemaining(
                      hasScrollBody: false,
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else if (state.status == MetersLoadStatus.failure && state.meters.isEmpty)
                    SliverFillRemaining(
                      hasScrollBody: false,
                      child: _EmptyState(
                        icon: Icons.wifi_off_outlined,
                        title: 'تعذر تحميل العدادات',
                        subtitle: state.errorMessage ?? 'تحقق من اتصال الإنترنت ثم أعد المحاولة.',
                        action: TextButton.icon(
                          onPressed: cubit.load,
                          icon: const Icon(Icons.refresh),
                          label: const Text('إعادة المحاولة'),
                        ),
                      ),
                    )
                  else if (state.meters.isEmpty)
                    const SliverFillRemaining(
                      hasScrollBody: false,
                      child: _EmptyState(
                        icon: Icons.speed_outlined,
                        title: 'لا توجد عدادات مطابقة',
                        subtitle: 'غيّر البحث أو الفلاتر، أو أضف عداداً جديداً.',
                      ),
                    )
                  else
                    SliverLayoutBuilder(
                      builder: (context, constraints) {
                        final width = constraints.crossAxisExtent;
                        final columns = width >= 1000 ? 3 : (width >= 650 ? 2 : 1);
                        if (columns == 1) {
                          return SliverList.builder(
                            itemCount: state.meters.length,
                            itemBuilder: (context, index) => _meterCard(
                              context,
                              state.meters[index],
                            ),
                          );
                        }
                        return SliverPadding(
                          padding: const EdgeInsets.fromLTRB(8, 4, 8, 92),
                          sliver: SliverGrid.builder(
                            itemCount: state.meters.length,
                            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                              crossAxisCount: columns,
                              crossAxisSpacing: 4,
                              mainAxisSpacing: 4,
                              mainAxisExtent: 230,
                            ),
                            itemBuilder: (context, index) => _meterCard(
                              context,
                              state.meters[index],
                            ),
                          ),
                        );
                      },
                    ),
                  const SliverToBoxAdapter(child: SizedBox(height: 88)),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Widget _filters(BuildContext context, MetersState state, MetersCubit cubit) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
      child: Column(
        children: [
          SearchTextField(
            query: state.query,
            hintText: 'ابحث بالعداد أو المشترك أو المربع/المسار 4/1',
            onChanged: cubit.setQuery,
          ),
          const SizedBox(height: 10),
          LayoutBuilder(
            builder: (context, constraints) {
              final children = [
                DropdownButtonFormField<int?>(
                  key: ValueKey<String>('square-${state.squareFilter}'),
                  initialValue: state.squareFilter,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'المربع السكني',
                    border: OutlineInputBorder(),
                    isDense: true,
                  ),
                  items: [
                    const DropdownMenuItem<int?>(value: null, child: Text('كل المربعات')),
                    ...state.squares.map((square) => DropdownMenuItem<int?>(
                          value: square.id,
                          child: Text(square.name, overflow: TextOverflow.ellipsis),
                        )),
                  ],
                  onChanged: cubit.setSquareFilter,
                ),
                DropdownButtonFormField<String?>(
                  key: ValueKey<String>('status-${state.statusFilter}'),
                  initialValue: state.statusFilter,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'الحالة',
                    border: OutlineInputBorder(),
                    isDense: true,
                  ),
                  items: const [
                    DropdownMenuItem<String?>(value: null, child: Text('كل الحالات')),
                    DropdownMenuItem<String?>(value: 'active', child: Text('نشط')),
                    DropdownMenuItem<String?>(value: 'inactive', child: Text('غير نشط')),
                  ],
                  onChanged: cubit.setStatusFilter,
                ),
              ];
              if (constraints.maxWidth >= 600) {
                return Row(
                  children: [
                    Expanded(child: children[0]),
                    const SizedBox(width: 10),
                    Expanded(child: children[1]),
                  ],
                );
              }
              return Column(
                children: [children[0], const SizedBox(height: 10), children[1]],
              );
            },
          ),
          if (state.status == MetersLoadStatus.loading && state.meters.isNotEmpty)
            const LinearProgressIndicator(minHeight: 2),
        ],
      ),
    );
  }

  Widget _meterCard(BuildContext context, Meter meter) {
    return MeterCardWidget(
      meter: meter,
      onRecordReading: () => _recordReading(context, meter),
      onEdit: () => _openForm(context, meter),
    );
  }

  Future<void> _recordReading(BuildContext context, Meter meter) async {
    await RecordReadingSheet.show(
      context,
      meter: meter,
      readingsRepository: readingsRepository,
      invoiceActions: invoiceActions,
      onNextPath: context.read<MetersCubit>().nextPath,
    );
    if (context.mounted) await context.read<MetersCubit>().load();
  }

  Future<void> _openForm(BuildContext context, [Meter? meter]) async {
    final changed = await Navigator.of(context).push<bool>(
      MaterialPageRoute<bool>(
        builder: (_) => MeterFormScreen(
          repository: metersRepository,
          meter: meter,
        ),
      ),
    );
    if (changed == true && context.mounted) {
      await context.read<MetersCubit>().load();
    }
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({
    required this.icon,
    required this.title,
    required this.subtitle,
    this.action,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 48, color: Theme.of(context).colorScheme.primary),
              const SizedBox(height: 14),
              Text(title, style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 6),
              Text(subtitle, textAlign: TextAlign.center),
              if (action != null) ...[const SizedBox(height: 12), action!],
            ],
          ),
        ),
      );
}
