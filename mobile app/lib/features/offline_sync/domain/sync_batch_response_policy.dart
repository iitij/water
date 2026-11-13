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
