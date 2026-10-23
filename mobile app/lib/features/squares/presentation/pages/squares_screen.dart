import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../service_requests/presentation/cubit/service_requests_cubit.dart';
import '../../../subscribers/domain/entities/subscriber.dart';
import '../../../subscribers/domain/repositories/subscribers_repository.dart';
import '../../../subscribers/presentation/cubit/subscriber360_cubit.dart';
import '../../../subscribers/presentation/pages/subscriber_360_screen.dart';
import '../../../subscribers/presentation/pages/subscriber_form_screen.dart';
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

/// SCR-04 — المربعات السكنية: قائمة + عدد المشتركين + CRUD + حذف جماعي + إدارة مشتركي المربع.
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
        title: Text(square == null ? 'إضافة مربع سكني' : 'تعديل المربع'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(
            controller: nameCtrl,
            decoration: const InputDecoration(
              labelText: 'اسم المربع * (100 حرف كحد أقصى)',
              border: OutlineInputBorder(),
            ),
            maxLength: 100,
          ),
          const SizedBox(height: 8),
          TextField(
            controller: descCtrl,
            decoration: const InputDecoration(
              labelText: 'الوصف (3 أسطر)',
              border: OutlineInputBorder(),
            ),
            maxLines: 3,
          ),
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
    return BlocConsumer<SquaresCubit, SquaresState>(
      listenWhen: (prev, curr) => curr.error != null && prev.error != curr.error,
      listener: (context, state) {
        if (state.error != null) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(state.error!), backgroundColor: AppColors.danger),
          );
        }
      },
      builder: (context, state) {
        final isCompact = MediaQuery.sizeOf(context).width < 840;
        return Scaffold(
          appBar: AppBar(
            title: const Text('المربعات السكنية'),
            actions: [
              if (state.selectedIds.isNotEmpty)
                IconButton(
                  icon: const Icon(Icons.delete_sweep),
                  tooltip: 'حذف المحدد (${state.selectedIds.length})',
                  onPressed: () async {
                    if (await confirmAction(
                      context,
                      'حذف جماعي للمربعات',
                      'حذف ${state.selectedIds.length} مربعاً؟ سيتم تخطي المربعات التي تحتوي على مشتركين.',
                    )) {
                      if (context.mounted) {
                        await context.read<SquaresCubit>().bulkDelete();
                      }
                    }
                  },
                ),
              IconButton(
                tooltip: 'تحديث',
                icon: const Icon(Icons.refresh),
                onPressed: () => context.read<SquaresCubit>().load(),
              ),
            ],
          ),
          floatingActionButton: FloatingActionButton.extended(
            onPressed: () => SquaresScreen._showForm(context),
            icon: const Icon(Icons.add),
            label: const Text('إضافة مربع'),
          ),
          body: MasterDetailLayout(
            hasSelection: state.selected != null,
            master: _MasterList(state: state, isCompact: isCompact),
            detail: state.selected == null
                ? const SizedBox.shrink()
                : _SquareDetail(state: state),
          ),
        );
      },
    );
  }
}

class _MasterList extends StatelessWidget {
  const _MasterList({required this.state, required this.isCompact});
  final SquaresState state;
  final bool isCompact;

  Future<void> _selectSquare(BuildContext context, Square sq) async {
    final cubit = context.read<SquaresCubit>();
    await cubit.select(sq);
    if (!isCompact || !context.mounted) return;
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => BlocProvider.value(
        value: cubit,
        child: FractionallySizedBox(
          heightFactor: 0.85,
          child: BlocBuilder<SquaresCubit, SquaresState>(
            builder: (sheetCtx, sheetState) => sheetState.selected == null
                ? const SizedBox.shrink()
                : _SquareDetail(state: sheetState),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      Padding(
        padding: const EdgeInsets.all(12),
        child: TextField(
          decoration: const InputDecoration(
            prefixIcon: Icon(Icons.search),
            hintText: 'بحث باسم المربع...',
            border: OutlineInputBorder(),
          ),
          onChanged: (v) => context.read<SquaresCubit>().load(search: v),
        ),
      ),
      if (state.selectedIds.isNotEmpty)
        MaterialBanner(
          content: Text('تم تحديد ${state.selectedIds.length} مربعاً'),
          leading: const Icon(Icons.checklist),
          actions: [
            TextButton(
              onPressed: () async {
                if (await confirmAction(
                  context,
                  'حذف جماعي للمربعات',
                  'حذف ${state.selectedIds.length} مربعاً؟ لا يمكن حذف المربعات التي تحتوي على مشتركين.',
                )) {
                  if (context.mounted) {
                    await context.read<SquaresCubit>().bulkDelete();
                  }
                }
              },
              child: const Text('حذف المحدد', style: TextStyle(color: AppColors.danger)),
            ),
          ],
        ),
      Expanded(
        child: state.loading
            ? const Center(child: CircularProgressIndicator())
            : state.squares.isEmpty
                ? const EmptyState(message: 'لا توجد مربعات سكنية')
                : ListView.builder(
                    itemCount: state.squares.length,
                    itemBuilder: (_, i) {
                      final sq = state.squares[i];
                      final selected = state.selected?.id == sq.id;
                      final checked = state.selectedIds.contains(sq.id);
                      return Card(
                        color: (selected || checked) ? AppColors.primary.withValues(alpha: 0.08) : null,
                        child: ListTile(
                          leading: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Checkbox(
                                value: checked,
                                onChanged: (_) => context.read<SquaresCubit>().toggleSelect(sq.id),
                                visualDensity: VisualDensity.compact,
                              ),
                              CircleAvatar(radius: 16, child: Text('#${sq.id}')),
                            ],
                          ),
                          title: Text(sq.name),
                          subtitle: Text(sq.description ?? ''),
                          trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                            StatusBadge(label: '${sq.subscribersCount} مشترك', color: AppColors.info),
                            IconButton(
                              icon: const Icon(Icons.edit_outlined),
                              tooltip: 'تعديل المربع',
                              onPressed: () => SquaresScreen._showForm(context, square: sq),
                            ),
                            IconButton(
                              icon: const Icon(Icons.delete_outline, color: AppColors.danger),
                              tooltip: 'حذف المربع',
                              onPressed: () async {
                                if (await confirmAction(
                                  context,
                                  'حذف المربع',
                                  'حذف «${sq.name}»؟ لا يمكن الحذف إن كان له مشتركون.',
                                )) {
                                  if (context.mounted) {
                                    await context.read<SquaresCubit>().delete(sq.id);
                                  }
                                }
                              },
                            ),
                          ]),
                          onTap: () => _selectSquare(context, sq),
                          onLongPress: () => context.read<SquaresCubit>().toggleSelect(sq.id),
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

  Future<void> _openSubscriber(BuildContext context, Subscriber subscriber) async {
    final cubit = context.read<SquaresCubit>();
    final sq = state.selected;
    final router = GoRouter.maybeOf(context);
    if (router != null) {
      try {
        await context.push('/subscribers/${subscriber.id}', extra: subscriber);
        if (context.mounted && sq != null) {
          await cubit.select(sq);
          await cubit.load();
        }
        return;
      } catch (_) {}
    }
    if (!context.mounted) return;
    final deps = FouadModule.deps;
    await Navigator.of(context).push(
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
    if (context.mounted && sq != null) {
      await cubit.select(sq);
      await cubit.load();
    }
  }

  Future<void> _openSubscriberForm(BuildContext context, Square sq, {Subscriber? existing}) async {
    final cubit = context.read<SquaresCubit>();
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute<bool>(
        builder: (_) => RepositoryProvider<SubscribersRepository>.value(
          value: FouadModule.deps.subscribers,
          child: SubscriberFormScreen(
            existing: existing,
            initialSquareId: sq.id,
          ),
        ),
      ),
    );
    if (saved == true && context.mounted) {
      await cubit.select(sq);
      await cubit.load();
    }
  }

  Future<void> _deleteSubscriber(BuildContext context, Square sq, Subscriber sub) async {
    final confirmed = await confirmAction(
      context,
      'حذف مشترك من المربع',
      'هل تريد حذف المشترك «${sub.name}»؟ لا يمكن حذفه إن ارتبط بعدادات أو فواتير.',
    );
    if (!confirmed || !context.mounted) return;
    final cubit = context.read<SquaresCubit>();
    try {
      await FouadModule.deps.subscribers.deleteSubscriber(sub.id);
      if (context.mounted) {
        await cubit.select(sq);
        await cubit.load();
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('تم حذف المشترك بنجاح')),
          );
        }
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(friendlyError(e)), backgroundColor: AppColors.danger),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final sq = state.selected!;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Container(
        color: AppColors.primary,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          children: [
            Expanded(
              child: Text(
                'مشتركو: ${sq.name} (${state.selectedSubscribers.length})',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 17,
                  fontWeight: FontWeight.bold,
                ),
              ),
            ),
            FilledButton.tonalIcon(
              onPressed: () => _openSubscriberForm(context, sq),
              icon: const Icon(Icons.person_add, size: 18),
              label: const Text('إضافة مشترك'),
            ),
          ],
        ),
      ),
      Expanded(
        child: state.selectedSubscribers.isEmpty
            ? const EmptyState(message: 'لا يوجد مشتركون في هذا المربع بعد')
            : ListView.builder(
                itemCount: state.selectedSubscribers.length,
                itemBuilder: (_, i) {
                  final s = state.selectedSubscribers[i];
                  return ListTile(
                    leading: const Icon(Icons.person_outline),
                    title: Text(s.name),
                    subtitle: Text(s.phone ?? ''),
                    trailing: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        BalanceText(s.netBalance),
                        IconButton(
                          icon: const Icon(Icons.edit_outlined, size: 20),
                          tooltip: 'تعديل المشترك',
                          onPressed: () => _openSubscriberForm(context, sq, existing: s),
                        ),
                        IconButton(
                          icon: const Icon(Icons.delete_outline, size: 20, color: AppColors.danger),
                          tooltip: 'حذف المشترك',
                          onPressed: () => _deleteSubscriber(context, sq, s),
                        ),
                      ],
                    ),
                    onTap: () => _openSubscriber(context, s),
                  );
                },
              ),
      ),
    ]);
  }
}
