import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// عقد طابور المزامنة أوفلاين — ينفذه المهندس أنور (Dev 2) بجدول Drift
/// `pending_sync_actions`، ويسلّمه للمهندس محمد لعرضه في `SCR-28`.
///
/// Dev 3 يستهلك العقد فقط: عند انقطاع الشبكة أثناء التحصيل الميداني يُسجَّل سند
/// القبض في الطابور ليُرسل لاحقاً بالترتيب الزمني، فلا يفقد المحصل تحصيله.
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

abstract interface class SyncQueueRepository {
  Future<void> enqueue(SyncAction action);
  Future<List<SyncAction>> pending({String? module});
  Future<void> markSynced(SyncAction action);
}

/// تنفيذ احتياطي بسيط (SharedPreferences) يبقيه Dev 3 قابلاً للتشغيل إلى أن يُدمج
/// تنفيذ Drift الخاص بـ Dev 2. السلوك: قائمة FIFO واحدة لكل عملية.
class LocalSyncQueueRepository implements SyncQueueRepository {
  const LocalSyncQueueRepository(this._prefs);

  static const String _key = 'sync.queue.pending';
  final SharedPreferences _prefs;

  @override
  Future<void> enqueue(SyncAction action) async {
    final list = _read();
    list.add(action);
    await _write(list);
  }

  @override
  Future<List<SyncAction>> pending({String? module}) async {
    final list = _read();
    if (module == null) return list;
    return list.where((a) => a.sourceModule == module).toList();
  }

  @override
  Future<void> markSynced(SyncAction action) async {
    final list = _read();
    list.removeWhere(
      (a) =>
          a.idempotencyKey != null &&
          a.idempotencyKey == action.idempotencyKey,
    );
    await _write(list);
  }

  List<SyncAction> _read() {
    final raw = _prefs.getStringList(_key) ?? <String>[];
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

  Future<void> _write(List<SyncAction> list) => _prefs.setStringList(
        _key,
        list.map((a) => jsonEncode(a.toJson())).toList(),
      );
}
