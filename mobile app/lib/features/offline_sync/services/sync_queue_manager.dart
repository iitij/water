import 'dart:async';
import 'dart:io';
import 'dart:math';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../../core/auth/session_service.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/network/network_info.dart';
import '../domain/sync_queue_item.dart';
import '../domain/sync_queue_repository.dart';

class SyncQueueManager extends ChangeNotifier {
  SyncQueueManager({
    required Dio dio,
    required SyncQueueRepository repository,
    required NetworkInfo networkInfo,
    required SessionService session,
  })  : _dio = dio,
        _repository = repository,
        _networkInfo = networkInfo,
        _session = session;

  final Dio _dio;
  final SyncQueueRepository _repository;
  final NetworkInfo _networkInfo;
  final SessionService _session;

  List<SyncQueueItem> _items = <SyncQueueItem>[];
  StreamSubscription<List<ConnectivityResult>>? _connectivitySubscription;
  bool _initialized = false;
  bool _isSyncing = false;
  bool? _isOnline;

  List<SyncQueueItem> get items => List<SyncQueueItem>.unmodifiable(_items);
  bool get isSyncing => _isSyncing;
  bool? get isOnline => _isOnline;
  int get pendingCount => _items.length;
  int get failedCount => _items.where((item) => item.status == SyncQueueStatus.failed).length;

  Future<void> initialize() async {
    if (_initialized) return;
    _items = (await _repository.loadAll()).map((item) => item.status == SyncQueueStatus.syncing
        ? item.copyWith(status: SyncQueueStatus.queued)
        : item).toList(growable: true);
    await _repository.saveAll(_items);
    _isOnline = await _networkInfo.isConnected;
    _session.addListener(_handleSessionChanged);
    _connectivitySubscription = _networkInfo.onConnectivityChanged.listen((results) {
      final online = results.any((result) => result != ConnectivityResult.none);
      _isOnline = online;
      notifyListeners();
      if (online && _items.isNotEmpty && _session.isReady && _session.isAuthenticated) unawaited(syncPending());
    });
    _initialized = true;
    notifyListeners();
    _handleSessionChanged();
  }

  Future<SyncQueueItem> enqueueReading(Map<String, dynamic> payload) =>
      _enqueue(SyncQueueType.reading, <String, dynamic>{
        ...payload,
        'sync_uuid': payload['sync_uuid'] ?? _newUuid(),
      });

  Future<SyncQueueItem> enqueuePayment(Map<String, dynamic> payload) =>
      _enqueue(SyncQueueType.payment, <String, dynamic>{
        ...payload,
        'sync_uuid': payload['sync_uuid'] ?? _newUuid(),
      });

  Future<void> retry(String id) async {
    final index = _items.indexWhere((item) => item.id == id);
    if (index < 0) return;
    _items[index] = _items[index].copyWith(
      status: SyncQueueStatus.queued,
      clearError: true,
    );
    await _persistAndNotify();
    await syncPending();
  }

  Future<void> retryFailed() async {
    _items = _items.map((item) => item.status == SyncQueueStatus.failed
        ? item.copyWith(status: SyncQueueStatus.queued, clearError: true)
        : item).toList(growable: true);
    await _persistAndNotify();
    await syncPending();
  }

  Future<void> syncPending() async {
    if (_isSyncing || _items.isEmpty || !_session.isReady || !_session.isAuthenticated) return;
    // Lock before the first await so manual, automatic, and enqueue-triggered syncs
    // cannot submit the same UUID concurrently.
    _isSyncing = true;
    notifyListeners();
    try {
      _isOnline = await _networkInfo.isConnected;
      if (_isOnline != true) {
        notifyListeners();
        return;
      }
      final orderedIds = _items
          .where((item) => item.status != SyncQueueStatus.syncing)
          .map((item) => item.id)
          .toList(growable: false);
      for (final id in orderedIds) {
        final index = _items.indexWhere((item) => item.id == id);
        if (index < 0) continue;
        var item = _items[index];
        _items[index] = item.copyWith(
          status: SyncQueueStatus.syncing,
          attempts: item.attempts + 1,
          clearError: true,
        );
        await _persistAndNotify();

        try {
          await _send(item);
          _items.removeWhere((candidate) => candidate.id == id);
          await _persistAndNotify();
        } catch (error) {
          final currentIndex = _items.indexWhere((candidate) => candidate.id == id);
          if (currentIndex >= 0) {
            item = _items[currentIndex];
            _items[currentIndex] = item.copyWith(
              status: SyncQueueStatus.failed,
              lastError: _errorMessage(error),
            );
            await _persistAndNotify();
          }
          _isOnline = await _networkInfo.isConnected;
          // Keep strict FIFO ordering: later operations may depend on the first failure.
          break;
        }
      }
    } catch (error) {
      final syncingIndex = _items.indexWhere((item) => item.status == SyncQueueStatus.syncing);
      if (syncingIndex >= 0) {
        _items[syncingIndex] = _items[syncingIndex].copyWith(
          status: SyncQueueStatus.failed,
          lastError: _errorMessage(error),
        );
        try {
          await _repository.saveAll(_items);
        } catch (_) {
          // Keep the in-memory failure visible even if local storage is unavailable.
        }
      }
      _isOnline = false;
      notifyListeners();
    } finally {
      _isSyncing = false;
      notifyListeners();
    }
  }

  Future<SyncQueueItem> _enqueue(SyncQueueType type, Map<String, dynamic> payload) async {
    final ownerUserId = _session.user?['id']?.toString();
    if (!_session.isReady || !_session.isAuthenticated || ownerUserId == null || ownerUserId.isEmpty) {
      throw StateError('يلزم تسجيل الدخول قبل حفظ عملية للمزامنة.');
    }
    final item = SyncQueueItem(
      id: _newUuid(),
      type: type,
      payload: Map<String, dynamic>.from(payload),
      status: SyncQueueStatus.queued,
      createdAt: DateTime.now(),
      ownerUserId: ownerUserId,
    );
    _items.add(item);
    _items.sort((a, b) => a.createdAt.compareTo(b.createdAt));
    await _persistAndNotify();
    unawaited(syncPending());
    return item;
  }

  Future<void> _send(SyncQueueItem item) async {
    final currentUserId = _session.user?['id']?.toString();
    if (item.ownerUserId == null || item.ownerUserId != currentUserId) {
      throw StateError('هذه العملية محفوظة لحساب مستخدم آخر؛ سجّل الدخول بالحساب نفسه لمزامنتها.');
    }
    switch (item.type) {
      case SyncQueueType.reading:
        await _sendReading(item);
        break;
      case SyncQueueType.payment:
        await _sendPayment(item);
        break;
    }
  }

  Future<void> _sendReading(SyncQueueItem item) async {
    final payload = Map<String, dynamic>.from(item.payload);
    final imagePath = payload.remove('local_image_path')?.toString();
    dynamic body = <String, dynamic>{'readings': <Map<String, dynamic>>[payload]};
    if (imagePath != null && imagePath.isNotEmpty && await File(imagePath).exists()) {
      payload['image'] = await MultipartFile.fromFile(
        imagePath,
        filename: imagePath.split(Platform.pathSeparator).last,
      );
      body = FormData.fromMap(<String, dynamic>{'readings': <Map<String, dynamic>>[payload]});
    }

    final response = await _dio.post<dynamic>(ApiEndpoints.readingSyncBatch, data: body);
    final results = (response.data is Map) ? (response.data as Map)['results'] : null;
    if (results is! List || results.isEmpty) {
      throw StateError('استجابة مزامنة القراءة غير مكتملة.');
    }
    final result = results.first;
    if (result is! Map || result['success'] != true) {
      throw StateError(result is Map ? (result['message']?.toString() ?? 'رفض الخادم مزامنة القراءة.') : 'رفض الخادم مزامنة القراءة.');
    }
  }

  Future<void> _sendPayment(SyncQueueItem item) async {
    final response = await _dio.post<dynamic>(
      ApiEndpoints.collectInvoicePayment,
      data: item.payload,
    );
    if ((response.statusCode ?? 0) >= 400) {
      throw StateError('تعذر اعتماد عملية الدفع.');
    }
  }

  Future<void> _persistAndNotify() async {
    await _repository.saveAll(_items);
    notifyListeners();
  }

  String _errorMessage(Object error) {
    if (error is DioException) return error.message ?? 'تعذر الاتصال بالخادم.';
    return error.toString().replaceFirst('Bad state: ', '');
  }

  String _newUuid() {
    final random = Random.secure();
    final bytes = List<int>.generate(16, (_) => random.nextInt(256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    final hex = bytes.map((byte) => byte.toRadixString(16).padLeft(2, '0')).join();
    return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-'
        '${hex.substring(16, 20)}-${hex.substring(20)}';
  }

  void _handleSessionChanged() {
    if (_session.isReady && _session.isAuthenticated && _isOnline == true && _items.isNotEmpty) {
      unawaited(syncPending());
    }
  }

  @override
  void dispose() {
    _session.removeListener(_handleSessionChanged);
    _connectivitySubscription?.cancel();
    super.dispose();
  }
}
