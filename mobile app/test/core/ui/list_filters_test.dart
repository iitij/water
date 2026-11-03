import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/l10n/list_options_strings.dart';
import 'package:water_project/core/ui/list_presentation_controls.dart';

typedef _Row = ({
  int id,
  int square,
  String name,
  List<int> paths,
  DateTime? added,
});

void main() {
  final rows = <_Row>[
    (
      id: 1,
      square: 4,
      name: 'أحمد',
      paths: [1, 10],
      added: DateTime(2026, 11, 2, 23, 59),
    ),
    (
      id: 2,
      square: 4,
      name: 'سالم',
      paths: [10],
      added: DateTime(2026, 11, 3, 23, 59),
    ),
    (id: 3, square: 5, name: 'علي', paths: [1], added: DateTime(2026, 11, 4)),
    (id: 4, square: 4, name: 'بلا تاريخ', paths: [], added: null),
  ];
  Future<void> mount(
    WidgetTester tester,
    ListPresentationSettings settings,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ListPresentation<_Row>(
            items: rows,
            initialSettings: settings,
            pathNumbers: (row) => row.paths,
            sortValues: {
              'id': (label: ListOptionsStrings.id, value: (row) => row.id),
              'name': (
                label: ListOptionsStrings.name,
                value: (row) => row.name,
              ),
              'path_number': (
                label: ListOptionsStrings.path,
                value: (row) => row.paths.firstOrNull,
              ),
              'square_id': (
                label: ListOptionsStrings.square,
                value: (row) => row.square,
              ),
              'square_name': (
                label: ListOptionsStrings.square,
                value: (row) => 'مربع ${row.square}',
              ),
              'created_at': (
                label: ListOptionsStrings.createdAt,
                value: (row) => row.added,
              ),
            },
            builder: (context, visible, options) => ListView(
              children: [for (final row in visible) Text('row:${row.id}')],
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets(
    'combines exact secondary route, square and inclusive addition dates',
    (tester) async {
      await mount(
        tester,
        ListPresentationSettings(
          pathNumber: 10,
          squareName: 'مربع 4',
          createdFrom: DateTime(2026, 11, 2),
          createdTo: DateTime(2026, 11, 3),
        ),
      );
      expect(find.text('row:1'), findsOneWidget);
      expect(find.text('row:2'), findsOneWidget);
      expect(find.text('row:3'), findsNothing);
      expect(find.text('row:4'), findsNothing);
      final controls = tester.widget<ListPresentationControls>(
        find.byType(ListPresentationControls),
      );
      controls.onChanged(controls.settings.copyWith(clearFilters: true));
      await tester.pumpAndSettle();
      expect(find.text('row:4'), findsOneWidget);
      expect(
        tester
            .widget<ListPresentationControls>(
              find.byType(ListPresentationControls),
            )
            .settings
            .filterCount,
        0,
      );
    },
  );

  testWidgets(
    'compound route search accepts Arabic digits and isolates the square',
    (tester) async {
      await mount(tester, const ListPresentationSettings(query: '٤/١'));
      expect(find.text('row:1'), findsOneWidget);
      expect(find.text('row:2'), findsNothing);
      expect(find.text('row:3'), findsNothing);
    },
  );

  testWidgets('zero is a valid exact route while unassigned routes remain distinct', (tester) async {
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: ListPresentation<int>(
      items: const [0, 1], initialSettings: const ListPresentationSettings(pathNumber: 0),
      sortValues: {'path_number': (label: ListOptionsStrings.path, value: (value) => value)},
      builder: (context, visible, options) => ListView(children: [for (final value in visible) Text('path:$value')]),
    ))));
    await tester.pumpAndSettle();
    expect(find.text('path:0'), findsOneWidget);
    expect(find.text('path:1'), findsNothing);
  });

  for (final width in [320.0, 1280.0]) {
    testWidgets('shared filter sheet validates Arabic paths at width $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await mount(tester, const ListPresentationSettings());
      await tester.tap(find.byTooltip(ListOptionsStrings.title));
      await tester.pumpAndSettle();
      final path = find.byWidgetPredicate(
        (widget) =>
            widget is TextField &&
            widget.decoration?.labelText == ListOptionsStrings.path,
      );
      await tester.enterText(path, '-1');
      await tester.pump();
      expect(find.text(ListOptionsStrings.invalidPath), findsOneWidget);
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, ListOptionsStrings.apply),
            )
            .onPressed,
        isNull,
      );
      await tester.enterText(path, '١٠');
      await tester.pump();
      await tester.ensureVisible(find.text(ListOptionsStrings.apply));
      await tester.tap(find.text(ListOptionsStrings.apply));
      await tester.pumpAndSettle();
      expect(find.text('row:1'), findsOneWidget);
      expect(find.text('row:2'), findsOneWidget);
      expect(find.text('row:3'), findsNothing);
      expect(tester.takeException(), isNull);
    });
  }
}
