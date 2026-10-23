import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/finance/finance_colors.dart';
import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/finance/finance_search.dart';
import '../../../../core/permissions/finance_permissions.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../data/models/tariff_model.dart';
import '../../domain/repositories/tariffs_repository.dart';
import '../cubit/tariffs_cubit.dart';
import '../sheets/tariff_form_sheet.dart';

/// `SCR-21` — إدارة التسعيرات، مطابقةً لـ `TariffResource` في Filament:
/// قائمة + سعر نشط في الترويسة + `TrashedFilter` (الحية/الكل/المحذوفة) +
/// استعادة/حذف نهائي، مع نموذج الإنشاء والتعديل في درج سفلي.
class TariffsScreen extends StatelessWidget {
  const TariffsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<TariffsCubit>(
      create: (_) => TariffsCubit(repository: sl<TariffsRepository>())..load(),
      child: const _TariffsBody(),
    );
  }
}

class _TariffsBody extends StatefulWidget {
  const _TariffsBody();

  @override
  State<_TariffsBody> createState() => _TariffsBodyState();
}

class _TariffsBodyState extends State<_TariffsBody> {
  final TextEditingController _search = TextEditingController();

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<TariffsCubit>();

    return Scaffold(
      appBar: AppBar(
        title: const Text('التسعيرات'),
        actions: <Widget>[
          IconButton(
            tooltip: 'إعادة تحميل',
            onPressed: () => cubit.load(refresh: true),
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      floatingActionButton: context.canManageTariffs
          ? FloatingActionButton.extended(
              onPressed: () async {
                await TariffFormSheet.show(context);
              },
              icon: const Icon(Icons.add),
              label: const Text('تسعيرة جديدة'),
            )
          : null,
      body: SafeArea(
        child: BlocBuilder<TariffsCubit, TariffsState>(
          builder: (context, state) {
            final tariffs = state.visible;

            return Column(
              children: <Widget>[
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 4),
                  child: FinanceSearchBar(
                    controller: _search,
                    hint: 'ابحث باسم التسعيرة',
                    onChanged: cubit.search,
                    trailing: state.search.isEmpty
                        ? null
                        : IconButton(
                            icon: const Icon(Icons.clear),
                            onPressed: () {
                              _search.clear();
                              cubit.search('');
                            },
                          ),
                  ),
                ),
                _ActivePriceHeader(state: state),
                _TrashFilterRow(cubit: cubit, state: state),
                Expanded(
                  child: _buildList(context, cubit, state, tariffs),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _buildList(
    BuildContext context,
    TariffsCubit cubit,
    TariffsState state,
    List<TariffModel> tariffs,
  ) {
    if (state.isLoading && tariffs.isEmpty) {
      return const SkeletonList(count: 5, lines: 1);
    }

    if (state.status == TariffsLoadStatus.failure && tariffs.isEmpty) {
      return ErrorStateWidget(
        message: state.errorMessage ?? 'تعذّر تحميل التسعيرات.',
        onRetry: () => cubit.load(refresh: true),
      );
    }

    if (tariffs.isEmpty) {
      return ListView(
        children: <Widget>[
          const SizedBox(height: 60),
          EmptyStateWidget(
            title: state.search.isEmpty ? 'لا توجد تسعيرات' : 'لا نتائج للبحث',
            message: state.showsTrashed
                ? 'لا تسعيرات محذوفة.'
                : 'أضف تسعيرة جديدة لتبدأ الفوترة التلقائية.',
            icon: Icons.sell_outlined,
            compact: true,
          ),
        ],
      );
    }

    return RefreshIndicator(
      onRefresh: () => cubit.load(refresh: true),
      child: ListView.builder(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 88),
        itemCount: tariffs.length,
        itemBuilder: (context, index) {
          final tariff = tariffs[index];
          return TariffCard(
            tariff: tariff,
            isActive: state.activeTariff?.id == tariff.id,
            busy: state.busyId == tariff.id,
            onTap: () => TariffFormSheet.show(context, tariff: tariff),
            onSoftDelete: !tariff.isTrashed && context.canManageTariffs
                ? () => _softDelete(cubit, tariff)
                : null,
            onRestore: tariff.isTrashed ? () => _restore(cubit, tariff) : null,
            onForceDelete: tariff.isTrashed ? () => _forceDelete(cubit, tariff) : null,
          );
        },
      ),
    );
  }

  Future<void> _softDelete(TariffsCubit cubit, TariffModel tariff) async {
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'حذف التسعيرة (سلة المحذوفات)',
      message: 'ستُنقل التسعيرة إلى سلة المحذوفات ويمكن استعادتها لاحقاً. الفواتير السابقة لن تتأثر.',
      details: <String>[tariff.name, CurrencyFormatter.withLabel(tariff.unitPrice)],
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (!confirmed || !mounted) return;

    final ok = await cubit.softDelete(tariff.id);
    if (!mounted) return;
    if (ok) {
      AppFeedback.success(context, 'تم نقل «${tariff.name}» إلى سلة المحذوفات.');
    } else {
      AppFeedback.error(context, cubit.state.errorMessage ?? 'تعذّر حذف التسعيرة.');
    }
  }

  Future<void> _restore(TariffsCubit cubit, TariffModel tariff) async {
    final ok = await cubit.restore(tariff.id);
    if (!mounted) return;
    if (ok) {
      AppFeedback.success(context, 'تمت استعادة «${tariff.name}».');
    } else {
      AppFeedback.error(context, cubit.state.errorMessage ?? 'تعذّرت الاستعادة.');
    }
  }

  Future<void> _forceDelete(TariffsCubit cubit, TariffModel tariff) async {
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'حذف نهائي للتسعيرة',
      message: 'لا يمكن التراجع عن الحذف النهائي. الفواتير السابقة لا تتأثر.',
      details: <String>[tariff.name, CurrencyFormatter.withLabel(tariff.unitPrice)],
      confirmLabel: 'حذف نهائي',
      destructive: true,
    );
    if (!confirmed || !mounted) return;

    final ok = await cubit.forceDelete(tariff.id);
    if (!mounted) return;
    if (ok) {
      AppFeedback.success(context, 'تم حذف «${tariff.name}» نهائياً.');
    } else {
      AppFeedback.error(context, cubit.state.errorMessage ?? 'تعذّر الحذف النهائي.');
    }
  }
}

class _ActivePriceHeader extends StatelessWidget {
  const _ActivePriceHeader({required this.state});

  final TariffsState state;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(12, 8, 12, 0),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        gradient: FinanceColors.headerGradient,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: <Widget>[
          const Icon(Icons.bolt, color: Colors.white, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              'السعر النشط المستخدم في الفوترة',
              style: TextStyle(fontSize: 12, color: Colors.white.withValues(alpha: 0.85)),
            ),
          ),
          Text(
            state.activePrice == null
                ? '—'
                : CurrencyFormatter.withLabel(state.activePrice),
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w800,
              color: Colors.white,
            ),
          ),
        ],
      ),
    );
  }
}

class _TrashFilterRow extends StatelessWidget {
  const _TrashFilterRow({required this.cubit, required this.state});

  final TariffsCubit cubit;
  final TariffsState state;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
      child: SegmentedButton<TariffsTrashFilter>(
        segments: TariffsTrashFilter.values
            .map(
              (filter) => ButtonSegment<TariffsTrashFilter>(
                value: filter,
                label: Text(filter.label),
                icon: Icon(
                  filter == TariffsTrashFilter.only
                      ? Icons.delete_outline
                      : (filter == TariffsTrashFilter.withTrashed ? Icons.filter_alt : Icons.verified_outlined),
                ),
              ),
            )
            .toList(),
        selected: <TariffsTrashFilter>{state.trashFilter},
        showSelectedIcon: false,
        style: const ButtonStyle(visualDensity: VisualDensity.compact),
        onSelectionChanged: (selection) => cubit.setTrashFilter(selection.first),
      ),
    );
  }
}
