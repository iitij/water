import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../../core/auth/session_service.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/network/network_info.dart';
import '../../../core/sync/sync_retry_policy.dart';
import '../domain/sync_batch.dart';
import '../domain/sync_failure_planner.dart';
import '../domain/sync_queue_item.dart';
import '../domain/sync_queue_order.dart';
import '../domain/sync_queue_repository.dart';

class SyncQueueManager extends ChangeNotifier {
  SyncQueueManager({
    required Dio dio,
    required SyncQueueRepository repository,
    required NetworkInfo networkInfo,
    required SessionService session,
    SyncRetryPolicy retryPolicy = const SyncRetryPolicy(),
  })  : _dio = dio,
        _repository = repository,
        _networkInfo = networkInfo,
        _session = session,
        _retryPolicy = retryPolicy;

  static final RegExp _uuidPattern = RegExp(
    r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
  );

  final Dio _dio;
  final SyncQueueRepository _repository;
  final NetworkInfo _networkInfo;
  final SessionService _session;
  final SyncRetryPolicy _retryPolicy;

  List<SyncQueueItem> _items = <SyncQueueItem>[];
  StreamSubscription<List<ConnectivityResult>>? _connectivitySubscription;
  StreamSubscription<void>? _externalChangeSubscription;
  bool _initialized = false;
  bool _isSyncing = false;
  bool _suppressExternalReload = false;
  bool? _isOnline;
  Timer? _retryTimer;

  List<SyncQueueItem> get items => List<SyncQueueItem>.unmodifiable(_items);
  bool get isSyncing => _isSyncing;
  bool? get isOnline => _isOnline;
  int get pendingCount => _items.length;
  int get failedCount =>
      _items.where((item) => item.status == SyncQueueStatus.failed).length;

  /// صفوف تحتاج تدخلاً بشرياً (بيانات مرفوضة أو صلاحية) — لا تُعاد تلقائياً.
  int get attentionCount => _items
      .where((item) => item.status == SyncQueueStatus.needsAttention)
      .length;

  /// كل ما يحتاج تدخلاً: مرفوض + مستنفد.
  int get blockedCount => failedCount + attentionCount;

  /// عدد العمليات التي تنتظر مهلة إعادة المحاولة التلقائية.
  int get waitingCount => _items
      .where((item) =>
          item.status == SyncQueueStatus.queued && item.nextAttemptAt != null)
      .length;

  /// أقرب موعد لمحاولة قادمة، أو `null` إن لم يكن هناك شيء مجدول.
  DateTime? get nextRetryAt {
    DateTime? earliest;
    for (final item in _items) {
      final next = item.nextAttemptAt;
      if (next == null) continue;
      if (earliest == null || next.isBefore(earliest)) earliest = next;
    }
    return earliest;
  }

  /// المهلة قبل المحاولة التالية لنفس عدد المحاولات — تعرضها شاشة المزامنة.
  Duration? backoffFor(int attempts, {int? statusCode}) {
    final decision = _retryPolicy.decide(
      attempts: attempts,
      statusCode: statusCode,
    );
    final next = decision.nextAttemptAt;
    if (next == null) return null;
    final delay = next.difference(DateTime.now().toUtc());
    return delay.isNegative ? Duration.zero : delay;
  }

  Future<void> initialize() async {
    if (_initialized) return;
    final loaded = await _repository.loadAll();
    _items = SyncQueueOrder.sortForSync(
      loaded.map(
        (item) => item.status == SyncQueueStatus.syncing
            ? item.copyWith(status: SyncQueueStatus.queued)
            : item,
      ),
    ).toList(growable: true);
    await _runRepositoryWrite(() => _repository.saveAll(_items));

    _isOnline = await _networkInfo.isConnected;
    _session.addListener(_handleSessionChanged);
    _externalChangeSubscription = _repository.onExternalChange?.listen((_) {
      if (!_suppressExternalReload) {
        unawaited(reload());
      }
    });
    _connectivitySubscription = _networkInfo.onConnectivityChanged.listen((results) {
      final online = results.any((result) => result != ConnectivityResult.none);
      _isOnline = online;
      notifyListeners();
      if (online &&
          _items.isNotEmpty &&
          _session.isReady &&
          _session.isAuthenticated) {
        unawaited(syncPending());
      }
    });
    _initialized = true;
    notifyListeners();
    _handleSessionChanged();
  }

  /// Reloads active queue items from the underlying SQLite repository when
  /// another module (e.g. `ReadingsRepositoryImpl`) mutates `AppDatabase`.
  Future<void> reload() async {
    final fresh = await _repository.loadAll();
    _items = SyncQueueOrder.sortForSync(fresh).toList(growable: true);
    notifyListeners();
    if (_isOnline == true &&
        !_isSyncing &&
        _items.any((item) => item.status == SyncQueueStatus.queued) &&
        _session.isReady &&
        _session.isAuthenticated) {
      unawaited(syncPending());
    }
  }

  Future<SyncQueueItem> enqueueReading(Map<String, dynamic> payload) {
    final syncUuid = _ensureUuid(payload['sync_uuid']?.toString());
    return _enqueue(
      SyncQueueType.reading,
      <String, dynamic>{
        ...payload,
        'sync_uuid': syncUuid,
        'client_action_id': payload['client_action_id'] ?? syncUuid,
        'local_id': payload['local_id'] ?? syncUuid,
      },
      preferredId: syncUuid,
    );
  }

  Future<SyncQueueItem> enqueuePayment(
    Map<String, dynamic> payload, {
    String? idempotencyKey,
  }) {
    final rawSyncUuid = payload['sync_uuid']?.toString() ?? idempotencyKey;
    final syncUuid = _ensureUuid(rawSyncUuid);
    return _enqueue(
      SyncQueueType.payment,
      <String, dynamic>{
        ...payload,
        'sync_uuid': syncUuid,
        if (idempotencyKey != null &&
            idempotencyKey.isNotEmpty &&
            !_uuidPattern.hasMatch(idempotencyKey))
          'local_receipt_number': idempotencyKey,
      },
      preferredId: syncUuid,
    );
  }

  /// إلغاء سند قبض (بدل حذفه) — يُنفَّذ فوراً عند الاتصال، ويُحفظ في الطابور
  /// عند الانقطاع حتى لا يضيع سبب الإلغاء.
  Future<SyncQueueItem> enqueuePaymentVoid({
    required int paymentId,
    required String reason,
  }) {
    if (reason.trim().length < 10) {
      throw StateError('سبب الإلغاء مطلوب ولا يقل عن 10 أحرف.');
    }
    return _enqueue(
      SyncQueueType.paymentVoid,
      <String, dynamic>{
        'payment_id': paymentId,
        'reason': reason.trim(),
        'requested_at': DateTime.now().toUtc().toIso8601String(),
      },
    );
  }

  /// إغلاق صندوق المحصّل من الميدان؛ يُعاد إرساله بعد الانقطاع بنفس المعرّف.
  Future<SyncQueueItem> enqueueCashSessionClose({
    required int cashSessionId,
    required num countedCash,
    String? notes,
  }) {
    return _enqueue(
      SyncQueueType.cashSessionClose,
      <String, dynamic>{
        'cash_session_id': cashSessionId,
        'counted_amount': countedCash,
        if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
        'client_closed_at': DateTime.now().toUtc().toIso8601String(),
      },
    );
  }

  Future<void> retry(String id) async {
    final index = _items.indexWhere((item) => item.id == id);
    if (index < 0) return;
    final updated = _items[index].copyWith(
      status: SyncQueueStatus.queued,
      clearError: true,
      clearNextAttempt: true,
      clearStatusCode: true,
    );
    _items[index] = updated;
    await _runRepositoryWrite(() => _repository.upsert(updated));
    notifyListeners();
    await syncPending();
  }

  Future<void> retryFailed() async {
    final updatedItems = <SyncQueueItem>[];
    for (var i = 0; i < _items.length; i++) {
      if (_items[i].status == SyncQueueStatus.failed ||
          _items[i].status == SyncQueueStatus.needsAttention) {
        final reset = _items[i].copyWith(
          status: SyncQueueStatus.queued,
          clearError: true,
          clearNextAttempt: true,
          clearStatusCode: true,
        );
        _items[i] = reset;
        updatedItems.add(reset);
      }
    }
    await _runRepositoryWrite(() async {
      for (final item in updatedItems) {
        await _repository.upsert(item);
      }
    });
    notifyListeners();
    await syncPending();
  }

  Future<void> syncPending() async {
    if (_isSyncing || !_session.isReady || !_session.isAuthenticated) return;
    // Lock before the first await so manual, automatic, and enqueue-triggered syncs
    // cannot submit the same UUID concurrently.
    _isSyncing = true;
    notifyListeners();
    try {
      // الترتيب: النقد أولاً ثم الأقدم؛ ومهلة التهدئة تُحترم داخل الحلقة.
      _items = SyncQueueOrder.sortForSync(await _repository.loadAll())
          .toList(growable: true);
      if (_items.isEmpty) {
        notifyListeners();
        return;
      }

      _isOnline = await _networkInfo.isConnected;
      if (_isOnline != true) {
        notifyListeners();
        return;
      }

      final now = DateTime.now();
      // الصفوف التي تحتاج تدخلاً بشرياً (`failed`) لا تُعاد تلقائياً؛ يعيدها
      // المستخدم بزر «إعادة المحاولة». أما الأخطاء المؤقتة فتبقى `queued`
      // بمهلة تهدئة ويُعاد إرسالها تلقائياً عند انقضائها.
      final orderedIds = eligibleForSync(_items, now)
          .map((item) => item.id)
          .toList(growable: false);
      _scheduleRetryWakeUp(now);

      for (final id in orderedIds) {
        final index = _items.indexWhere((item) => item.id == id);
        if (index < 0) continue;
        final currentUserId = _session.user?['id']?.toString();
        var item = _items[index];

        // احترام مهلة التهدئة: عنصر واحد ينتظر لا يجوز أن يعطّل بقية الطابور،
        // لكن الترتيب الصارم (FIFO) يبقى قائماً لكل ما حان وقته.
        if (!item.isReadyAt(now)) {
          continue;
        }

        // Adopt unowned legacy/field rows into the active authenticated account,
        // while strictly protecting items owned by a different user ID.
        if ((item.ownerUserId == null || item.ownerUserId!.isEmpty) &&
            currentUserId != null &&
            currentUserId.isNotEmpty) {
          item = item.copyWith(ownerUserId: currentUserId);
        }

        final syncingItem = item.copyWith(
          status: SyncQueueStatus.syncing,
          attempts: item.attempts + 1,
          clearError: true,
        );
        _items[index] = syncingItem;
        await _runRepositoryWrite(() => _repository.upsert(syncingItem));
        notifyListeners();

        try {
          await _send(syncingItem);
          _items.removeWhere((candidate) => candidate.id == id);
          notifyListeners();
        } catch (error) {
          final currentIndex = _items.indexWhere((candidate) => candidate.id == id);
          if (currentIndex >= 0) {
            final recorded = _applyFailure(_items[currentIndex], error);
            _items[currentIndex] = recorded;
            await _runRepositoryWrite(
              () => _repository.recordSyncFailure(
                item: recorded,
                error: recorded.lastError ?? 'تعذر إرسال العملية.',
                retryAt: recorded.nextAttemptAt,
              ),
            );
            notifyListeners();
          }
          await _refreshOnlineState();
          // Keep strict FIFO ordering: later operations may depend on the first failure.
          break;
        }
      }
    } catch (error) {
      final syncingIndex = _items.indexWhere(
        (item) => item.status == SyncQueueStatus.syncing,
      );
      if (syncingIndex >= 0) {
        final recorded = _applyFailure(_items[syncingIndex], error);
        _items[syncingIndex] = recorded;
        try {
          await _runRepositoryWrite(
            () => _repository.recordSyncFailure(
              item: recorded,
              error: recorded.lastError ?? 'تعذر إرسال العملية.',
              retryAt: recorded.nextAttemptAt,
            ),
          );
        } catch (_) {
          // Keep the in-memory failure visible even if local storage is unavailable.
        }
      }
      await _refreshOnlineState();
      notifyListeners();
    } finally {
      _isSyncing = false;
      _scheduleRetryWakeUp(DateTime.now());
      notifyListeners();
    }
  }

  Future<SyncQueueItem> _enqueue(
    SyncQueueType type,
    Map<String, dynamic> payload, {
    String? preferredId,
  }) async {
    final ownerUserId = _session.user?['id']?.toString();
    if (!_session.isReady ||
        !_session.isAuthenticated ||
        ownerUserId == null ||
        ownerUserId.isEmpty) {
      throw StateError('يلزم تسجيل الدخول قبل حفظ عملية للمزامنة.');
    }

    final itemId = _ensureUuid(preferredId);
    final existingIndex = _items.indexWhere((existing) => existing.id == itemId);
    if (existingIndex >= 0) {
      return _items[existingIndex];
    }

    final item = SyncQueueItem(
      id: itemId,
      type: type,
      payload: Map<String, dynamic>.from(payload),
      status: SyncQueueStatus.queued,
      createdAt: DateTime.now(),
      ownerUserId: ownerUserId,
    );
    _items.add(item);
    _items.sort((a, b) => a.createdAt.compareTo(b.createdAt));
    await _runRepositoryWrite(() => _repository.upsert(item));
    notifyListeners();
    unawaited(syncPending());
    return item;
  }

  Future<void> _send(SyncQueueItem item) async {
    final currentUserId = _session.user?['id']?.toString();
    // الصفوف القديمة بلا مالك تُنسب للمستخدم الحالي عند المزامنة (سياسة التبنّي)،
    // أما أي اختلاف صريح في المالك فيُوقف الإرسال لحماية بيانات الحساب الآخر.
    if (item.ownerUserId != null &&
        item.ownerUserId!.isNotEmpty &&
        item.ownerUserId != currentUserId) {
      throw StateError(
        'هذه العملية محفوظة لحساب مستخدم آخر؛ سجّل الدخول بالحساب نفسه لمزامنتها.',
      );
    }
    switch (item.type) {
      case SyncQueueType.reading:
        await _sendReading(item);
      case SyncQueueType.payment:
        await _sendPayment(item);
      case SyncQueueType.paymentVoid:
        await _sendPaymentVoid(item);
      case SyncQueueType.cashSessionClose:
        await _sendCashSessionClose(item);
    }
  }

  /// إلغاء سند قبض. الخادم يرفض طلباً بلا سبب واضح (10 أحرف على الأقل).
  Future<void> _sendPaymentVoid(SyncQueueItem item) async {
    final payload = Map<String, dynamic>.from(item.payload);
    payload.remove('owner_user_id');
    final paymentId = _toNullableInt(payload.remove('payment_id') ?? payload['id']);
    if (paymentId == null) {
      throw StateError('سند الإلغاء لا يحمل رقم سند صالحاً.');
    }
    final reason = payload['reason']?.toString().trim() ?? '';
    if (reason.length < 10) {
      throw StateError('سبب الإلغاء مطلوب ولا يقل عن 10 أحرف.');
    }

    final response = await _dio.post<dynamic>(
      ApiEndpoints.voidPayment(paymentId),
      data: <String, dynamic>{
        'reason': reason,
        if (payload['sync_uuid'] != null) 'sync_uuid': payload['sync_uuid'],
      },
      options: Options(headers: <String, dynamic>{'Idempotency-Key': item.id}),
    );
    final responseMap = _asMap(response.data);
    await _runRepositoryWrite(
      () => _repository.markPaymentSynced(
        id: item.id,
        serverResponseJson: responseMap == null ? null : jsonEncode(responseMap),
      ),
    );
  }

  /// إغلاق صندوق المحصّل. يُرسل النقد المعدود فقط؛ الأرقام المتوقعة تُحسب
  /// على الخادم من السندات الفعلية (خطة 2.0 — الفصل 5.2).
  Future<void> _sendCashSessionClose(SyncQueueItem item) async {
    final payload = Map<String, dynamic>.from(item.payload);
    payload.remove('owner_user_id');
    final sessionId = _toNullableInt(payload.remove('cash_session_id'));
    if (sessionId == null) {
      throw StateError('طلب إغلاق الصندوق لا يحمل رقم صندوق صالحاً.');
    }
    final counted = payload['counted_amount'] ?? payload['counted_cash'];
    final body = <String, dynamic>{
      'counted_amount': counted,
      if (payload['notes'] != null) 'notes': payload['notes'],
    };

    final response = await _dio.post<dynamic>(
      ApiEndpoints.closeCashSession(sessionId),
      data: body,
      options: Options(headers: <String, dynamic>{'Idempotency-Key': item.id}),
    );
    final responseMap = _asMap(response.data);
    await _runRepositoryWrite(
      () => _repository.markPaymentSynced(
        id: item.id,
        serverResponseJson: responseMap == null ? null : jsonEncode(responseMap),
      ),
    );
  }

  Future<void> _sendReading(SyncQueueItem item) async {
    final payload = Map<String, dynamic>.from(item.payload);
    final localImage = payload.remove('local_image_path')?.toString();
    final fieldImage = payload.remove('image_path')?.toString();
    payload.remove('owner_user_id');

    final syncUuid = _ensureUuid(payload['sync_uuid']?.toString() ?? item.id);
    payload['sync_uuid'] = syncUuid;
    payload['client_action_id'] ??= item.id;
    payload['local_id'] ??= item.id;

    final imagePath = (localImage != null && localImage.isNotEmpty)
        ? localImage
        : fieldImage;

    Object body = <String, dynamic>{
      'readings': <Map<String, dynamic>>[payload],
    };
    if (imagePath != null && imagePath.isNotEmpty && await File(imagePath).exists()) {
      payload['image'] = await MultipartFile.fromFile(
        imagePath,
        filename: imagePath.split(Platform.pathSeparator).last,
      );
      body = FormData.fromMap(<String, dynamic>{
        'readings': <Map<String, dynamic>>[payload],
      });
    }

    final response = await _dio.post<dynamic>(
      ApiEndpoints.readingSyncBatch,
      data: body,
      options: Options(headers: <String, dynamic>{'Idempotency-Key': syncUuid}),
    );
    final responseMap = _asMap(response.data);
    final results = responseMap?['results'];
    if (results is! List || results.isEmpty) {
      throw StateError('استجابة مزامنة القراءة غير مكتملة.');
    }
    final result = _asMap(results.first);
    if (result == null || result['success'] != true) {
      throw StateError(
        result?['message']?.toString() ?? 'رفض الخادم مزامنة القراءة.',
      );
    }

    final readingMap = _asMap(result['reading']) ?? _asMap(result['data']);
    final serverId = _toNullableInt(
      readingMap?['id'] ?? result['reading_id'] ?? result['id'],
    );
    if (serverId == null) {
      throw StateError('أكد الخادم مزامنة القراءة دون إرجاع رقم القراءة الرسمي.');
    }

    await _runRepositoryWrite(
      () => _repository.markReadingSynced(
        id: item.id,
        serverId: serverId,
        isInvoiced: _nullableBool(
          readingMap?['is_invoiced'] ?? result['invoice_issued'],
        ),
        invoiceId: _toNullableInt(
          readingMap?['invoice_id'] ?? _asMap(result['invoice_data'])?['id'],
        ),
        serverReading: readingMap,
        serverResponseJson: jsonEncode(result),
      ),
    );
  }

  Future<void> _sendPayment(SyncQueueItem item) async {
    final payload = Map<String, dynamic>.from(item.payload);
    payload.remove('local_receipt_number');
    payload.remove('owner_user_id');

    final syncUuid = _ensureUuid(payload['sync_uuid']?.toString() ?? item.id);
    payload['sync_uuid'] = syncUuid;

    final hasInvoice = payload['invoice_id'] != null;
    final endpoint = hasInvoice
        ? ApiEndpoints.collectInvoicePayment
        : ApiEndpoints.payments;

    final response = await _dio.post<dynamic>(
      endpoint,
      data: payload,
      options: Options(headers: <String, dynamic>{'Idempotency-Key': syncUuid}),
    );
    if ((response.statusCode ?? 0) >= 400) {
      throw StateError('تعذر اعتماد عملية الدفع.');
    }

    final responseMap = _asMap(response.data);
    await _runRepositoryWrite(
      () => _repository.markPaymentSynced(
        id: item.id,
        serverResponseJson: responseMap == null ? null : jsonEncode(responseMap),
      ),
    );
  }

  /// يحوّل فشلاً إلى حالة صف عبر دالة نقية (`planSyncFailure`) قابلة للاختبار.
  SyncQueueItem _applyFailure(SyncQueueItem item, Object error) {
    final plan = planSyncFailure(
      attempts: item.attempts,
      statusCode: _statusCodeOf(error),
      serverMessage: _serverMessageOf(error) ?? _errorMessage(error),
    );
    return applyFailurePlan(item, plan);
  }

  int? _statusCodeOf(Object error) {
    if (error is DioException) return error.response?.statusCode;
    return null;
  }

  String? _serverMessageOf(Object error) {
    if (error is! DioException) return null;
    final body = _asMap(error.response?.data);
    final message = body?['message']?.toString();
    return message != null && message.trim().isNotEmpty ? message.trim() : null;
  }

  Future<void> _refreshOnlineState() async {
    try {
      _isOnline = await _networkInfo.isConnected;
    } catch (_) {
      _isOnline = false;
    }
  }

  /// يوقظ المزامنة عند أول موعد استحقاق قادم، مرة واحدة فقط.
  void _scheduleRetryWakeUp(DateTime now) {
    _retryTimer?.cancel();
    _retryTimer = null;
    DateTime? earliest;
    for (final item in _items) {
      final next = item.nextAttemptAt;
      if (next == null || !next.isAfter(now)) continue;
      if (earliest == null || next.isBefore(earliest)) earliest = next;
    }
    if (earliest == null) return;
    final delay = earliest.difference(now);
    _retryTimer = Timer(delay, () {
      _retryTimer = null;
      if (_session.isReady && _session.isAuthenticated && _isOnline != false) {
        unawaited(syncPending());
      }
    });
  }

  Future<T> _runRepositoryWrite<T>(Future<T> Function() action) async {
    _suppressExternalReload = true;
    try {
      return await action();
    } finally {
      _suppressExternalReload = false;
    }
  }

  String _errorMessage(Object error) {
    if (error is DioException) {
      final body = _asMap(error.response?.data);
      final serverMessage = body?['message']?.toString();
      if (serverMessage != null && serverMessage.trim().isNotEmpty) {
        return serverMessage.trim();
      }
      return error.message ?? 'تعذر الاتصال بالخادم.';
    }
    return error.toString().replaceFirst('Bad state: ', '');
  }

  String _ensureUuid(String? candidate) {
    if (candidate != null && _uuidPattern.hasMatch(candidate.trim())) {
      return candidate.trim().toLowerCase();
    }
    return _newUuid();
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

  static Map<String, dynamic>? _asMap(Object? value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return null;
  }

  static int? _toNullableInt(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  static bool? _nullableBool(Object? value) {
    if (value == null) return null;
    return value == true || value == 1 || value.toString().toLowerCase() == 'true';
  }

  void _handleSessionChanged() {
    if (_session.isReady &&
        _session.isAuthenticated &&
        _isOnline == true &&
        _items.isNotEmpty) {
      unawaited(syncPending());
    }
  }

  @override
  void dispose() {
    _retryTimer?.cancel();
    _session.removeListener(_handleSessionChanged);
    _connectivitySubscription?.cancel();
    _externalChangeSubscription?.cancel();
    super.dispose();
  }
}
