import 'package:equatable/equatable.dart';

/// محطة واحدة في مسار القراءة الميداني.
///
/// الترتيب مقصود: رقم المسار أولاً (هو ما يطبعه المشرف على ورقة الجولة)، ثم
/// كود العداد كفاصل نهائي حتى لا يهتز الترتيب بين تنزيلين.
class RouteStop extends Equatable {
  const RouteStop({
    required this.meterId,
    required this.meterCode,
    required this.subscriberName,
    this.subscriberId,
    this.pathNumber,
    this.lastReading,
    this.isBlocked = false,
  });

  final int meterId;
  final String meterCode;
  final String? subscriberName;
  final int? subscriberId;

  /// رقم المسار المعتمد في المربع (قد يكون فارغاً لعداد جديد).
  final int? pathNumber;

  /// آخر قراءة مسجّلة على الخادم — تظهر للأسرع في التحقق البصري.
  final double? lastReading;

  /// عداد غير صالح للقراءة الآن (موقوف/مفصول).
  final bool isBlocked;

  /// رقم يُعرض للمحصّل: المسار إن وُجد وإلا كود العداد.
  String get displayNumber => pathNumber?.toString() ?? meterCode;

  @override
  List<Object?> get props =>
      <Object?>[meterId, meterCode, subscriberName, subscriberId, pathNumber, lastReading, isBlocked];
}

/// خطة مسار مربع واحد: قائمة مرتّبة + تقدّم + المحطة التالية.
///
/// كل الحسابات هنا نقية (بلا شبكة ولا حالة واجهة) لتُختبر وحدها، وتُستخدم
/// نفسها في الشاشة وفي تقرير التقدّم.
class RoutePlan extends Equatable {
  const RoutePlan({
    required this.squareId,
    required this.squareName,
    required this.stops,
    required this.visitedMeterIds,
  });

  final int? squareId;
  final String? squareName;

  /// المحطات **مرتّبة** تصاعدياً بالمسار ثم كود العداد.
  final List<RouteStop> stops;

  /// عدادات أُنجزت في هذه الجولة.
  final Set<int> visitedMeterIds;

  static const RoutePlan empty = RoutePlan(
    squareId: null,
    squareName: null,
    stops: <RouteStop>[],
    visitedMeterIds: <int>{},
  );

  /// يبني خطة من عدادات الخادم + العدادات المنجزة، مع ترتيب ثابت.
  factory RoutePlan.fromStops({
    int? squareId,
    String? squareName,
    required List<RouteStop> stops,
    Set<int> visitedMeterIds = const <int>{},
  }) {
    final ordered = List<RouteStop>.from(stops)
      ..sort((a, b) {
        final byPath = (a.pathNumber ?? 1 << 30).compareTo(b.pathNumber ?? 1 << 30);
        if (byPath != 0) return byPath;
        return a.meterCode.compareTo(b.meterCode);
      });
    return RoutePlan(
      squareId: squareId,
      squareName: squareName,
      stops: List<RouteStop>.unmodifiable(ordered),
      visitedMeterIds: Set<int>.unmodifiable(visitedMeterIds),
    );
  }

  bool isVisited(RouteStop stop) => visitedMeterIds.contains(stop.meterId);

  /// المحطات القابلة للزيارة (بلا العدادات الموقوفة).
  List<RouteStop> get actionableStops =>
      stops.where((stop) => !stop.isBlocked).toList(growable: false);

  List<RouteStop> get remainingStops => actionableStops
      .where((stop) => !visitedMeterIds.contains(stop.meterId))
      .toList(growable: false);

  int get total => actionableStops.length;

  int get completed =>
      actionableStops.where((stop) => visitedMeterIds.contains(stop.meterId)).length;

  int get remaining => total - completed;

  int get blockedCount => stops.where((stop) => stop.isBlocked).length;

  /// نسبة الإنجاز 0..1 (خطة فارغة = 1 لأن لا شيء متبقٍ).
  double get progress {
    if (total == 0) return 1;
    return (completed / total).clamp(0.0, 1.0);
  }

  bool get isComplete => remaining == 0;

  /// المحطة التالية: أول محطة غير منجزة بالترتيب المعتمد.
  RouteStop? get nextStop {
    for (final stop in stops) {
      if (stop.isBlocked) continue;
      if (!visitedMeterIds.contains(stop.meterId)) return stop;
    }
    return null;
  }

  /// نسخة بعد تعليم عداد كمُنجز (idempotent).
  RoutePlan markVisited(int meterId) => RoutePlan(
        squareId: squareId,
        squareName: squareName,
        stops: stops,
        visitedMeterIds: Set<int>.unmodifiable(
          <int>{...visitedMeterIds, meterId},
        ),
      );

  /// نسخة بعد التراجع عن تعليم عداد (يُستخدم عند تصحيح ضغطة خاطئة).
  RoutePlan unmarkVisited(int meterId) => RoutePlan(
        squareId: squareId,
        squareName: squareName,
        stops: stops,
        visitedMeterIds: Set<int>.unmodifiable(
          visitedMeterIds.where((id) => id != meterId).toSet(),
        ),
      );

  /// متوسط الزمن لكل محطة منجزة (ثوانٍ) — أساس مقياس S3-06.
  double? averageSecondsPerStop(Duration elapsed) {
    if (completed == 0) return null;
    return elapsed.inSeconds / completed;
  }

  @override
  List<Object?> get props => <Object?>[squareId, squareName, stops, visitedMeterIds];
}
