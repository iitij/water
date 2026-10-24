import 'package:flutter/foundation.dart';

import '../data/audit_event_store.dart';
import '../domain/local_audit_event.dart';

/// يرفع دفعة أحداث إلى الخادم. يرمي استثناءً عند الفشل ليُبقي [AuditLogService]
/// الأحداث في المخزن ويعيد المحاولة لاحقاً.
typedef AuditUploader = Future<void> Function(List<Map<String, dynamic>> events);

/// سجل تدقيق محلي + رفع مجمّع (بطاقة S4-05).
///
/// المبدأ: **لا يُفقد حدث**. التسجيل لا يرمي استثناءً أبداً — الأسوأ أن يبقى
/// الحدث في الجهاز بانتظار الشبكة، لا أن تفشل عملية التحصيل لأن السجل تعطّل.
///
/// ترتيب العمل:
/// 1. `record(...)` يضيف الحدث للمخزن المحلي فوراً (قبل أي اتصال).
/// 2. `flush()` يرسل دفعات بحجم [batchSize] ويحذف ما نجح فقط.
/// 3. عند فشل أي دفعة يتوقف الرفع ويُبقي ما لم يُرفع (بلا تكرار: المعرّف المحلي
///    ثابت على الحدث).
class AuditLogService extends ChangeNotifier {
  AuditLogService({
    required AuditEventStore store,
    required AuditUploader upload,
    this.batchSize = 50,
    String? Function()? deviceId,
    DateTime Function()? now,
    String Function()? idGenerator,
  })  : _store = store,
        _upload = upload,
        _deviceId = deviceId,
        _now = now ?? DateTime.now,
        _newId = idGenerator ?? _generateId,
        assert(batchSize > 0, 'حجم الدفعة يجب أن يكون أكبر من صفر');

  final AuditEventStore _store;
  final AuditUploader _upload;
  final String? Function()? _deviceId;
  final DateTime Function() _now;
  final String Function() _newId;
  final int batchSize;

  bool _flushing = false;
  int _pending = 0;
  String? _lastError;
  DateTime? _lastFlushedAt;

  /// عدد الأحداث المعلّقة كما ظهر في آخر تحميل ناجح.
  int get pendingCount => _pending;

  /// آخر رسالة فشل (لشاشة التشخيص)، وتُمحى بعد أول رفع ناجح.
  String? get lastError => _lastError;

  DateTime? get lastFlushedAt => _lastFlushedAt;

  bool get isFlushing => _flushing;

  /// يسجّل حدثاً محلياً. لا يرمي استثناءً مهما حدث.
  Future<LocalAuditEvent?> record(
    String event, {
    String? entityType,
    Object? entityId,
    Map<String, dynamic>? before,
    Map<String, dynamic>? after,
  }) async {
    if (event.trim().isEmpty) return null;

    final localEvent = LocalAuditEvent(
      id: _newId(),
      event: event.trim(),
      occurredAt: _now(),
      entityType: entityType,
      entityId: entityId?.toString(),
      before: before,
      after: after,
      deviceId: _deviceId?.call(),
    );

    try {
      final pending = await _store.pending();
      pending.add(localEvent);
      await _store.save(pending);
      _pending = pending.length;
      notifyListeners();
      return localEvent;
    } catch (error) {
      _lastError = 'تعذّر حفظ حدث التدقيق محلياً: $error';
      notifyListeners();
      return null;
    }
  }

  /// يرفع كل المعلّق على دفعات، ويعيد عدد ما رُفع فعلاً.
  ///
  /// آمن للاستدعاء المتكرر (عند عودة الشبكة، عند فتح الشاشة، بعد كل عملية).
  Future<int> flush() async {
    if (_flushing) return 0;
    _flushing = true;
    notifyListeners();

    var uploaded = 0;
    try {
      var pending = await _store.pending();
      _pending = pending.length;

      while (pending.isNotEmpty) {
        final batch = pending.take(batchSize).toList(growable: false);
        try {
          await _upload(
            batch.map((event) => event.toWireJson()).toList(growable: false),
          );
        } catch (error) {
          _lastError = 'تعذّر رفع سجل التدقيق: $error';
          break;
        }

        final sentIds = batch.map((event) => event.id).toSet();
        pending = pending
            .where((event) => !sentIds.contains(event.id))
            .toList(growable: true);
        uploaded += batch.length;
        _lastError = null;
        _lastFlushedAt = _now();
        await _store.save(pending);
        _pending = pending.length;
      }

      _pending = pending.length;
      return uploaded;
    } catch (error) {
      _lastError = 'تعذّر قراءة سجل التدقيق المحلي: $error';
      return uploaded;
    } finally {
      _flushing = false;
      notifyListeners();
    }
  }

  /// عدد الأحداث المعلّقة قراءةً حديثة من المخزن (بلا رفع).
  Future<int> refreshPendingCount() async {
    try {
      final pending = await _store.pending();
      _pending = pending.length;
      notifyListeners();
    } catch (error) {
      _lastError = 'تعذّر قراءة سجل التدقيق المحلي: $error';
    }
    return _pending;
  }

  /// معرّف بسيط فريد بما يكفي للجهاز الواحد (زمن + عشوائي).
  static String _generateId() {
    final micros = DateTime.now().microsecondsSinceEpoch;
    final salt = DateTime.now().hashCode & 0xffff;
    return '$micros-$salt';
  }
}
