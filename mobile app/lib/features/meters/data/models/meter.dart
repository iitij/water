class Meter {
  const Meter({
    required this.id,
    required this.uniqueCode,
    this.pathNumber,
    this.subscriberId,
    this.squareId,
    this.subscriberName,
    this.squareName,
    this.subscriptionStartDate,
    this.tenantName,
    this.tenantPhone,
    this.status = 'active',
    this.balance = 0,
    this.initialReading = 0,
    this.lastReading = 0,
    this.installedAt,
    this.removedAt,
    this.isPathMatch = false,
  });

  final int id;
  final String uniqueCode;
  final int? pathNumber;
  final int? subscriberId;
  final int? squareId;
  final String? subscriberName;
  final String? squareName;
  final DateTime? subscriptionStartDate;
  final String? tenantName;
  final String? tenantPhone;
  final String status;
  final double balance;
  final double initialReading;
  final double lastReading;
  final DateTime? installedAt;
  final DateTime? removedAt;
  final bool isPathMatch;

  bool get isActive => status == 'active';
  double get outstandingBalance => balance < 0 ? -balance : 0;

  Meter copyWith({
    int? id,
    String? uniqueCode,
    int? pathNumber,
    int? subscriberId,
    int? squareId,
    String? subscriberName,
    String? squareName,
    DateTime? subscriptionStartDate,
    String? tenantName,
    String? tenantPhone,
    String? status,
    double? balance,
    double? initialReading,
    double? lastReading,
    DateTime? installedAt,
    DateTime? removedAt,
    bool? isPathMatch,
  }) {
    return Meter(
      id: id ?? this.id,
      uniqueCode: uniqueCode ?? this.uniqueCode,
      pathNumber: pathNumber ?? this.pathNumber,
      subscriberId: subscriberId ?? this.subscriberId,
      squareId: squareId ?? this.squareId,
      subscriberName: subscriberName ?? this.subscriberName,
      squareName: squareName ?? this.squareName,
      subscriptionStartDate: subscriptionStartDate ?? this.subscriptionStartDate,
      tenantName: tenantName ?? this.tenantName,
      tenantPhone: tenantPhone ?? this.tenantPhone,
      status: status ?? this.status,
      balance: balance ?? this.balance,
      initialReading: initialReading ?? this.initialReading,
      lastReading: lastReading ?? this.lastReading,
      installedAt: installedAt ?? this.installedAt,
      removedAt: removedAt ?? this.removedAt,
      isPathMatch: isPathMatch ?? this.isPathMatch,
    );
  }

  factory Meter.fromJson(Map<String, dynamic> json) {
    final subscriber = _asMap(json['subscriber']);
    final square = _asMap(json['square'] ?? subscriber?['square']);
    final lastReadingJson = json['last_reading'];
    final lastReadingMap = _asMap(lastReadingJson);
    return Meter(
      id: _asInt(json['id'] ?? json['server_id']) ?? 0,
      uniqueCode: (json['unique_code'] ?? json['meter_code'] ?? '').toString(),
      pathNumber: _asInt(json['path_number']),
      subscriberId: _asInt(json['subscriber_id'] ?? subscriber?['id']),
      squareId: _asInt(json['square_id'] ?? subscriber?['square_id'] ?? square?['id']),
      subscriberName: _asString(json['subscriber_name'] ?? subscriber?['name']),
      squareName: _asString(json['square_name'] ?? square?['name']),
      subscriptionStartDate: _asDate(
        json['subscription_start_date'] ?? subscriber?['subscription_start_date'],
      ),
      tenantName: _asString(json['tenant_name']),
      tenantPhone: _asString(json['tenant_phone']),
      status: (json['status'] ?? 'active').toString(),
      balance: _asDouble(json['balance']) ?? 0,
      initialReading: _asDouble(json['initial_reading']) ?? 0,
      lastReading: _asDouble(
            json['last_reading_value'] ??
                lastReadingMap?['current_reading'] ??
                (lastReadingJson is num ? lastReadingJson : null),
          ) ??
          0,
      installedAt: _asDate(json['installed_at']),
      removedAt: _asDate(json['removed_at']),
      isPathMatch: json['is_path_match'] == true || json['is_path_match'] == 1,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'unique_code': uniqueCode,
        'path_number': pathNumber,
        'subscriber_id': subscriberId,
        'square_id': squareId,
        'subscriber_name': subscriberName,
        'square_name': squareName,
        'subscription_start_date': _dateString(subscriptionStartDate),
        'tenant_name': tenantName,
        'tenant_phone': tenantPhone,
        'status': status,
        'balance': balance,
        'initial_reading': initialReading,
        'last_reading_value': lastReading,
        'installed_at': _dateString(installedAt),
        'removed_at': _dateString(removedAt),
        'is_path_match': isPathMatch,
      };
}

class MeterDraft {
  const MeterDraft({
    required this.uniqueCode,
    required this.subscriberId,
    this.pathNumber,
    this.tenantName,
    this.tenantPhone,
    this.status = 'active',
  });

  final String uniqueCode;
  final int subscriberId;
  final int? pathNumber;
  final String? tenantName;
  final String? tenantPhone;
  final String status;

  Map<String, dynamic> toJson() => {
        'unique_code': uniqueCode.trim(),
        'subscriber_id': subscriberId,
        'path_number': pathNumber,
        'tenant_name': _emptyToNull(tenantName),
        'tenant_phone': _emptyToNull(tenantPhone),
        'status': status,
      };
}

class SquareOption {
  const SquareOption({required this.id, required this.name, this.description});

  final int id;
  final String name;
  final String? description;

  factory SquareOption.fromJson(Map<String, dynamic> json) => SquareOption(
        id: _asInt(json['id'] ?? json['server_id']) ?? 0,
        name: (json['name'] ?? '').toString(),
        description: _asString(json['description']),
      );
}

class SubscriberOption {
  const SubscriberOption({
    required this.id,
    required this.name,
    this.squareId,
    this.squareName,
    this.phone,
    this.status = 'active',
    this.subscriptionStartDate,
    this.meterCount = 0,
  });

  final int id;
  final String name;
  final int? squareId;
  final String? squareName;
  final String? phone;
  final String status;
  final DateTime? subscriptionStartDate;
  final int meterCount;

  factory SubscriberOption.fromJson(Map<String, dynamic> json) {
    final square = _asMap(json['square']);
    final meters = json['meters'];
    return SubscriberOption(
      id: _asInt(json['id'] ?? json['server_id']) ?? 0,
      name: (json['name'] ?? '').toString(),
      squareId: _asInt(json['square_id'] ?? square?['id']),
      squareName: _asString(json['square_name'] ?? square?['name']),
      phone: _asString(json['phone']),
      status: (json['status'] ?? 'active').toString(),
      subscriptionStartDate: _asDate(json['subscription_start_date']),
      meterCount: _asInt(json['meters_count']) ??
          (meters is List ? meters.length : 0),
    );
  }
}

Map<String, dynamic>? _asMap(Object? value) {
  if (value is Map<String, dynamic>) return value;
  if (value is Map) return Map<String, dynamic>.from(value);
  return null;
}

String? _asString(Object? value) => value?.toString();

int? _asInt(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  return int.tryParse(value?.toString() ?? '');
}

double? _asDouble(Object? value) {
  if (value is num) return value.toDouble();
  return double.tryParse(value?.toString() ?? '');
}

DateTime? _asDate(Object? value) =>
    value == null ? null : DateTime.tryParse(value.toString());

String? _dateString(DateTime? value) => value?.toIso8601String().split('T').first;

String? _emptyToNull(String? value) {
  final trimmed = value?.trim();
  return trimmed == null || trimmed.isEmpty ? null : trimmed;
}
