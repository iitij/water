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
    this.attempts = 0,
    this.lastError,
  });

  final String id;
  final SyncQueueType type;
  final Map<String, dynamic> payload;
  final SyncQueueStatus status;
  final DateTime createdAt;
  final String? ownerUserId;
  final int attempts;
  final String? lastError;

  SyncQueueItem copyWith({
    SyncQueueStatus? status,
    int? attempts,
    String? lastError,
    bool clearError = false,
  }) => SyncQueueItem(
        id: id,
        type: type,
        payload: payload,
        status: status ?? this.status,
        createdAt: createdAt,
        ownerUserId: ownerUserId,
        attempts: attempts ?? this.attempts,
        lastError: clearError ? null : (lastError ?? this.lastError),
      );

  Map<String, dynamic> toJson() => <String, dynamic>{
        'id': id,
        'type': type.name,
        'payload': payload,
        'status': status.name,
        'created_at': createdAt.toIso8601String(),
        'owner_user_id': ownerUserId,
        'attempts': attempts,
        'last_error': lastError,
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
        attempts: int.tryParse(json['attempts']?.toString() ?? '') ?? 0,
        lastError: json['last_error']?.toString(),
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
  List<Object?> get props => <Object?>[id, type, payload, status, createdAt, ownerUserId, attempts, lastError];
}

enum SyncQueueType { reading, payment }
enum SyncQueueStatus { queued, syncing, failed }
