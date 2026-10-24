import 'package:equatable/equatable.dart';

/// حدث تدقيق محلي كما يُرسل إلى `POST /api/audit-events`.
///
/// القاعدة: كل عملية حسّاسة جرت في الميدان — حتى بلا شبكة — تبقى لها بصمة
/// محلية ثم تُرفع مجمّعة عندما تعود الشبكة. لا يُرسل الفاعل من الجهاز أبداً؛
/// الخادم يأخذه من التوكن (AuditEventController::store).
///
/// مرجع: خطة الإصدار 2.0 — الفصلان 9.6 وS4-05.
class LocalAuditEvent extends Equatable {
  const LocalAuditEvent({
    required this.id,
    required this.event,
    required this.occurredAt,
    this.entityType,
    this.entityId,
    this.before,
    this.after,
    this.deviceId,
  });

  /// معرّف محلي (UUID) يمنع الرفع المزدوج بعد إعادة المحاولة.
  final String id;
  final String event;
  final DateTime occurredAt;
  final String? entityType;
  final String? entityId;
  final Map<String, dynamic>? before;
  final Map<String, dynamic>? after;
  final String? deviceId;

  Map<String, dynamic> toJson() => <String, dynamic>{
        'id': id,
        'event': event,
        'occurred_at': occurredAt.toUtc().toIso8601String(),
        'entity_type': entityType,
        'entity_id': entityId,
        'before': before,
        'after': after,
        'device_id': deviceId,
      };

  /// الحمولة المرسلة للخادم: بلا معرّف محلي (الخادم ينشئ معرّفه).
  Map<String, dynamic> toWireJson() => <String, dynamic>{
        'event': event,
        'occurred_at': occurredAt.toUtc().toIso8601String(),
        if (entityType != null) 'entity_type': entityType,
        if (entityId != null) 'entity_id': entityId,
        if (before != null) 'before': before,
        if (after != null) 'after': after,
        if (deviceId != null) 'device_id': deviceId,
      };

  factory LocalAuditEvent.fromJson(Map<String, dynamic> json) => LocalAuditEvent(
        id: json['id']?.toString() ?? '',
        event: json['event']?.toString() ?? '',
        occurredAt:
            DateTime.tryParse(json['occurred_at']?.toString() ?? '')?.toLocal() ??
                DateTime.now(),
        entityType: json['entity_type']?.toString(),
        entityId: json['entity_id']?.toString(),
        before: json['before'] is Map
            ? Map<String, dynamic>.from(json['before'] as Map)
            : null,
        after: json['after'] is Map
            ? Map<String, dynamic>.from(json['after'] as Map)
            : null,
        deviceId: json['device_id']?.toString(),
      );

  @override
  List<Object?> get props =>
      <Object?>[id, event, occurredAt, entityType, entityId, before, after, deviceId];
}
