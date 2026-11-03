import 'package:equatable/equatable.dart';

/// جهاز مسجّل على حساب الموظف (خطة 2.0 — الفصل 9.7).
///
/// الغرض العملي: جهاز مفقود أو مسروق يحمل توكناً وجلسة مفتوحة. المشرف أو
/// صاحب الحساب يُبطل الجهاز من التطبيق، فيرفض الخادم أول طلب لاحق منه.
class DeviceRecord extends Equatable {
  const DeviceRecord({
    required this.id,
    required this.deviceUuid,
    this.name,
    this.platform,
    this.appVersion,
    this.lastSeenAt,
    this.revokedAt,
    this.isCurrent = false,
    this.createdAt,
  });

  final DateTime? createdAt;
  final int id;
  final String deviceUuid;
  final String? name;
  final String? platform;
  final String? appVersion;
  final DateTime? lastSeenAt;
  final DateTime? revokedAt;

  /// هل هذا هو الجهاز الذي نستخدمه الآن؟ (يمنع إبطال الذات بالخطأ)
  final bool isCurrent;

  bool get isRevoked => revokedAt != null;

  /// اسم مقروء للعرض: الاسم المخزّن، وإلا المنصّة، وإلا آخر 6 من المعرّف.
  String get displayName {
    final stored = name?.trim();
    if (stored != null && stored.isNotEmpty) return stored;
    final platformLabel = platformLabelOf(platform);
    final suffix = deviceUuid.length <= 6
        ? deviceUuid
        : deviceUuid.substring(deviceUuid.length - 6);
    return '$platformLabel • $suffix';
  }

  static String platformLabelOf(String? platform) {
    if (platform == null || platform.trim().isEmpty) return 'جهاز';
    if (platform == 'android') return 'أندرويد';
    if (platform == 'ios') return 'آيفون';
    if (platform == 'windows') return 'ويندوز';
    if (platform == 'linux') return 'لينكس';
    if (platform == 'macos') return 'ماك';
    return platform;
  }

  factory DeviceRecord.fromJson(
    Map<String, dynamic> json, {
    String? currentDeviceUuid,
  }) {
    final uuid = json['device_uuid']?.toString() ?? '';
    return DeviceRecord(
      id: (json['id'] as num?)?.toInt() ?? 0,
      deviceUuid: uuid,
      name: json['name']?.toString(),
      createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
      platform: json['platform']?.toString(),
      appVersion: json['app_version']?.toString(),
      lastSeenAt: DateTime.tryParse(json['last_seen_at']?.toString() ?? ''),
      revokedAt: DateTime.tryParse(json['revoked_at']?.toString() ?? ''),
      isCurrent: currentDeviceUuid != null && uuid == currentDeviceUuid,
    );
  }

  DeviceRecord asRevoked(DateTime when) => DeviceRecord(
    id: id,
    createdAt: createdAt,
    deviceUuid: deviceUuid,
    name: name,
    platform: platform,
    appVersion: appVersion,
    lastSeenAt: lastSeenAt,
    revokedAt: when,
    isCurrent: isCurrent,
  );

  @override
  List<Object?> get props => <Object?>[
    id,
    deviceUuid,
    name,
    platform,
    lastSeenAt,
    revokedAt,
    isCurrent,
  ];
}

/// ترتيب الأجهزة للعرض: النشطة أولاً، والأحدث استخداماً أولاً.
///
/// دالة نقية خارج الواجهة حتى تُختبر وحدها وتُستخدم في الشاشة والتقارير.
List<DeviceRecord> sortDevices(List<DeviceRecord> devices) {
  final sorted = List<DeviceRecord>.from(devices);
  sorted.sort((a, b) {
    if (a.isRevoked != b.isRevoked) return a.isRevoked ? 1 : -1;
    final aSeen = a.lastSeenAt ?? DateTime.fromMillisecondsSinceEpoch(0);
    final bSeen = b.lastSeenAt ?? DateTime.fromMillisecondsSinceEpoch(0);
    return bSeen.compareTo(aSeen);
  });
  return sorted;
}
