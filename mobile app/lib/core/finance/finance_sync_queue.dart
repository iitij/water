/// طابور المزامنة المحلي للوحدة المالية (SharedPreferences) — يُستخدم عندما لا يكون
/// Dev 1 قد سجّل `SyncQueueManager` (drift). الواجهة مطابقة لـ `SyncQueueRepository`.
import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';

class SyncAction {
  const SyncAction({
    required this.type,
    required this.payload,
    required this.createdAt,
    this.idempotencyKey,
    this.sourceModule = 'payments',
  });

  /// `payment.create` | `invoice.generate_meter` | `invoice.generate_subscription` | ...
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

/// تنفيذ احتياطي بسيط (SharedPreferences) يبقيه Dev 3 قابلاً للتشغيل إلى أن يُدمج
/// تنفيذ Drift الخاص بـ Dev 2. السلوك: قائمة FIFO واحدة لكل عملية.
class LocalSyncQueueRepository implements FinanceSyncQueueRepository {
  LocalSyncQueueRepository({SharedPreferences? prefs}) : _prefs = prefs;

  static const String _key = 'sync.queue.pending';
  SharedPreferences? _prefs;

  /// تحميل كسول —Dev 1 يمرّر `SharedPreferences` عند الحاجة فقط.
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
