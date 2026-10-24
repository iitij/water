import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:water_project/features/field_route/data/route_visit_store.dart';
import 'package:water_project/features/field_route/domain/route_plan.dart';

/// منطق وضع المسار (S3-01/S3-06): ترتيب ثابت، تقدّم صحيح، ومحطة تالية واحدة.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  RouteStop stop(int meterId, {int? path, String? code, bool blocked = false}) => RouteStop(
        meterId: meterId,
        meterCode: code ?? 'MTR-$meterId',
        subscriberName: 'مشترك $meterId',
        pathNumber: path,
        lastReading: 100,
        isBlocked: blocked,
      );

  group('RoutePlan', () {
    test('يرتّب المحطات برقم المسار ثم بكود العداد', () {
      final plan = RoutePlan.fromStops(
        squareId: 3,
        stops: <RouteStop>[
          stop(1, path: 5, code: 'B'),
          stop(2, path: 2, code: 'Z'),
          stop(3, path: 2, code: 'A'),
          stop(4, path: null, code: '0-NOPATH'),
        ],
      );

      expect(plan.stops.map((s) => s.meterId), <int>[3, 2, 1, 4]);
    });

    test('العداد الموقوف لا يُحتسب في الإجمالي ولا يصبح «التالي»', () {
      final plan = RoutePlan.fromStops(
        stops: <RouteStop>[
          stop(1, path: 1, blocked: true),
          stop(2, path: 2),
          stop(3, path: 3),
        ],
      );

      expect(plan.total, 2);
      expect(plan.blockedCount, 1);
      expect(plan.nextStop?.meterId, 2);
      expect(plan.progress, 0);
    });

    test('التعليم يزيد التقدّم مرة واحدة فقط ويتجاهل التكرار', () {
      final plan = RoutePlan.fromStops(
        stops: <RouteStop>[stop(1, path: 1), stop(2, path: 2)],
      );

      final once = plan.markVisited(1);
      final twice = once.markVisited(1);

      expect(once.completed, 1);
      expect(twice.completed, 1);
      expect(twice.progress, 0.5);
      expect(twice.nextStop?.meterId, 2);
    });

    test('التراجع عن التعليم يعيد المحطة إلى «التالي»', () {
      final plan = RoutePlan.fromStops(
        stops: <RouteStop>[stop(1, path: 1), stop(2, path: 2)],
      ).markVisited(1).markVisited(2);

      expect(plan.isComplete, isTrue);
      expect(plan.nextStop, isNull);

      final undone = plan.unmarkVisited(1);
      expect(undone.completed, 1);
      expect(undone.nextStop?.meterId, 1);
    });

    test('الخطة الفارغة مكتملة ولا تُظهر محطة تالية', () {
      expect(RoutePlan.empty.total, 0);
      expect(RoutePlan.empty.progress, 1);
      expect(RoutePlan.empty.isComplete, isTrue);
      expect(RoutePlan.empty.nextStop, isNull);
    });

    test('متوسط الزمن لكل محطة يُحسب من المدة المنقضية', () {
      final plan = RoutePlan.fromStops(
        stops: <RouteStop>[stop(1, path: 1), stop(2, path: 2)],
      ).markVisited(1).markVisited(2);

      expect(plan.averageSecondsPerStop(const Duration(minutes: 4)), 120);
      expect(RoutePlan.empty.averageSecondsPerStop(const Duration(minutes: 4)), isNull);
    });
  });

  group('RouteVisitStore', () {
    setUp(() => SharedPreferences.setMockInitialValues(<String, Object>{}));

    test('يحفظ ويقرأ تعليم اليوم لنفس المربع فقط', () async {
      final store = SharedPreferencesRouteVisitStore();
      final day = DateTime(2026, 10, 24, 14);

      await store.save(squareId: 7, day: day, meterIds: <int>{1, 2});

      expect(await store.visited(squareId: 7, day: day), <int>{1, 2});
      expect(await store.visited(squareId: 8, day: day), isEmpty);
      expect(await store.visited(squareId: 7, day: DateTime(2026, 10, 25)), isEmpty,
          reason: 'جولة الغد تبدأ فارغة');
    });

    test('المفتاح يحمل التاريخ بصيغة ثابتة', () {
      expect(
        SharedPreferencesRouteVisitStore.keyFor(7, DateTime(2026, 1, 5)),
        'route.visits.2026-01-05.7',
      );
    });

    test('المخزن في الذاكرة يعمل بنفس العقد', () async {
      final store = InMemoryRouteVisitStore();
      final day = DateTime(2026, 10, 24);

      await store.save(squareId: 1, day: day, meterIds: <int>{5});
      expect(await store.visited(squareId: 1, day: day), <int>{5});
    });
  });
}
