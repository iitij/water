import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../meters/data/models/meter.dart';
import '../../../meters/domain/meters_repository.dart';
import '../../../subscribers/domain/entities/subscriber.dart';
import '../../domain/entities/ledger_transaction.dart';
import '../../domain/repositories/transactions_repository.dart';

class LedgerMeterFilterOption extends Equatable {
  const LedgerMeterFilterOption({
    required this.id,
    required this.label,
    this.subscriberId,
  });

  final int id;
  final String label;
  final int? subscriberId;

  @override
  List<Object?> get props => [id, label, subscriberId];
}

class TransactionsState extends Equatable {
  const TransactionsState({
    this.loading = false,
    this.transactions = const [],
    this.subscribers = const [],
    this.meters = const [],
    this.search = '',
    this.type,
    this.subscriberId,
    this.meterId,
    this.fromDate,
    this.toDate,
    this.error,
  });

  final bool loading;
  final List<LedgerTransaction> transactions;
  final List<Subscriber> subscribers;
  final List<LedgerMeterFilterOption> meters;
  final String search;
  final String? type; // payment|invoice|adjustment
  final int? subscriberId;
  final String? meterId; // 'general' أو id
  final DateTime? fromDate;
  final DateTime? toDate;
  final String? error;

  double get totalCredit => transactions.where((t) => t.isCredit).fold(0, (a, t) => a + t.amount);
  double get totalDebit => transactions.where((t) => !t.isCredit).fold(0, (a, t) => a + t.amount.abs());

  List<LedgerMeterFilterOption> get visibleMeters => subscriberId == null
      ? meters
      : meters.where((m) => m.subscriberId == null || m.subscriberId == subscriberId).toList();

  TransactionsState copyWith({
    bool? loading,
    List<LedgerTransaction>? transactions,
    List<Subscriber>? subscribers,
    List<LedgerMeterFilterOption>? meters,
    String? search,
    String? type,
    bool clearType = false,
    int? subscriberId,
    bool clearSubscriber = false,
    String? meterId,
    bool clearMeter = false,
    DateTime? fromDate,
    DateTime? toDate,
    bool clearDates = false,
    String? error,
  }) =>
      TransactionsState(
        loading: loading ?? this.loading,
        transactions: transactions ?? this.transactions,
        subscribers: subscribers ?? this.subscribers,
        meters: meters ?? this.meters,
        search: search ?? this.search,
        type: clearType ? null : (type ?? this.type),
        subscriberId: clearSubscriber ? null : (subscriberId ?? this.subscriberId),
        meterId: clearMeter ? null : (meterId ?? this.meterId),
        fromDate: clearDates ? null : (fromDate ?? this.fromDate),
        toDate: clearDates ? null : (toDate ?? this.toDate),
        error: error,
      );

  @override
  List<Object?> get props => [
        loading,
        transactions,
        subscribers,
        meters,
        search,
        type,
        subscriberId,
        meterId,
        fromDate,
        toDate,
        error,
      ];
}

/// دفتر الأستاذ العام (SCR-22) — قراءة فقط، مع فلتر مشترك وفلتر عداد فعلي.
class TransactionsCubit extends Cubit<TransactionsState> {
  TransactionsCubit(this._repo) : super(const TransactionsState());
  final TransactionsRepository _repo;
  bool _lookupsLoaded = false;

  Future<void> load() async {
    emit(state.copyWith(loading: true));
    if (!_lookupsLoaded) {
      await _loadLookups();
      _lookupsLoaded = true;
    }
    try {
      final list = await _repo.getTransactions(
        search: state.search,
        subscriberId: state.subscriberId,
        meterId: state.meterId,
        type: state.type,
        fromDate: _formatDate(state.fromDate),
        toDate: _formatDate(state.toDate),
      );
      final mergedMeters = _mergeMetersFromTransactions(state.meters, list);
      emit(state.copyWith(loading: false, transactions: list, meters: mergedMeters));
    } catch (e) {
      emit(state.copyWith(loading: false, error: friendlyError(e)));
    }
  }

  /// تنسيق التاريخ للخادم بصيغة `YYYY-MM-DD` (سلوك فلاتر Filament).
  String? _formatDate(DateTime? date) {
    if (date == null) return null;
    final month = date.month.toString().padLeft(2, '0');
    final day = date.day.toString().padLeft(2, '0');
    return '${date.year}-$month-$day';
  }

  Future<void> _loadLookups() async {
    var subscribers = state.subscribers;
    var meters = state.meters;
    try {
      subscribers = await FouadModule.deps.subscribers.getSubscribers(perPage: 500);
    } catch (_) {}

    if (GetIt.I.isRegistered<MetersRepository>()) {
      try {
        final rawMeters = await GetIt.I<MetersRepository>().getMeters();
        meters = rawMeters.map(_fromMeterModel).toList();
      } catch (_) {}
    }
    emit(state.copyWith(subscribers: subscribers, meters: meters));
  }

  LedgerMeterFilterOption _fromMeterModel(Meter m) {
    final owner = m.subscriberName ?? '';
    return LedgerMeterFilterOption(
      id: m.id,
      subscriberId: m.subscriberId,
      label: owner.isEmpty ? 'عداد ${m.uniqueCode}' : 'عداد ${m.uniqueCode} — $owner',
    );
  }

  List<LedgerMeterFilterOption> _mergeMetersFromTransactions(
    List<LedgerMeterFilterOption> existing,
    List<LedgerTransaction> list,
  ) {
    final byId = {for (final m in existing) m.id: m};
    for (final t in list) {
      final mId = t.meterId;
      if (mId != null && !byId.containsKey(mId)) {
        byId[mId] = LedgerMeterFilterOption(
          id: mId,
          subscriberId: t.subscriberId,
          label: 'عداد رقم #$mId${t.subscriberName != null ? ' — ${t.subscriberName}' : ''}',
        );
      }
    }
    return byId.values.toList();
  }

  void search(String q) {
    emit(state.copyWith(search: q));
    load();
  }

  void filterType(String? t) {
    emit(t == null ? state.copyWith(clearType: true) : state.copyWith(type: t));
    load();
  }

  Future<void> filterSubscriber(int? id) async {
    var updatedMeters = state.meters;
    if (id != null && !updatedMeters.any((m) => m.subscriberId == id)) {
      try {
        final detail = await FouadModule.deps.subscribers.getSubscriber360(id);
        final subMeters = detail.summary.meterBalances.map(
          (mb) => LedgerMeterFilterOption(
            id: mb.meterId,
            subscriberId: id,
            label: mb.title,
          ),
        );
        final byId = {for (final m in updatedMeters) m.id: m};
        for (final sm in subMeters) {
          byId[sm.id] = sm;
        }
        updatedMeters = byId.values.toList();
      } catch (_) {}
    }

    final currentMeterValid = state.meterId == null ||
        state.meterId == 'general' ||
        id == null ||
        updatedMeters.any((m) => '${m.id}' == state.meterId && m.subscriberId == id);

    emit(
      id == null
          ? state.copyWith(clearSubscriber: true, meters: updatedMeters)
          : state.copyWith(
              subscriberId: id,
              meters: updatedMeters,
              clearMeter: !currentMeterValid,
            ),
    );
    await load();
  }

  void filterMeter(String? m) {
    emit(m == null ? state.copyWith(clearMeter: true) : state.copyWith(meterId: m));
    load();
  }

  /// فلتر الفترة الزمنية (`R-11`: filters subscriber/meter/type/date).
  /// تمرير قيمتين `null` يزيل الفلتر.
  Future<void> filterDateRange(DateTime? from, DateTime? to) async {
    if (from == null && to == null) {
      emit(state.copyWith(clearDates: true));
    } else {
      emit(state.copyWith(fromDate: from, toDate: to));
    }
    await load();
  }
}
