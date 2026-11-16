import 'dart:convert';

import 'package:drift/drift.dart';

/// Upgrade-only scrubber: never discard legitimate queued field readings.
/// Delete only obsolete billing fields while retaining UUID, ownership and payload.
Future<void> purgeRetiredOfflineFields(GeneratedDatabase db) async {
  final rows = await db.customSelect(
    'SELECT id, payload_json FROM pending_sync_actions',
  ).get();
  for (final row in rows) {
    final id = row.read<String>('id');
    final raw = row.read<String>('payload_json');
    Object? value;
    try {
      value = jsonDecode(raw);
    } on FormatException {
      continue; // Preserve malformed actions for explicit sync diagnostics.
    }
    if (value is! Map<String, dynamic>) continue;
    final sanitized = _withoutRetiredCharges(value);
    if (jsonEncode(sanitized) != jsonEncode(value)) {
      await db.customStatement(
        'UPDATE pending_sync_actions SET payload_json = ? WHERE id = ?',
        [jsonEncode(sanitized), id],
      );
    }
  }
  // Early database versions did not create the settings cache table.
  final settingsTable = await db.customSelect(
    "SELECT name FROM sqlite_master "
    "WHERE type = 'table' AND name = 'cached_settings'",
  ).get();
  if (settingsTable.isNotEmpty) {
    await db.customStatement(
      "DELETE FROM cached_settings WHERE "
      "setting_key LIKE '%tanker%' OR setting_key LIKE '%tenant%' "
      "OR setting_key LIKE '%subscription_fee%' "
      "OR setting_key LIKE 'sub_inv_%' OR setting_key LIKE 'sub_show_%' "
      "OR setting_key LIKE 'sub_lbl_%' "
      "OR setting_key IN ('subscription_invoice_template', 'whatsapp_subscription_message') "
      "OR setting_value LIKE '%tanker_fee%' "
      "OR setting_value LIKE '%subscription_fee%' OR setting_value LIKE '%رسوم الاشتراك%'",
    );
  }
}

const _retiredChargeFields = <String>{
  'has_tanker_fee',
  'tanker_installment',
  'has_subscription_fee',
  'subscription_installment',
};

/// Traverse batch requests too: nested reading payloads may contain old fee
/// flags, but reading values, UUIDs and reconciliation details must survive.
Object? _withoutRetiredCharges(Object? value) {
  if (value is Map) {
    return <String, dynamic>{
      for (final entry in value.entries)
        if (!_retiredChargeFields.contains(entry.key.toString()))
          entry.key.toString(): _withoutRetiredCharges(entry.value),
    };
  }
  if (value is List) return value.map(_withoutRetiredCharges).toList();
  return value;
}
