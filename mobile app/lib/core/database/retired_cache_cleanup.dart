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
    final data = Map<String, dynamic>.of(value);
    final oldLength = data.length;
    data.remove('has_tanker_fee');
    data.remove('tanker_installment');
    data.remove('has_subscription_fee');
    data.remove('subscription_installment');
    if (data.length != oldLength) {
      await db.customStatement(
        'UPDATE pending_sync_actions SET payload_json = ? WHERE id = ?',
        [jsonEncode(data), id],
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
