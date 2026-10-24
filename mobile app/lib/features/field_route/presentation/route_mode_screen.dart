import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../core/error/failures.dart';
import '../../../core/finance/finance_feedback.dart';
import '../../../core/finance/finance_locator.dart';
import '../../../core/router/route_names.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/ui/app_surfaces.dart';
import '../../../core/ui/app_tokens.dart';
import '../../meters/data/models/meter.dart';
import '../../meters/domain/meters_repository.dart';
import '../../readings/domain/readings_repository.dart';
import '../data/route_visit_store.dart';
import '../domain/route_plan.dart';

/// وضع المسار (بطاقة S3-01 + قياس الزمن S3-06).
///
/// الفكرة: المحصّل لا يبحث في قائمة مشتركين طويلة؛ يفتح مربعه فيرى **ترتيب
/// الجولة** (رقم المسار تصاعدياً)، وتقدّمه، ومن المحطة التالية. التعليم يُحفظ
/// محلياً فوراً حتى لو انقطعت الشبكة في منتصف الجولة.
class RouteModeScreen extends StatefulWidget {
  const RouteModeScreen({super.key, this.visitStore});

  /// حقن المخزن في الاختبارات؛ الافتراضي تخزين SharedPreferences.
  final RouteVisitStore? visitStore;

  @override
  State<RouteModeScreen> createState() => _RouteModeScreenState();
}

class _RouteModeScreenState extends State<RouteModeScreen> {
  late final RouteVisitStore _visits =
      widget.visitStore ?? SharedPreferencesRouteVisitStore();

  final Stopwatch _session = Stopwatch()..start();

  MetersRepository get _meters => sl<MetersRepository>();
  ReadingsRepository get _readings => sl<ReadingsRepository>();

  List<SquareOption> _squares = const <SquareOption>[];
  SquareOption? _selectedSquare;
  RoutePlan _plan = RoutePlan.empty;

  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    unawaited(_bootstrap());
  }

  @override
  void dispose() {
    _session.stop();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final squares = await _meters.getSquares();
      if (!mounted) return;
      _squares = squares;
      // نُعيد ربط الاختيار بنسخة القائمة الجديدة (المقارنة بالمعرّف لا بالهوية)
      // حتى لا يفشل `DropdownButton` بعد تحديث القائمة.
      SquareOption? target;
      if (squares.isNotEmpty) {
        final previousId = _selectedSquare?.id;
        target = squares.firstWhere(
          (square) => square.id == previousId,
          orElse: () => squares.first,
        );
      }
      await _loadPlan(target);
    } on AppFailure catch (failure) {
      if (mounted) setState(() => _error = failure.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _loadPlan(SquareOption? square) async {
    if (square == null) {
      if (mounted) {
        setState(() {
          _selectedSquare = null;
          _plan = RoutePlan.empty;
        });
      }
      return;
    }

    final meters = await _meters.getMeters(squareId: square.id);
    final visited = await _visits.visited(squareId: square.id, day: DateTime.now());
    final readToday = await _readMetersReadToday(square.id);
    final merged = <int>{...visited, ...readToday};
    if (merged.length != visited.length) {
      await _visits.save(squareId: square.id, day: DateTime.now(), meterIds: merged);
    }

    final stops = meters
        .map(
          (meter) => RouteStop(
            meterId: meter.id,
            meterCode: meter.uniqueCode,
            subscriberName: meter.subscriberName,
            subscriberId: meter.subscriberId,
            pathNumber: meter.pathNumber,
            lastReading: meter.lastReading,
            isBlocked: meter.status.toLowerCase() != 'active',
          ),
        )
        .toList(growable: false);

    if (!mounted) return;
    setState(() {
      _selectedSquare = square;
      _plan = RoutePlan.fromStops(
        squareId: square.id,
        squareName: square.name,
        stops: stops,
        visitedMeterIds: merged,
      );
    });
  }

  /// القراءات المسجّلة اليوم على الخادم تُعتبر منجزة حتى لو غيّر المحصّل جهازه.
  Future<Set<int>> _readMetersReadToday(int squareId) async {
    try {
      final now = DateTime.now();
      final readings = await _readings.getReadings(
        squareId: squareId,
        fromDate: DateTime(now.year, now.month, now.day),
      );
      return readings
          .map((reading) => reading.meterId)
          .whereType<int>()
          .toSet();
    } catch (_) {
      // بلا شبكة: نعتمد على التعليم المحلي فقط.
      return <int>{};
    }
  }

  Future<void> _toggle(RouteStop stop) async {
    final square = _selectedSquare;
    if (square == null || stop.isBlocked) return;

    final updated = _plan.isVisited(stop)
        ? _plan.unmarkVisited(stop.meterId)
        : _plan.markVisited(stop.meterId);

    setState(() => _plan = updated);
    await _visits.save(
      squareId: square.id,
      day: DateTime.now(),
      meterIds: updated.visitedMeterIds,
    );

    if (!mounted) return;
    if (updated.isComplete) {
      AppFeedback.success(context, 'أكملت مسار ${square.name}. عمل ممتاز.');
    }
  }

  void _openReader(RouteStop stop) {
    AppFeedback.info(
      context,
      'ابحث في شاشة القراءة عن ${stop.pathNumber == null ? 'العداد ${stop.meterCode}' : 'المسار ${stop.pathNumber}'}'
      ' — ${stop.subscriberName ?? ''}',
    );
    context.push(RouteNames.fieldReader);
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: _bootstrap,
      child: AppPageBody(
        topPadding: 16,
        bottomPadding: 32,
        children: <Widget>[
          _routeHero(context),
          const Gap(AppSpacing.md),
          if (_squares.length > 1) ...<Widget>[
            _squareSelector(context),
            const Gap(AppSpacing.md),
          ],
          if (_error != null) ...<Widget>[
            AppCard(
              borderColor: AppColors.danger,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  const Icon(Icons.error_outline, color: AppColors.danger),
                  const Gap(8),
                  Expanded(child: Text(_error!)),
                ],
              ),
            ),
            const Gap(AppSpacing.md),
          ],
          if (_loading)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (_plan.stops.isEmpty)
            AppCard(child: Text(_emptyMessage()))
          else
            ..._plan.stops.map((stop) => _stopTile(context, stop)),
          if (_plan.stops.isNotEmpty) ...<Widget>[
            const Gap(AppSpacing.md),
            _timingCard(context),
          ],
        ],
      ),
    );
  }

  String _emptyMessage() {
    if (_squares.isEmpty) return 'لا مربعات مسندة إليك. راجع المشرف قبل بدء الجولة.';
    return 'لا عدادات مسجّلة في مربع ${_selectedSquare?.name ?? ''}.';
  }

  Widget _routeHero(BuildContext context) {
    final next = _plan.nextStop;
    final percent = (_plan.progress * 100).round();
    return AppHeroPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  'وضع المسار',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
              ),
              Text(
                '${_plan.completed}/${_plan.total}',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ],
          ),
          const Gap(8),
          ClipRRect(
            borderRadius: BorderRadius.circular(AppRadius.pill),
            child: LinearProgressIndicator(
              value: _plan.progress,
              minHeight: 8,
              backgroundColor: AppColors.border,
            ),
          ),
          const Gap(8),
          Text(
            _plan.isComplete && _plan.total > 0
                ? 'اكتمل المسار بنسبة $percent% — لا محطات متبقية.'
                : next == null
                    ? 'لا توجد محطة تالية.'
                    : 'المحطة التالية: ${next.displayNumber} — ${next.subscriberName ?? next.meterCode}'
                        '${_plan.remaining > 0 ? ' (متبقٍ ${_plan.remaining})' : ''}',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          if (_plan.blockedCount > 0) ...<Widget>[
            const Gap(4),
            Text(
              '${_plan.blockedCount} عداد موقوف لا يُحتسب في الجولة.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ],
      ),
    );
  }

  Widget _squareSelector(BuildContext context) {
    return AppCard(
      child: DropdownButtonHideUnderline(
        child: DropdownButton<SquareOption>(
          isExpanded: true,
          value: _selectedSquare,
          hint: const Text('اختر المربع'),
          items: _squares
              .map(
                (square) => DropdownMenuItem<SquareOption>(
                  value: square,
                  child: Text(square.name),
                ),
              )
              .toList(growable: false),
          onChanged: (square) async {
            if (square == null || square.id == _selectedSquare?.id) return;
            setState(() => _loading = true);
            await _loadPlan(square);
            if (mounted) setState(() => _loading = false);
          },
        ),
      ),
    );
  }

  Widget _stopTile(BuildContext context, RouteStop stop) {
    final visited = _plan.isVisited(stop);
    final isNext = _plan.nextStop?.meterId == stop.meterId;

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: AppCard(
        borderColor: isNext ? AppColors.primary : null,
        child: Row(
          children: <Widget>[
            SizedBox(
              width: 34,
              child: Text(
                stop.displayNumber,
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: stop.isBlocked ? AppColors.danger : null,
                    ),
              ),
            ),
            const Gap(8),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    stop.subscriberName ?? 'بدون اسم',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                          decoration: visited ? TextDecoration.lineThrough : null,
                        ),
                  ),
                  Text(
                    'العداد ${stop.meterCode}'
                    '${stop.lastReading == null ? '' : ' — آخر قراءة ${stop.lastReading!.toStringAsFixed(0)}'}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            IconButton(
              tooltip: 'فتح شاشة القراءة',
              onPressed: stop.isBlocked ? null : () => _openReader(stop),
              icon: const Icon(Icons.speed_rounded),
            ),
            IconButton(
              tooltip: visited ? 'إلغاء التعليم' : 'تعليم كمُنجز',
              onPressed: stop.isBlocked ? null : () => _toggle(stop),
              icon: Icon(
                visited ? Icons.check_circle_rounded : Icons.radio_button_unchecked,
                color: visited ? AppColors.success : null,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _timingCard(BuildContext context) {
    final average = _plan.averageSecondsPerStop(_session.elapsed);
    final elapsedMinutes = _session.elapsed.inMinutes;
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text('قياس الجولة', style: Theme.of(context).textTheme.titleMedium),
          const Gap(6),
          Text(
            'المدة: $elapsedMinutes دقيقة'
            '${average == null ? '' : ' — متوسط ${average.toStringAsFixed(0)} ثانية لكل محطة'}',
          ),
          const Gap(4),
          Text(
            'المعيار في الخطة: 50 قراءة في أقل من 8 دقائق لكل محصّل مدرَّب.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}
