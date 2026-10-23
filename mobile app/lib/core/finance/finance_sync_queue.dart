/// طابور المزامنة المحلي للوحدة المالية — يربط عمليات التحصيل الأوفلاين مباشرة
/// بـ `SyncQueueManager` الموحّد (لتظهر في شاشة المزامنة وتُرسَل تلقائياً فور عودة الاتصال)،
/// مع الاحتفاظ بـ SharedPreferences كاحتياط آمن في بيئات الاختبار المعزولة.
import 'dart:convert';
import 'package:get_it/get_it.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../features/offline_sync/domain/sync_queue_item.dart';
import '../../features/offline_sync/services/sync_queue_manager.dart';

class SyncAction {
  const SyncAction({
    required this.type,
    required this.payload,
    required this.createdAt,
    this.idempotencyKey,
    this.sourceModule = 'payments',
  });

  /// `payment.create` | `invoice.pay` | `invoice.generate_meter` | ...
  final String type;
  final Map<String, dynamic> payload;
  final DateTime createdAt;

  /// مفتاح يمنع تكرار السند عند إعادة المحاولة (رقم السند المحلي).
  final String? idempotencyKey;
  final String sourceModule;

  Map<String, dynamic> toJson() => <String, dynamic>{
        'type': type,
        'payload': payload,
        'created_at': createdAt.toIso8601String(),
        'idempotency_key': idempotencyKey,
        'source_module': sourceModule,
      };

  factory SyncAction.fromJson(Map<String, dynamic> json) => SyncAction(
        type: json['type'] as String,
        payload: (json['payload'] as Map?)?.cast<String, dynamic>() ??
            <String, dynamic>{},
        createdAt: DateTime.tryParse('${json['created_at']}') ?? DateTime.now(),
        idempotencyKey: json['idempotency_key'] as String?,
        sourceModule: (json['source_module'] as String?) ?? 'unknown',
      );
}

abstract interface class FinanceSyncQueueRepository {
  Future<void> enqueue(SyncAction action);
  Future<List<SyncAction>> pending({String? module});
  Future<void> markSynced(SyncAction action);
}

typedef SyncQueueRepository = FinanceSyncQueueRepository;

/// تنفيذ متكامل يمرّر الدفعات الأوفلاين إلى `SyncQueueManager` الموحّد فور تسجيلها،
/// ويعود إلى SharedPreferences فقط عند غياب مدير المزامنة (مثلاً في اختبارات الوحدات).
class LocalSyncQueueRepository implements FinanceSyncQueueRepository {
  LocalSyncQueueRepository({SharedPreferences? prefs}) : _prefs = prefs;

  static const String storageKey = 'sync.queue.pending';
  SharedPreferences? _prefs;

  Future<SharedPreferences> get _store async =>
      _prefs ??= await SharedPreferences.getInstance();

  @override
  Future<void> enqueue(SyncAction action) async {
    if (GetIt.I.isRegistered<SyncQueueManager>() &&
        (action.sourceModule == 'payments' ||
            action.type == 'payment.create' ||
            action.type == 'invoice.pay')) {
      try {
        final payload = Map<String, dynamic>.from(action.payload);
        // لا نمرّر `REC-LOCAL-...` كـ sync_uuid لأن الخادم يشترط UUID v4 صالحاً؛
        // يولّد `SyncQueueManager.enqueuePayment` قيمة `sync_uuid` قياسية تلقائياً.
        if (action.idempotencyKey != null) {
          payload['local_receipt_number'] = action.idempotencyKey;
        }
        await GetIt.I<SyncQueueManager>().enqueuePayment(payload);
        return;
      } catch (_) {
        // في حال عدم اكتمال الجلسة في الاختبارات، نحفظ في المخزن المحلي الاحتياطي.
      }
    }
    final list = await _read();
    list.add(action);
    await _write(list);
  }

  @override
  Future<List<SyncAction>> pending({String? module}) async {
    final localList = await _read();
    final filteredLocal = module == null
        ? localList
        : localList.where((a) => a.sourceModule == module).toList();

    if (GetIt.I.isRegistered<SyncQueueManager>() &&
        (module == null || module == 'payments')) {
      final managerItems = GetIt.I<SyncQueueManager>()
          .items
          .where((item) => item.type == SyncQueueType.payment)
          .map(
            (item) => SyncAction(
              type: item.payload['invoice_id'] != null
                  ? 'invoice.pay'
                  : 'payment.create',
              payload: item.payload,
              createdAt: item.createdAt,
              idempotencyKey:
                  item.payload['local_receipt_number']?.toString() ?? item.id,
              sourceModule: 'payments',
            ),
          );
      return <SyncAction>[...filteredLocal, ...managerItems];
    }
    return filteredLocal;
  }

  @override
  Future<void> markSynced(SyncAction action) async {
    final list = await _read();
    list.removeWhere(
      (a) =>
          a.idempotencyKey != null &&
          a.idempotencyKey == action.idempotencyKey,
    );
    await _write(list);
  }

  Future<List<SyncAction>> _read() async {
    final prefs = await _store;
    final raw = prefs.getStringList(storageKey) ?? <String>[];
    final list = <SyncAction>[];
    for (final item in raw) {
      try {
        final decoded = jsonDecode(item);
        if (decoded is Map) {
          list.add(SyncAction.fromJson(decoded.cast<String, dynamic>()));
        }
      } catch (_) {}
    }
    return list;
  }

  Future<void> _write(List<SyncAction> list) async {
    final prefs = await _store;
    await prefs.setStringList(
      storageKey,
      list.map((a) => jsonEncode(a.toJson())).toList(),
    );
  }
}
