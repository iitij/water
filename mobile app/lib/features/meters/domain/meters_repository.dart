import '../data/models/meter.dart';

abstract interface class MetersRepository {
  Future<List<Meter>> getMeters({
    String query = '',
    int? squareId,
    String? status,
  });

  Future<List<SquareOption>> getSquares();
  Future<List<SubscriberOption>> getSubscribers();
  Future<SubscriberOption> getSubscriber(int subscriberId);

  Future<Meter> saveMeter(MeterDraft draft, {int? meterId});
  Future<Meter> replaceMeter({
    required int meterId,
    required String uniqueCode,
    required String reason,
    required String removedAt,
    required double initialReading,
    int? pathNumber,
  });
  Future<void> deleteMeter(int meterId);
  Future<({int deletedCount, int skippedCount})> bulkDeleteMeters(List<int> meterIds);

  Future<bool> isPathNumberAvailable({
    required int pathNumber,
    required int squareId,
    int? ignoreMeterId,
  });

  Future<int> meterCountForSubscriber(int subscriberId);
}
