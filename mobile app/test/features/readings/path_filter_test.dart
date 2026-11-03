import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/features/meters/domain/meters_repository.dart';
import 'package:water_project/features/readings/data/models/reading.dart';
import 'package:water_project/features/readings/data/reading_filter_preset_store.dart';
import 'package:water_project/features/readings/domain/reading_filter_preset.dart';
import 'package:water_project/features/readings/domain/readings_repository.dart';
import 'package:water_project/features/readings/presentation/cubit/readings_cubit.dart';

class _Meters extends Mock implements MetersRepository {}

class _Readings extends Mock implements ReadingsRepository {}

void main() {
  late ReadingsCubit cubit;
  late _Readings readings;
  final date = DateTime(2026, 11, 3);
  Reading row(int id, int? path) => Reading(
    id: id,
    meterId: id,
    pathNumber: path,
    readingDate: date,
    periodStart: date,
    periodEnd: date,
    previousReading: 0,
    currentReading: 10,
    consumptionUnits: 10,
  );
  final rows = [row(1, 1), row(2, 10), row(3, null)];
  setUp(() {
    readings = _Readings();
    final meters = _Meters();
    when(() => meters.getSquares()).thenAnswer((_) async => []);
    when(
      () => readings.getReadings(
        isInvoiced: any(named: 'isInvoiced'),
        squareId: any(named: 'squareId'),
        fromDate: any(named: 'fromDate'),
        toDate: any(named: 'toDate'),
        query: any(named: 'query'),
      ),
    ).thenAnswer((_) async => rows);
    cubit = ReadingsCubit(
      readingsRepository: readings,
      metersRepository: meters,
      presetStore: InMemoryReadingFilterPresetStore(),
    );
    addTearDown(cubit.close);
  });

  test(
    'path filter matches exactly and survives changing other facets',
    () async {
      var loaded = cubit.stream
          .skipWhile((s) => s.status != ReadingsLoadStatus.loading)
          .firstWhere((s) => s.status == ReadingsLoadStatus.success);
      cubit.applyFilters(
        squareId: 4,
        fromDate: date,
        toDate: date,
        pathNumber: 1,
      );
      await loaded;
      expect(cubit.state.readings.map((r) => r.id), [1]);
      loaded = cubit.stream
          .skipWhile((s) => s.status != ReadingsLoadStatus.loading)
          .firstWhere((s) => s.status == ReadingsLoadStatus.success);
      cubit.applyFilters(squareId: null, fromDate: date, toDate: date);
      await loaded;
      expect(cubit.state.pathFilter, 1);
      expect(cubit.state.readings.map((r) => r.id), [1]);
      loaded = cubit.stream
          .skipWhile((s) => s.status != ReadingsLoadStatus.loading)
          .firstWhere((s) => s.status == ReadingsLoadStatus.success);
      cubit.clearAllFilters();
      await loaded;
      expect(cubit.state.pathFilter, isNull);
      expect(cubit.state.readings, rows);
    },
  );

  test(
    'saved route filters round-trip and legacy filters clear a prior route',
    () async {
      var loaded = cubit.stream
          .skipWhile((s) => s.status != ReadingsLoadStatus.loading)
          .firstWhere((s) => s.status == ReadingsLoadStatus.success);
      cubit.setPathFilter(1);
      await loaded;
      expect(await cubit.savePreset('مسار واحد'), isTrue);
      final saved = ReadingFilterPreset.fromJson(
        cubit.state.presets.single.toJson(),
      );
      expect(saved.pathNumber, 1);
      expect(saved.copyWith(name: 'آخر').pathNumber, 1);
      expect(saved.facetCount, 1);
      expect(saved.isEmpty, isFalse);
      loaded = cubit.stream
          .skipWhile((s) => s.status != ReadingsLoadStatus.loading)
          .firstWhere((s) => s.status == ReadingsLoadStatus.success);
      await cubit.applyPreset(
        ReadingFilterPreset.fromJson({'id': 'old', 'name': 'قديم'}),
      );
      await loaded;
      expect(cubit.state.pathFilter, isNull);
      expect(cubit.state.readings, rows);
      loaded = cubit.stream
          .skipWhile((s) => s.status != ReadingsLoadStatus.loading)
          .firstWhere((s) => s.status == ReadingsLoadStatus.success);
      await cubit.applyPreset(saved);
      await loaded;
      expect(cubit.state.readings.map((r) => r.id), [1]);
    },
  );
}
