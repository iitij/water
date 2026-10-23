import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/widgets/app_text_field.dart';
import '../../../../core/widgets/search_text_field.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../../service_requests/presentation/cubit/service_requests_cubit.dart';
import '../../../subscribers/domain/entities/subscriber.dart';
import '../../../subscribers/presentation/cubit/subscriber360_cubit.dart';
import '../../../subscribers/presentation/pages/subscriber_360_screen.dart';
import '../../../subscription_fees/presentation/cubit/subscription_fees_cubit.dart';
import '../../domain/entities/square.dart';
import '../cubit/squares_cubit.dart';

bool _hasSquaresCubit(BuildContext context) {
  try {
    context.read<SquaresCubit>();
    return true;
  } catch (_) {
    return false;
  }
}

/// المربعات السكنية: قائمة + عدد المشتركين + CRUD + Master-Detail (تابلت).
class SquaresScreen extends StatelessWidget {
  const SquaresScreen({super.key});

  @override
  Widget build(BuildContext context) {
    if (!_hasSquaresCubit(context)) {
      return BlocProvider<SquaresCubit>(
        create: (_) => SquaresCubit(FouadModule.deps.squares)..load(),
        child: const _SquaresView(),
      );
    }
    return const _SquaresView();
  }

  static Future<void> _showForm(BuildContext context, {Square? square}) async {
    final nameCtrl = TextEditingController(text: square?.name ?? '');
    final descCtrl = TextEditingController(text: square?.description ?? '');
    final cubit = context.read<SquaresCubit>();
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.apartment_outlined, size: 32, color: AppColors.primary),
        title: Text(square == null ? 'إضافة مربع سكني' : 'تعديل المربع', style: const TextStyle(fontWeight: FontWeight.w800)),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          AppTextField(controller: nameCtrl, label: 'اسم المربع *', maxLength: 100),
          const SizedBox(height: 12),
          AppTextField(controller: descCtrl, label: 'الوصف', maxLines: 3),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () {
              if (nameCtrl.text.trim().isEmpty) return;
              cubit.save(
                id: square?.id,
                name: nameCtrl.text.trim(),
                description: descCtrl.text.trim().isEmpty ? null : descCtrl.text.trim(),
              );
              Navigator.pop(ctx);
            },
            child: const Text('حفظ'),
          ),
        ],
      ),
    );
  }
}

class _SquaresView extends StatelessWidget {
  const _SquaresView();

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<SquaresCubit, SquaresState>(
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(
            title: const Text('المربعات السكنية'),
            actions: [
              if (state.selectedIds.isNotEmpty)
                IconButton(
                  icon: const Icon(Icons.delete_sweep_rounded),
                  tooltip: 'حذف جماعي',
                  onPressed: () async {
                    if (await confirmAction(context, 'حذف جماعي', 'حذف ${state.selectedIds.length} مربعاً؟')) {
                      if (context.mounted) {
                        context.read<SquaresCubit>().bulkDelete();
                      }
                    }
                  },
                ),
            ],
          ),
          floatingActionButton: FloatingActionButton.extended(
            onPressed: () => SquaresScreen._showForm(context),
            icon: const Icon(Icons.add_rounded),
            label: const Text('إضافة مربع'),
          ),
          body: MasterDetailLayout(
            hasSelection: state.selected != null,
            master: _MasterList(state: state),
            detail: state.selected == null
                ? const SizedBox.shrink()
                : _SquareDetail(state: state),
          ),
        );
      },
    );
  }
}

class _MasterList extends StatefulWidget {
  const _MasterList({required this.state, super.key});
  final SquaresState state;

  @override
  State<_MasterList> createState() => _MasterListState();
}

class _MasterListState extends State<_MasterList> {
  late final TextEditingController _search = TextEditingController();

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = widget.state;
    return Column(children: [
      Padding(
        padding: const EdgeInsets.all(12),
        child: SearchTextField(
          query: _search.text,
          hintText: 'بحث باسم المربع...',
          onChanged: (v) {
            _search.text = v;
            context.read<SquaresCubit>().load(search: v);
          },
        ),
      ),
      Expanded(
        child: state.loading
            ? ListView(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                children: List.generate(5, (_) => ShimmerSkeleton(height: 96)),
              )
            : state.squares.isEmpty
                ? const EmptyState(message: 'لا توجد مربعات سكنية')
                : ListView.builder(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    itemCount: state.squares.length,
                    itemBuilder: (_, i) {
                      final sq = state.squares[i];
                      final selected = state.selected?.id == sq.id;
                      return AdminCard(
                        color: selected ? AppColors.primary.withValues(alpha: 0.06) : null,
                        borderColor: selected ? AppColors.primary.withValues(alpha: .5) : null,
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                        child: Row(
                          children: [
                            CircleAvatar(child: Text('#${sq.id}')),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(sq.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                                  const SizedBox(height: 3),
                                  Text(sq.description ?? '', style: const TextStyle(fontSize: 12, color: AppColors.neutral), maxLines: 1, overflow: TextOverflow.ellipsis),
                                ],
                              ),
                            ),
                            StatusBadge(label: '${sq.subscribersCount} مشترك', color: AppColors.info),
                            const SizedBox(width: 4),
                            IconButton(icon: const Icon(Icons.edit_outlined, size: 20), onPressed: () => SquaresScreen._showForm(context, square: sq)),
                            IconButton(
                              icon: const Icon(Icons.delete_outline, size: 20, color: AppColors.danger),
                              onPressed: () async {
                                if (await confirmAction(context, 'حذف المربع', 'حذف «${sq.name}»؟ لا يمكن الحذف إن كان له مشتركون.')) {
                                  if (context.mounted) {
                                    context.read<SquaresCubit>().delete(sq.id);
                                  }
                                }
                              },
                            ),
                          ],
                        ),
                      );
                    },
                  ),
      ),
    ]);
  }
}

class _SquareDetail extends StatelessWidget {
  const _SquareDetail({required this.state});
  final SquaresState state;

  void _openSubscriber(BuildContext context, Subscriber subscriber) {
    final router = GoRouter.maybeOf(context);
    if (router != null) {
      try {
        context.push('/subscribers/${subscriber.id}', extra: subscriber);
        return;
      } catch (_) {}
    }
    final deps = FouadModule.deps;
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => MultiBlocProvider(
          providers: [
            BlocProvider<Subscriber360Cubit>(
              create: (_) => Subscriber360Cubit(deps.subscribers)..load(subscriber.id),
            ),
            BlocProvider<SubscriptionFeesCubit>(
              create: (_) => SubscriptionFeesCubit(deps.fees)
                ..subscriberId = subscriber.id
                ..load(),
            ),
            BlocProvider<ServiceRequestsCubit>(
              create: (_) => ServiceRequestsCubit(deps.requests)
                ..subscriberId = subscriber.id
                ..load(),
            ),
          ],
          child: Subscriber360Screen(subscriber: subscriber),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final sq = state.selected!;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [AppColors.primaryDark, AppColors.primary],
            begin: Alignment.topRight,
            end: Alignment.bottomLeft,
          ),
          borderRadius: BorderRadius.circular(22),
        ),
        child: Row(
          children: [
            Expanded(
              child: Text('مشتركو «${sq.name}»', style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w800)),
            ),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
              decoration: BoxDecoration(color: Colors.white.withValues(alpha: .18), borderRadius: BorderRadius.circular(999)),
              child: Text('${state.selectedSubscribers.length}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
            ),
          ],
        ),
      ),
      const SizedBox(height: 12),
      Expanded(
        child: state.selectedSubscribers.isEmpty
            ? const EmptyState(message: 'لا يوجد مشتركون في هذا المربع بعد', icon: Icons.groups_outlined)
            : ListView.builder(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                itemCount: state.selectedSubscribers.length,
                itemBuilder: (_, i) {
                  final s = state.selectedSubscribers[i];
                  return AdminCard(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    child: ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.person_outline_rounded, color: AppColors.primary),
                      title: Text(s.name),
                      subtitle: Text(s.phone ?? ''),
                      trailing: BalanceText(s.generalBalance),
                      onTap: () => _openSubscriber(context, s),
                    ),
                  );
                },
              ),
      ),
    ]);
  }
}
