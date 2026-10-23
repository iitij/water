/// طابور المزامنة للوحدة المالية: يوجّه الدفعات الأوفلاين مباشرةً إلى
/// `SyncQueueManager` الموحّد المدعوم بـ Drift/SQLite (`AppDatabase`)، مع الاحتفاظ
/// بـ `LocalSyncQueueRepository` كخيار احتياطي للاختبارات المنعزلة فقط.
import 'dart:convert';

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

  /// `invoice.pay` | `payment.create`
  final String type;
  final Map<String, dynamic> payload;
  final DateTime createdAt;

  /// مفتاح يمنع تكرار السند عند إعادة المحاولة.
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

/// المحوّل الرسمي للإنتاج: يربط الوحدة المالية بطابور Drift/SQLite الموحد
/// (`SyncQueueManager`) لتظهر كل سندات القبض الأوفلاين فوراً في `SCR-28` والشاشة الرئيسية.
class UnifiedFinanceSyncQueueAdapter implements FinanceSyncQueueRepository {
  const UnifiedFinanceSyncQueueAdapter(this._manager);

  final SyncQueueManager _manager;

  @override
  Future<void> enqueue(SyncAction action) async {
    await _manager.enqueuePayment(
      action.payload,
      idempotencyKey: action.idempotencyKey,
    );
  }

  @override
  Future<List<SyncAction>> pending({String? module}) async {
    return _manager.items
        .where((item) => item.type == SyncQueueType.payment)
        .map(
          (item) => SyncAction(
            type: item.payload['invoice_id'] != null
                ? 'invoice.pay'
                : 'payment.create',
            payload: item.payload,
            createdAt: item.createdAt,
            idempotencyKey: item.id,
            sourceModule: 'payments',
          ),
        )
        .toList(growable: false);
  }

  @override
  Future<void> markSynced(SyncAction action) async {
    // يُدار الانتقال إلى `synced` تلقائياً بواسطة `SyncQueueManager`.
  }
}

/// تنفيذ احتياطي بسيط (SharedPreferences) للاختبارات المنعزلة التي لا تهيئ `SyncQueueManager`.
class LocalSyncQueueRepository implements FinanceSyncQueueRepository {
  LocalSyncQueueRepository({SharedPreferences? prefs}) : _prefs = prefs;

  static const String _key = 'sync.queue.pending';
  SharedPreferences? _prefs;

  Future<SharedPreferences> get _store async =>
      _prefs ??= await SharedPreferences.getInstance();

  @override
  Future<void> enqueue(SyncAction action) async {
    final list = await _read();
    list.add(action);
    await _write(list);
  }

  @override
  Future<List<SyncAction>> pending({String? module}) async {
    final list = await _read();
    if (module == null) return list;
    return list.where((a) => a.sourceModule == module).toList();
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
    final raw = prefs.getStringList(_key) ?? <String>[];
    final list = <SyncAction>[];
    for (final item in raw) {
      try {
        final decoded = jsonDecode(item);
        if (decoded is Map) {
          list.add(SyncAction.fromJson(decoded.cast<String, dynamic>()));
        }
      } catch (_) {
        // عنصر تالف → يُتجاهل حتى لا ينكسر الطابور كله.
      }
    }
    return list;
  }

  Future<void> _write(List<SyncAction> list) async {
    final prefs = await _store;
    await prefs.setStringList(
      _key,
      list.map((a) => jsonEncode(a.toJson())).toList(),
    );
  }
}
