import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/l10n/list_options_strings.dart';
import 'package:water_project/core/ui/list_presentation_controls.dart';

void main() {
  testWidgets('sorts the complete collection before paging and resets page on applying options', (tester) async {
    final records = List.generate(30, (i) => 30 - i);
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: ListPresentation<int>(
      items: records,
      initialSettings: const ListPresentationSettings(sortBy: 'number', descending: false, pageSize: 10),
      sortValues: {'number': (label: 'الرقم', value: (int value) => value)},
      fields: const {'detail': 'تفصيل إضافي'},
      builder: (context, rows, settings) => ListView(children: [
        for (final row in rows) Text('record:$row'),
        if (settings.isVisible('detail')) const Text('optional-detail'),
      ]),
    ))));
    expect(find.text('record:1'), findsOneWidget);
    expect(find.text('record:30'), findsNothing);
    await tester.tap(find.text(ListOptionsStrings.next));
    await tester.pump();
    expect(find.text('record:11'), findsOneWidget);
    await tester.tap(find.byTooltip(ListOptionsStrings.title));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(SwitchListTile, ListOptionsStrings.descending));
    await tester.tap(find.widgetWithText(CheckboxListTile, 'تفصيل إضافي'));
    await tester.ensureVisible(find.text(ListOptionsStrings.apply));
    await tester.tap(find.text(ListOptionsStrings.apply));
    await tester.pumpAndSettle();
    expect(find.text('record:30'), findsOneWidget);
    expect(find.text('record:11'), findsNothing);
    expect(find.text('optional-detail'), findsNothing);
    expect(find.text('${ListOptionsStrings.page} 1 / 3'), findsOneWidget);
  });

  testWidgets('cancel preserves current display options', (tester) async {
    ListPresentationSettings? applied;
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: ListPresentationControls(
      settings: const ListPresentationSettings(sortBy: 'name', descending: false),
      sortOptions: const {'name': 'الاسم'}, onChanged: (value) => applied = value,
    ))));
    await tester.tap(find.byTooltip(ListOptionsStrings.title));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(SwitchListTile, ListOptionsStrings.descending));
    await tester.tap(find.text(ListOptionsStrings.cancel));
    await tester.pumpAndSettle();
    expect(applied, isNull);
    expect(tester.takeException(), isNull);
  });
}
