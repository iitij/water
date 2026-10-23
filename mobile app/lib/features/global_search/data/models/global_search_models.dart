/// استجابة البحث الكاملة: الاستعلام الأصلي + مجموعات النتائج المرخّصة فقط.
class GlobalSearchResponse {
  const GlobalSearchResponse({
    required this.query,
    this.groups = const <GlobalSearchGroup>[],
    this.minLength = 2,
  });

  final String query;
  final List<GlobalSearchGroup> groups;
  final int minLength;

  bool get isEmpty => groups.isEmpty;

  int get itemCount => groups.fold<int>(0, (sum, group) => sum + group.items.length);

  factory GlobalSearchResponse.fromJson(Map<String, dynamic> json) {
    final rawGroups = json['groups'];
    final groups = rawGroups is List
        ? rawGroups
            .whereType<Map<String, dynamic>>()
            .map(GlobalSearchGroup.fromJson)
            .where((group) => group.items.isNotEmpty)
            .toList(growable: false)
        : const <GlobalSearchGroup>[];

    return GlobalSearchResponse(
      query: _asText(json['query']) ?? '',
      groups: groups,
      minLength: _asInt(json['min_length']) ?? 2,
    );
  }
}

/// مجموعة نتائج من نوع واحد (مشتركون، فواتير، عدادات، سندات، مربعات).
class GlobalSearchGroup {
  const GlobalSearchGroup({
    required this.type,
    required this.label,
    this.items = const <GlobalSearchItem>[],
  });

  final String type;
  final String label;
  final List<GlobalSearchItem> items;

  factory GlobalSearchGroup.fromJson(Map<String, dynamic> json) {
    final rawItems = json['items'];
    return GlobalSearchGroup(
      type: _asText(json['type']) ?? '',
      label: _asText(json['label']) ?? '',
      items: rawItems is List
          ? rawItems
              .whereType<Map<String, dynamic>>()
              .map(GlobalSearchItem.fromJson)
              .toList(growable: false)
          : const <GlobalSearchItem>[],
    );
  }
}

/// نتيجة واحدة قابلة للنقر.
class GlobalSearchItem {
  const GlobalSearchItem({
    required this.id,
    required this.title,
    this.subtitle,
    this.badge,
    this.amount,
    this.subscriberId,
  });

  final int id;
  final String title;
  final String? subtitle;
  final String? badge;
  final double? amount;
  final int? subscriberId;

  /// سطر واحد يجمع الشارة (الحالة/الطريقة) والوصف.
  String? get caption {
    final parts = <String>[
      if (badge != null && badge!.trim().isNotEmpty) badge!.trim(),
      if (subtitle != null && subtitle!.trim().isNotEmpty) subtitle!.trim(),
    ];
    return parts.isEmpty ? null : parts.join(' • ');
  }

  factory GlobalSearchItem.fromJson(Map<String, dynamic> json) {
    return GlobalSearchItem(
      id: _asInt(json['id']) ?? 0,
      title: _asText(json['title']) ?? '',
      subtitle: _asText(json['subtitle']),
      badge: _asText(json['badge']),
      amount: _asDouble(json['amount']),
      subscriberId: _asInt(json['subscriber_id']),
    );
  }
}

int? _asInt(dynamic value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  return int.tryParse('${value ?? ''}');
}

double? _asDouble(dynamic value) {
  if (value is num) return value.toDouble();
  return double.tryParse('${value ?? ''}');
}

String? _asText(dynamic value) {
  final text = value?.toString().trim();
  return (text == null || text.isEmpty) ? null : text;
}
