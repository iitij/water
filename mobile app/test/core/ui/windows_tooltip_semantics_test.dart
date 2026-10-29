import 'dart:async';
import 'dart:ui' as ui;

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/ui/app_controls.dart';
import 'package:water_project/core/ui/app_tooltip.dart';

class _SerializedNode {
  const _SerializedNode({
    this.children = const [],
    this.label = '',
    this.tooltip = '',
    this.actions = 0,
  });

  final List<int> children;
  final String label;
  final String tooltip;
  final int actions;
}

class _SemanticsRecordingBinding extends AutomatedTestWidgetsFlutterBinding {
  final nodes = <int, _SerializedNode>{};
  final invalidBatches = <List<int>>[];
  int batchCount = 0;

  @override
  ui.SemanticsUpdateBuilder createSemanticsUpdateBuilder() =>
      _RecordingBuilder(this);

  void record(Map<int, _SerializedNode> changes) {
    batchCount++;
    nodes.addAll(changes);
    final reachable = <int>{};
    void visit(int id) {
      if (!reachable.add(id)) return;
      for (final child in nodes[id]?.children ?? const <int>[]) {
        visit(child);
      }
    }

    visit(0);
    final orphans = changes.keys
        .where((id) => !reachable.contains(id))
        .toList();
    if (orphans.isNotEmpty) invalidBatches.add(orphans);
    nodes.removeWhere((id, _) => !reachable.contains(id));
  }
}

// Capture the actual traversal child ids sent to the native accessibility
// bridge. Checking only Dart exceptions misses malformed native AXTree updates.
// Forwarding updateNode also keeps this harness independent of its SDK signature.
class _RecordingBuilder implements ui.SemanticsUpdateBuilder {
  _RecordingBuilder(this.binding);

  final _SemanticsRecordingBinding binding;
  final delegate = ui.SemanticsUpdateBuilder();
  final changes = <int, _SerializedNode>{};

  @override
  dynamic noSuchMethod(Invocation invocation) {
    if (invocation.memberName == #updateNode) {
      changes[invocation.namedArguments[#id] as int] = _SerializedNode(
        children: List<int>.of(
          invocation.namedArguments[#childrenInTraversalOrder] as Iterable<int>,
        ),
        label: invocation.namedArguments[#label] as String,
        tooltip: invocation.namedArguments[#tooltip] as String,
        actions: invocation.namedArguments[#actions] as int,
      );
      return Function.apply(
        delegate.updateNode,
        invocation.positionalArguments,
        invocation.namedArguments,
      );
    }
    return super.noSuchMethod(invocation);
  }

  @override
  void updateCustomAction({
    required int id,
    String? label,
    String? hint,
    int overrideId = -1,
  }) => delegate.updateCustomAction(
    id: id,
    label: label,
    hint: hint,
    overrideId: overrideId,
  );

  @override
  ui.SemanticsUpdate build() {
    binding.record(changes);
    return delegate.build();
  }
}

void main() {
  final binding = _SemanticsRecordingBinding();

  setUp(() {
    binding.nodes.clear();
    binding.invalidBatches.clear();
    binding.batchCount = 0;
  });

  test('recorder detects an updated node outside the root traversal tree', () {
    binding.record({
      0: const _SerializedNode(children: [1]),
      1: const _SerializedNode(),
    });
    expect(binding.invalidBatches, isEmpty);
    binding.record({2: const _SerializedNode(label: 'orphan overlay')});
    expect(binding.invalidBatches, [
      [2],
    ]);
  });

  for (final kind in ['information', 'mixed', 'enabled', 'disabled']) {
    testWidgets(
      '$kind tooltips keep reachable nodes during hover and route changes',
      (tester) async {
        final semantics = tester.ensureSemantics();
        final navigator = GlobalKey<NavigatorState>();
        var taps = 0;
        try {
          Widget button(String key, String tooltip, IconData icon) =>
              AppIconButton(
                key: ValueKey(key),
                icon: icon,
                tooltip: tooltip,
                onPressed: kind == 'disabled' ? null : () => taps++,
              );
          Widget information(String key, String tooltip, String text) =>
              AppTooltip(
                key: ValueKey(key),
                message: tooltip,
                child: SizedBox.square(dimension: 100, child: Text(text)),
              );

          await tester.pumpWidget(
            MaterialApp(
              theme: ThemeData(platform: TargetPlatform.windows),
              navigatorKey: navigator,
              home: Scaffold(
                // IndexedSemantics merges both anchors into one list item if
                // their surrounding accessibility boundaries are removed.
                body: ListView(
                  children: [
                    Row(
                      children: [
                        if (kind == 'mixed')
                          const Tooltip(
                            key: ValueKey('first'),
                            message: 'تحديث',
                            child: SizedBox.square(
                              dimension: 100,
                              child: Text('A'),
                            ),
                          )
                        else if (kind == 'information')
                          information('first', 'تحديث', 'A')
                        else
                          button('first', 'تحديث', Icons.refresh),
                        if (kind == 'information')
                          information('second', 'بحث', 'B')
                        else
                          button('second', 'بحث', Icons.search),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          );
          expect(
            binding.nodes.values.any((node) => node.tooltip == 'تحديث'),
            isTrue,
          );
          expect(
            binding.nodes.values.any((node) => node.tooltip == 'بحث'),
            isTrue,
          );

          final mouse = await tester.createGesture(
            kind: PointerDeviceKind.mouse,
          );
          await mouse.addPointer(location: const Offset(700, 500));
          addTearDown(mouse.removePointer);
          for (var i = 0; i < 3; i++) {
            await mouse.moveTo(
              tester.getCenter(find.byKey(const ValueKey('first'))),
            );
            await tester.pump(const Duration(milliseconds: 600));
            await mouse.moveTo(
              tester.getCenter(find.byKey(const ValueKey('second'))),
            );
            await tester.pump(const Duration(milliseconds: 30));
            await tester.pump(const Duration(milliseconds: 200));
            expect(find.text('بحث'), findsOneWidget);
          }
          unawaited(
            navigator.currentState!.push(
              MaterialPageRoute<void>(
                builder: (_) => const Scaffold(body: Text('وجهة أخرى')),
              ),
            ),
          );
          await tester.pumpAndSettle();
          navigator.currentState!.pop();
          await tester.pumpAndSettle();
          await mouse.moveTo(const Offset(700, 500));
          await tester.pumpAndSettle();
          expect(find.text('بحث'), findsNothing);

          if (kind == 'mixed' || kind == 'enabled') {
            expect(
              binding.nodes.values.any(
                (node) =>
                    node.label.contains('بحث') &&
                    node.actions & ui.SemanticsAction.tap.index != 0,
              ),
              isTrue,
            );
            await tester.tap(find.byKey(const ValueKey('second')));
            expect(taps, 1);
          } else if (kind == 'disabled') {
            expect(
              binding.nodes.values.any(
                (node) =>
                    node.label.contains('بحث') &&
                    node.actions & ui.SemanticsAction.tap.index != 0,
              ),
              isFalse,
            );
          }
          expect(binding.batchCount, greaterThan(1));
          expect(binding.invalidBatches, isEmpty);
        } finally {
          semantics.dispose();
        }
      },
    );
  }
}
