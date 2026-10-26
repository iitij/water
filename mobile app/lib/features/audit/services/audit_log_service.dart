import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

import '../data/audit_event_store.dart';
import '../domain/local_audit_event.dart';

/// Uploads a batch under the authenticated scope; never sends an actor ID.
typedef AuditUploader =
    Future<void> Function(List<Map<String, dynamic>> events);

/// Local audit writes are serialized. Network requests release the write lock,
/// then remove only acknowledged IDs from a fresh snapshot of the same store.
class AuditLogService extends ChangeNotifier {
  AuditLogService({
    required AuditEventStore store,
    required AuditUploader upload,
    this.batchSize = 50,
    String? Function()? deviceId,
    DateTime Function()? now,
    String Function()? idGenerator,
    String? Function()? scopeProvider,
    AuditEventStore Function(String scope)? scopedStoreFactory,
  }) : _store = store,
       _upload = upload,
       _deviceId = deviceId,
       _now = now ?? DateTime.now,
       _newId = idGenerator ?? const Uuid().v4,
       _scopeProvider = scopeProvider,
       _scopedStoreFactory = scopedStoreFactory,
       assert(batchSize > 0),
       assert((scopeProvider == null) == (scopedStoreFactory == null));

  final AuditEventStore _store;
  final AuditUploader _upload;
  final String? Function()? _deviceId;
  final DateTime Function() _now;
  final String Function() _newId;
  final String? Function()? _scopeProvider;
  final AuditEventStore Function(String scope)? _scopedStoreFactory;
  final Map<String, AuditEventStore> _scopedStores = {};
  final int batchSize;

  Future<void> _storeTail = Future<void>.value();
  bool _flushing = false;
  int _generation = 0;
  int _pending = 0;
  String? _lastError;
  DateTime? _lastFlushedAt;

  int get pendingCount => _pending;
  String? get lastError => _lastError;
  DateTime? get lastFlushedAt => _lastFlushedAt;
  bool get isFlushing => _flushing;

  /// Called on session changes so counters from another account disappear and
  /// a running flush cannot dispatch its next batch under the new session.
  void sessionChanged() {
    _generation++;
    _pending = 0;
    _lastError = null;
    _lastFlushedAt = null;
    notifyListeners();
  }

  _AuditContext? _context() {
    if (_scopeProvider == null) {
      return _AuditContext(null, _store, _generation);
    }
    final scope = _scopeProvider.call();
    if (scope == null || scope.isEmpty) return null;
    final store = _scopedStores.putIfAbsent(
      scope,
      () => _scopedStoreFactory!(scope),
    );
    return _AuditContext(scope, store, _generation);
  }

  bool _isCurrent(_AuditContext context) =>
      context.generation == _generation &&
      (_scopeProvider == null || _scopeProvider.call() == context.scope);

  Future<T> _locked<T>(Future<T> Function() operation) async {
    final previous = _storeTail;
    final completed = Completer<void>();
    _storeTail = completed.future;
    await previous;
    try {
      return await operation();
    } finally {
      completed.complete();
    }
  }

  /// Recording is best-effort and never interrupts the financial operation.
  Future<LocalAuditEvent?> record(
    String event, {
    String? entityType,
    Object? entityId,
    Map<String, dynamic>? before,
    Map<String, dynamic>? after,
  }) async {
    if (event.trim().isEmpty) return null;
    try {
      final context = _context();
      if (context == null) return null;
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
      final count = await _locked(() async {
        final pending = List<LocalAuditEvent>.from(
          await context.store.pending(),
        );
        pending.add(localEvent);
        await context.store.save(pending);
        return pending.length;
      });
      if (_isCurrent(context)) {
        _pending = count;
        notifyListeners();
      }
      return localEvent;
    } catch (error) {
      _lastError = 'تعذّر حفظ حدث التدقيق محلياً: $error';
      notifyListeners();
      return null;
    }
  }

  Future<int> flush() async {
    if (_flushing) return 0;
    final context = _context();
    if (context == null) {
      _pending = 0;
      return 0;
    }
    _flushing = true;
    notifyListeners();
    var uploaded = 0;
    try {
      while (_isCurrent(context)) {
        final pending = await _locked(context.store.pending);
        if (!_isCurrent(context)) break;
        _pending = pending.length;
        if (pending.isEmpty) break;
        final batch = pending.take(batchSize).toList(growable: false);
        try {
          await _upload(
            batch.map((event) => event.toWireJson()).toList(growable: false),
          );
        } catch (error) {
          if (_isCurrent(context)) _lastError = 'تعذّر رفع سجل التدقيق: $error';
          break;
        }
        final sentIds = batch.map((event) => event.id).toSet();
        final remaining = await _locked(() async {
          final latest = await context.store.pending();
          final unsent = latest
              .where((event) => !sentIds.contains(event.id))
              .toList();
          await context.store.save(unsent);
          return unsent.length;
        });
        uploaded += batch.length;
        if (_isCurrent(context)) {
          _pending = remaining;
          _lastError = null;
          _lastFlushedAt = _now();
        }
      }
      return uploaded;
    } catch (error) {
      if (_isCurrent(context)) {
        _lastError = 'تعذّر قراءة سجل التدقيق المحلي: $error';
      }
      return uploaded;
    } finally {
      _flushing = false;
      notifyListeners();
    }
  }

  Future<int> refreshPendingCount() async {
    try {
      final context = _context();
      if (context == null) {
        _pending = 0;
      } else {
        final pending = await _locked(context.store.pending);
        if (_isCurrent(context)) _pending = pending.length;
      }
      notifyListeners();
    } catch (error) {
      _lastError = 'تعذّر قراءة سجل التدقيق المحلي: $error';
    }
    return _pending;
  }
}

class _AuditContext {
  const _AuditContext(this.scope, this.store, this.generation);
  final String? scope;
  final AuditEventStore store;
  final int generation;
}
