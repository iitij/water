import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../../../core/widgets/filled_dropdown.dart';
import '../../../../core/widgets/search_text_field.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
import '../../../meters/data/models/meter.dart';
import '../../../meters/domain/meters_repository.dart';
import '../../../meters/presentation/cubit/meters_cubit.dart';
import '../../../meters/presentation/widgets/meter_card_widget.dart';
import '../../data/models/reading.dart';
import '../../data/services/reading_invoice_actions_service.dart';
import '../../domain/readings_repository.dart';
import 'submit_reading_screen.dart';

class FieldReaderScreen extends StatelessWidget {
  const FieldReaderScreen({
    required this.metersRepository,
    required this.readingsRepository,
    this.invoiceActions,
    super.key,
  });

  final MetersRepository metersRepository;
  final ReadingsRepository readingsRepository;
  final ReadingInvoiceActionsService? invoiceActions;

  @override
  Widget build(BuildContext context) => BlocProvider(
        create: (_) => MetersCubit(metersRepository)..load(),
        child: _FieldReaderView(
          readingsRepository: readingsRepository,
          invoiceActions: invoiceActions,
        ),
      );
}

class _FieldReaderView extends StatelessWidget {
  const _FieldReaderView({
    required this.readingsRepository,
    this.invoiceActions,
  });

  final ReadingsRepository readingsRepository;
  final ReadingInvoiceActionsService? invoiceActions;

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<MetersCubit>();
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('القارئ الميداني'),
          actions: [
            IconButton(
              tooltip: 'مسح باركود العداد',
              onPressed: () => _scan(context, cubit),
              icon: const Icon(Icons.qr_code_scanner),
            ),
          ],
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
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(12, 12, 12, 6),
                      child: Column(
                        children: [
                          SearchTextField(
                            query: state.query,
                            hintText: 'المربع/المسار 4/1 أو رقم العداد أو الاسم',
                            onChanged: cubit.setQuery,
                          ),
                          const SizedBox(height: 10),
                          FilledDropdown<int?>(
                            value: state.squareFilter,
                            label: 'فلترة بالمربع السكني',
                            prefixIcon: const Icon(Icons.location_city_outlined),
                            items: [
                              const DropdownMenuItem<int?>(
                                value: null,
                                child: Text('كل المربعات'),
                              ),
                              ...state.squares.map((square) => DropdownMenuItem<int?>(
                                    value: square.id,
                                    child: Text(square.name),
                                  )),
                            ],
                            onChanged: cubit.setSquareFilter,
                          ),
                          if (state.status == MetersLoadStatus.loading && state.meters.isNotEmpty)
                            const Padding(
                              padding: EdgeInsets.only(top: 8),
                              child: ShimmerSkeleton(height: 4),
                            ),
                        ],
                      ),
                    ),
                  ),
                  if (state.status == MetersLoadStatus.loading && state.meters.isEmpty)
                    SliverFillRemaining(
                      hasScrollBody: false,
                      child: ListView(
                        padding: const EdgeInsets.all(12),
                        children: List.generate(6, (_) => ShimmerSkeleton(height: 230)),
                      ),
                    )
                  else if (state.meters.isEmpty)
                    SliverFillRemaining(
                      hasScrollBody: false,
                      child: _FieldEmptyState(
                        title: state.errorMessage == null ? 'لا توجد عدادات' : 'تعذر تحميل العدادات',
                        subtitle: state.errorMessage ?? 'حمّل بيانات المربع قبل بدء الجولة الميدانية.',
                        onRetry: cubit.load,
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
                  const SliverToBoxAdapter(child: SizedBox(height: 24)),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Future<void> _openReader(BuildContext context, Meter meter, MetersCubit cubit) async {
    await Navigator.of(context).push<ReadingSaveResult>(
      MaterialPageRoute(
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
      MaterialPageRoute(builder: (_) => const MeterBarcodeScannerScreen()),
    );
    if (code != null && context.mounted) cubit.setQuery(code);
  }
}

class MeterBarcodeScannerScreen extends StatefulWidget {
  const MeterBarcodeScannerScreen({super.key});

  @override
  State<MeterBarcodeScannerScreen> createState() => _MeterBarcodeScannerScreenState();
}

class _MeterBarcodeScannerScreenState extends State<MeterBarcodeScannerScreen> {
  bool _didReturn = false;

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(
          appBar: AppBar(title: const Text('مسح باركود العداد')),
          body: Stack(
            fit: StackFit.expand,
            children: [
              MobileScanner(
                onDetect: (capture) {
                  if (_didReturn) return;
                  for (final barcode in capture.barcodes) {
                    final value = barcode.rawValue?.trim();
                    if (value != null && value.isNotEmpty) {
                      _didReturn = true;
                      Navigator.of(context).pop(value);
                      break;
                    }
                  }
                },
              ),
              IgnorePointer(
                child: Center(
                  child: Container(
                    width: 280,
                    height: 170,
                    decoration: BoxDecoration(
                      border: Border.all(color: Colors.white, width: 3),
                      borderRadius: BorderRadius.circular(20),
                    ),
                  ),
                ),
              ),
              const Positioned(
                left: 24,
                right: 24,
                bottom: 36,
                child: Card(
                  color: Color(0xDD102A34),
                  child: Padding(
                    padding: EdgeInsets.all(12),
                    child: Text(
                      'وجّه الكاميرا إلى باركود أو QR الموجود على العداد.',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: Colors.white),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      );
}

class _FieldEmptyState extends StatelessWidget {
  const _FieldEmptyState({
    required this.title,
    required this.subtitle,
    required this.onRetry,
  });

  final String title;
  final String subtitle;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.water_drop_outlined, size: 50),
              const SizedBox(height: 12),
              Text(title, style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              Text(subtitle, textAlign: TextAlign.center),
              const SizedBox(height: 12),
              FilledButton.tonalIcon(
                onPressed: onRetry,
                icon: const Icon(Icons.refresh),
                label: const Text('إعادة التحميل'),
              ),
            ],
          ),
        ),
      );
}
