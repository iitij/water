import 'dart:convert';

import 'package:equatable/equatable.dart';

class SyncQueueItem extends Equatable {
  const SyncQueueItem({
    required this.id,
    required this.type,
    required this.payload,
    required this.status,
    required this.createdAt,
    this.ownerUserId,
    this.ownerServer,
    this.attempts = 0,
    this.lastError,
    this.nextAttemptAt,
    this.lastStatusCode,
  });

  final String id;
  final SyncQueueType type;
  final Map<String, dynamic> payload;
  final SyncQueueStatus status;
  final DateTime createdAt;
  final String? ownerUserId;
  final String? ownerServer;
  final int attempts;
  final String? lastError;

  /// متى يُسمح بمحاولة الإرسال التالية (فترة تهدئة). `null` يعني «فوراً».
  final DateTime? nextAttemptAt;

  /// رمز HTTP لآخر فشل، يُستخدم في شاشة المزامنة وفي تقرير الدعم.
  final int? lastStatusCode;

  bool isReadyAt(DateTime now) {
    final next = nextAttemptAt;
    return next == null || !next.isAfter(now);
  }

  SyncQueueItem copyWith({
    SyncQueueStatus? status,
    int? attempts,
    String? ownerUserId,
    String? lastError,
    bool clearError = false,
    DateTime? nextAttemptAt,
    bool clearNextAttempt = false,
    int? lastStatusCode,
    bool clearStatusCode = false,
  }) =>
      SyncQueueItem(
        id: id,
        type: type,
        payload: payload,
        status: status ?? this.status,
        createdAt: createdAt,
        ownerUserId: ownerUserId ?? this.ownerUserId,
        ownerServer: ownerServer,
        attempts: attempts ?? this.attempts,
        lastError: clearError ? null : (lastError ?? this.lastError),
        nextAttemptAt:
            clearNextAttempt ? null : (nextAttemptAt ?? this.nextAttemptAt),
        lastStatusCode:
            clearStatusCode ? null : (lastStatusCode ?? this.lastStatusCode),
      );

  Map<String, dynamic> toJson() => <String, dynamic>{
        'id': id,
        'type': type.name,
        'payload': payload,
        'status': status.name,
        'created_at': createdAt.toIso8601String(),
        'owner_user_id': ownerUserId,
        'owner_server': ownerServer,
        'attempts': attempts,
        'last_error': lastError,
        'next_attempt_at': nextAttemptAt?.toIso8601String(),
        'last_status_code': lastStatusCode,
      };

  factory SyncQueueItem.fromJson(Map<String, dynamic> json) => SyncQueueItem(
        id: json['id']?.toString() ?? '',
        type: SyncQueueType.values.firstWhere(
          (item) => item.name == json['type'],
          orElse: () => SyncQueueType.reading,
        ),
        payload: json['payload'] is Map
            ? Map<String, dynamic>.from(json['payload'] as Map)
            : <String, dynamic>{},
        status: SyncQueueStatus.values.firstWhere(
          (item) => item.name == json['status'],
          orElse: () => SyncQueueStatus.queued,
        ),
        createdAt: DateTime.tryParse(json['created_at']?.toString() ?? '') ?? DateTime.now(),
        ownerUserId: json['owner_user_id']?.toString(),
        ownerServer: json['owner_server']?.toString(),
        attempts: int.tryParse(json['attempts']?.toString() ?? '') ?? 0,
        lastError: json['last_error']?.toString(),
        nextAttemptAt: DateTime.tryParse(json['next_attempt_at']?.toString() ?? ''),
        lastStatusCode: int.tryParse(json['last_status_code']?.toString() ?? ''),
      );

  static String encodeList(List<SyncQueueItem> items) =>
      jsonEncode(items.map((item) => item.toJson()).toList(growable: false));

  /// Tolerant decoder used by the legacy SharedPreferences migration: a single
  /// corrupt row must never discard the whole local queue.
  static List<SyncQueueItem> decodeList(String? raw) {
    if (raw == null || raw.isEmpty) return <SyncQueueItem>[];
    try {
      final decoded = jsonDecode(raw);
      if (decoded is! List) return <SyncQueueItem>[];
      return decoded
          .whereType<Map<Object?, Object?>>()
          .map((row) => SyncQueueItem.fromJson(Map<String, dynamic>.from(row)))
          .toList(growable: true);
    } on FormatException {
      return <SyncQueueItem>[];
    }
  }

  @override
  List<Object?> get props => <Object?>[
        id,
        type,
        payload,
        status,
        createdAt,
        ownerUserId,
        ownerServer,
        attempts,
        lastError,
        nextAttemptAt,
        lastStatusCode,
      ];
}

enum SyncQueueType {
  reading,
  payment,

  /// إلغاء سند قبض بدل حذفه (خطة 2.0 — الفصل 5.3).
  paymentVoid,

  /// إغلاق صندوق المحصّل وإقفال العدّ (خطة 2.0 — الفصل 5.2).
  cashSessionClose,
}

enum SyncQueueStatus {
  queued,
  syncing,

  /// فشل دائم يحتاج تدخلاً بشرياً (بيانات أو صلاحية) — لا يُعاد تلقائياً.
  needsAttention,

  /// استُنفدت المحاولات التلقائية — يبقى ظاهراً حتى يعالجه المستخدم.
  failed,
}
