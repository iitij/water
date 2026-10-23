import '../../meters/data/models/meter.dart';
import '../data/models/reading.dart';

abstract interface class ReadingsRepository {
  Future<MeterPeriodResponse> getMeterPeriod(Meter meter);

  Future<ReadingSaveResult> submitReading(ReadingSubmission submission);

  Future<List<Reading>> getReadings({
    bool? isInvoiced,
    int? squareId,
    int? meterId,
    DateTime? fromDate,
    DateTime? toDate,
    String? query,
  });

  Future<Reading> updateReading(
    Reading reading,
    double newCurrentReading, {
    DateTime? newReadingDate,
  });
  Future<void> deleteReading(Reading reading);
  Future<BulkOperationResult> bulkDeleteReadings(List<Reading> readings);
  Future<BulkOperationResult> bulkIssueInvoices(List<Reading> readings);
}
