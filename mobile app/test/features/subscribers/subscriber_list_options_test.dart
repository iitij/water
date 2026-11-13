import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/l10n/list_options_strings.dart';
import 'package:water_project/core/l10n/subscriber_experience_strings.dart';
import 'package:water_project/core/ui/list_presentation_controls.dart';
import 'package:water_project/features/service_requests/domain/repositories/service_requests_repository.dart';
import 'package:water_project/features/service_requests/presentation/cubit/service_requests_cubit.dart';
import 'package:water_project/features/subscribers/data/models/subscriber_model.dart';
import 'package:water_project/features/subscribers/domain/entities/subscriber.dart';
import 'package:water_project/features/subscribers/domain/repositories/subscribers_repository.dart';
import 'package:water_project/features/subscribers/presentation/cubit/subscribers_cubit.dart';
import 'package:water_project/features/subscribers/presentation/pages/subscribers_screen.dart';

class _Subscribers extends Mock implements SubscribersRepository {}

class _Requests extends Mock implements ServiceRequestsRepository {}

void main() {
  test('parses all paths numerically and preserves actual addition time', () {
    final subscriber = SubscriberModel.fromJson({
      'id': 1,
      'name': 'مشترك',
      'path_numbers': ['10', 2, 10, null],
      'created_at': '2026-11-03T09:15:00.000000Z',
      'subscription_start_date': '2020-01-01',
    });
    expect(subscriber.pathNumbers, [2, 10]);
    expect(subscriber.firstPathNumber, 2);
    expect(subscriber.createdAt, DateTime.utc(2026, 11, 3, 9, 15));
    expect(SubscriberModel.fromJson({'id': 2}).firstPathNumber, isNull);
    expect(SubscriberModel.fromJson({'id': 2}).createdAt, isNull);
    expect(
      SubscriberModel.fromJson({
        'id': 2,
        'meters': [
          {'path_number': 10},
          {'path_number': 2},
          {'path_number': null},
        ],
      }).pathNumbers,
      [2, 10],
    );
  });

  test('subscriber meter count excludes historical meters even without API count', () {
    final replaced = SubscriberModel.fromJson({
      'id': 8,
      'name': 'مشترك',
      'meters': [
        {'id': 11, 'path_number': 7, 'removed_at': '2026-10-01'},
        {'id': 12, 'path_number': 3, 'removed_at': null},
      ],
    });
    expect(replaced.metersCount, 1);
    expect(replaced.pathNumbers, [3]);

    final historicalOnly = SubscriberModel.fromJson({
      'id': 9,
      'name': 'بدون عداد حالي',
      'meters': [
        {'id': 11, 'path_number': 7, 'removed_at': '2026-10-01'},
      ],
    });
    expect(historicalOnly.metersCount, 0);
    expect(historicalOnly.pathNumbers, isEmpty);

    final staleCount = SubscriberModel.fromJson({
      'id': 10,
      'name': 'سجل قديم',
      'meters_count': 3,
    });
    expect(staleCount.metersCount, 1);
  });

  test('operational paths omit retired meters while preserving active path', () {
    final subscriber = SubscriberModel.fromJson({
      'id': 6,
      'name': 'مشترك',
      'meters_count': 1,
      'meters': [
        {'id': 1, 'path_number': 9, 'removed_at': '2026-09-01'},
        {'id': 2, 'path_number': 4, 'removed_at': null},
      ],
    });
    expect(subscriber.metersCount, 1);
    expect(subscriber.pathNumbers, [4]);
  });

  test(
    'path filter matches any meter, combines facets, and resets once',
    () async {
      final repo = _Subscribers();
      when(
        () => repo.getSubscribers(
          query: any(named: 'query'),
          squareId: any(named: 'squareId'),
          status: any(named: 'status'),
        ),
      ).thenAnswer(
        (_) async => const [
          Subscriber(id: 1, name: 'مساران', pathNumbers: [2, 10]),
          Subscriber(id: 2, name: 'مسار آخر', pathNumbers: [3]),
          Subscriber(id: 3, name: 'بلا مسار'),
        ],
      );
      final cubit = SubscribersCubit(repo);
      addTearDown(cubit.close);
      cubit.applyFilters(
        squareId: 7,
        status: SubscriberStatus.active,
        pathNumber: 10,
      );
      await Future<void>.delayed(Duration.zero);
      expect(cubit.state.subscribers.map((s) => s.id), [1]);
      verify(
        () => repo.getSubscribers(
          query: '',
          squareId: 7,
          status: SubscriberStatus.active,
        ),
      ).called(1);
      cubit.clearFilters(clearQuery: true);
      await Future<void>.delayed(Duration.zero);
      expect(cubit.state.pathNumber, isNull);
      expect(cubit.state.subscribers, hasLength(3));
      verify(
        () => repo.getSubscribers(query: '', squareId: null, status: null),
      ).called(1);
    },
  );

  test(
    'normalizes route digits and ignores slower previous searches',
    () async {
      final repo = _Subscribers();
      final old = Completer<List<Subscriber>>();
      final current = Completer<List<Subscriber>>();
      when(
        () => repo.getSubscribers(query: '4/1', squareId: null, status: null),
      ).thenAnswer((_) => old.future);
      when(
        () => repo.getSubscribers(query: '12', squareId: null, status: null),
      ).thenAnswer((_) => current.future);
      final cubit = SubscribersCubit(repo);
      addTearDown(cubit.close);
      cubit.search('٤/١');
      cubit.search('۱۲');
      current.complete([const Subscriber(id: 2, name: 'current')]);
      await Future<void>.delayed(Duration.zero);
      old.complete([const Subscriber(id: 1, name: 'old')]);
      await Future<void>.delayed(Duration.zero);
      expect(cubit.state.subscribers.single.id, 2);
      expect(cubit.state.query, '۱۲');
    },
  );

  for (final width in [390.0, 1280.0]) {
    testWidgets('subscriber sort controls work at width $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 1000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final repo = _Subscribers();
      when(
        () => repo.getSubscribers(query: '', squareId: null, status: null),
      ).thenAnswer(
        (_) async => [
          Subscriber(
            id: 20,
            name: 'أول',
            pathNumbers: const [10],
            createdAt: DateTime.utc(2026, 1, 1),
          ),
          Subscriber(
            id: 10,
            name: 'ثان',
            pathNumbers: const [2],
            createdAt: DateTime.utc(2026, 2, 1),
          ),
          const Subscriber(id: 30, name: 'ثالث'),
        ],
      );
      final cubit = SubscribersCubit(repo);
      final requests = ServiceRequestsCubit(_Requests());
      addTearDown(cubit.close);
      addTearDown(requests.close);
      await cubit.load();
      await tester.pumpWidget(
        MaterialApp(
          home: MultiBlocProvider(
            providers: [
              BlocProvider.value(value: cubit),
              BlocProvider.value(value: requests),
            ],
            child: const SubscribersScreen(),
          ),
        ),
      );
      await tester.pumpAndSettle();
      final presentation = tester.widget<ListPresentation<Subscriber>>(
        find.byType(ListPresentation<Subscriber>),
      );
      Future<void> sort(String field, {bool descending = false}) async {
        final controls = tester.widget<ListPresentationControls>(
          find.byType(ListPresentationControls),
        );
        controls.onChanged(
          controls.settings.copyWith(sortBy: field, descending: descending),
        );
        await tester.pumpAndSettle();
      }

      List<int> visibleIds() {
        final list = tester.widget<ListView>(
          find
              .descendant(
                of: find.byType(ListPresentation<Subscriber>),
                matching: find.byType(ListView),
              )
              .first,
        );
        final builder = list.childrenDelegate as SliverChildBuilderDelegate;
        return List.generate(builder.childCount!, (index) {
          final dynamic card = builder.builder(
            tester.element(find.byType(ListView).first),
            index,
          );
          // ignore: avoid_dynamic_calls
          return (card.subscriber as Subscriber).id;
        });
      }

      expect(
        presentation.sortValues['path_number']!.label,
        SubscriberExperienceStrings.path,
      );
      await sort('path_number');
      expect(visibleIds(), [10, 20, 30]);
      await sort('path_number', descending: true);
      expect(visibleIds(), [20, 10, 30]);
      await sort('created_at');
      expect(visibleIds(), [20, 10, 30]);
      await sort('addition_order', descending: true);
      expect(visibleIds(), [30, 20, 10]);
      final filtered = tester.widget<ListPresentationControls>(
        find.byType(ListPresentationControls),
      );
      filtered.onChanged(filtered.settings.copyWith(pathNumber: 2));
      await tester.pumpAndSettle();
      cubit.selectAllVisible();
      expect(
        cubit.state.selectedIds,
        {10},
        reason: 'Bulk selection must exclude hidden routes',
      );
      cubit.clearSelection();
      await tester.tap(find.byTooltip(ListOptionsStrings.title));
      await tester.pumpAndSettle();
      expect(find.text(ListOptionsStrings.sortBy), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
