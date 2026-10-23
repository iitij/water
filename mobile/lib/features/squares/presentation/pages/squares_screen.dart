import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/widgets/common_widgets.dart';
import '../../domain/entities/square.dart';
import '../cubit/squares_cubit.dart';

/// SCR-04 — المربعات السكنية: قائمة + عدد المشتركين + CRUD + Master-Detail (تابلت).
class SquaresScreen extends StatelessWidget {
  const SquaresScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<SquaresCubit, SquaresState>(
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(
            title: const Text('SCR-04 • المربعات السكنية'),
            actions: [
              if (state.selectedIds.isNotEmpty)
                IconButton(
                  icon: const Icon(Icons.delete_sweep),
                  tooltip: 'حذف جماعي',
                  onPressed: () async {
                    if (await confirmAction(context, 'حذف جماعي', 'حذف ${state.selectedIds.length} مربعاً؟')) {
                      context.read<SquaresCubit>().bulkDelete();
                    }
                  },
                ),
            ],
          ),
          floatingActionButton: FloatingActionButton.extended(
            onPressed: () => _showForm(context),
            icon: const Icon(Icons.add),
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

  static Future<void> _showForm(BuildContext context, {Square? square}) async {
    final nameCtrl = TextEditingController(text: square?.name ?? '');
    final descCtrl = TextEditingController(text: square?.description ?? '');
    final cubit = context.read<SquaresCubit>();
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(square == null ? 'إضافة مربع سكني' : 'تعديل المربع'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: nameCtrl, decoration: const InputDecoration(labelText: 'اسم المربع * (100 حرف كحد أقصى)', border: OutlineInputBorder()), maxLength: 100),
          const SizedBox(height: 8),
          TextField(controller: descCtrl, decoration: const InputDecoration(labelText: 'الوصف (3 أسطر)', border: OutlineInputBorder()), maxLines: 3),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () {
              if (nameCtrl.text.trim().isEmpty) return;
              cubit.save(id: square?.id, name: nameCtrl.text.trim(), description: descCtrl.text.trim().isEmpty ? null : descCtrl.text.trim());
              Navigator.pop(ctx);
            },
            child: const Text('حفظ'),
          ),
        ],
      ),
    );
  }
}

class _MasterList extends StatelessWidget {
  const _MasterList({required this.state});
  final SquaresState state;

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      Padding(
        padding: const EdgeInsets.all(12),
        child: TextField(
          decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'بحث باسم المربع...', border: OutlineInputBorder()),
          onChanged: (v) => context.read<SquaresCubit>().load(search: v),
        ),
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
                      return Card(
                        color: selected ? AppColors.primary.withValues(alpha: 0.08) : null,
                        child: ListTile(
                          leading: CircleAvatar(child: Text('#${sq.id}')),
                          title: Text(sq.name),
                          subtitle: Text(sq.description ?? ''),
                          trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                            StatusBadge(label: '${sq.subscribersCount} مشترك', color: AppColors.info),
                            IconButton(icon: const Icon(Icons.edit_outlined), onPressed: () => SquaresScreen._showForm(context, square: sq)),
                            IconButton(
                              icon: const Icon(Icons.delete_outline, color: AppColors.danger),
                              onPressed: () async {
                                if (await confirmAction(context, 'حذف المربع', 'حذف «${sq.name}»؟ لا يمكن الحذف إن كان له مشتركون.')) {
                                  context.read<SquaresCubit>().delete(sq.id);
                                }
                              },
                            ),
                          ]),
                          onTap: () => context.read<SquaresCubit>().select(sq),
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

  @override
  Widget build(BuildContext context) {
    final sq = state.selected!;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Container(
        color: AppColors.primary,
        padding: const EdgeInsets.all(16),
        child: Text('مشتركو: ${sq.name} (${state.selectedSubscribers.length})',
            style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold)),
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
                    trailing: BalanceText(s.generalBalance),
                  );
                },
              ),
      ),
    ]);
  }
}
