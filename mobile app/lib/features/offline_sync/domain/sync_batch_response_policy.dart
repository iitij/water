import '../../../core/accounting/exact_money.dart';

/// A per-item validation rejection in an HTTP 200 sync-batch response.
class SyncBatchItemRejected implements Exception {
  const SyncBatchItemRejected(this.message);

  final String message;
  int get statusCode => 422;

  @override
  String toString() => message;
}

/// A rejected meter reading is not a network error. The user must correct or
/// discard it, especially if the meter has since been removed/replaced.
///
/// Unexpected server failures remain retryable and are handled by the queue's
/// existing backoff policy.
void ensureSyncBatchItemAccepted(Map<String, dynamic>? item) {
  if (item == null) {
    throw StateError('استجابة مزامنة القراءة غير مكتملة.');
  }
  if (item['success'] == true) return;

  final message = item['message']?.toString() ?? 'رفض الخادم مزامنة القراءة.';
  if (item['retryable'] == true) {
    throw StateError(message);
  }
  throw SyncBatchItemRejected(message);
}


/// A successful HTTP status is not proof of an issued receipt. The current
/// Laravel payment endpoints return the persisted payment with these fields.
/// A late approved review may legitimately have no original cash_session_id;
/// the server's exact replay contract checks that separately.
void ensurePaymentAcknowledged(Object? response, Map<String, dynamic> request,
    {required String ownerUserId}) {
  Never invalid() => throw const SyncBatchItemRejected(
      'لم يؤكد الخادم هوية سند القبض ومبلغه؛ احتفظ بالعملية للمراجعة.');
  if (response is! Map || response['payment'] is! Map) invalid();
  final payment = response['payment'] as Map;
  int? id(Object? value) {
    if (value is! int && value is! String) return null;
    final text = value.toString();
    if (!RegExp(r'^[1-9][0-9]*$').hasMatch(text)) return null;
    return int.tryParse(text);
  }

  if (id(payment['id']) == null ||
      payment['receipt_number'] is! String ||
      (payment['receipt_number'] as String).trim().isEmpty ||
      (payment['receipt_number'] as String).startsWith('REC-LOCAL-') ||
      payment['sync_uuid'] != request['sync_uuid'] ||
      id(request['subscriber_id']) == null ||
      id(payment['subscriber_id']) != id(request['subscriber_id']) ||
      id(payment['user_id']) == null ||
      id(payment['user_id']) != id(ownerUserId) ||
      (request['invoice_id'] == null
          ? payment['invoice_id'] != null
          : id(request['invoice_id']) == null ||
              id(payment['invoice_id']) != id(request['invoice_id'])) ||
      payment['payment_method'] != request['payment_method']) invalid();
  try {
    final sent = ExactMoney.parse(request['amount'].toString());
    final received = ExactMoney.parse(payment['amount'].toString());
    if (sent.minor <= BigInt.zero || sent.minor != received.minor) invalid();
  } on FormatException {
    invalid();
  } on RangeError {
    invalid();
  }
}
