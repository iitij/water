import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:water_project/core/diagnostics/api_log_manager.dart';
import 'package:water_project/features/diagnostics/presentation/widgets/floating_api_log_overlay.dart';

void main() {
  for (final visible in [false, true]) {
    testWidgets('logging during child build is safe (visible: $visible)', (
      tester,
    ) async {
      final manager = ApiLogManager()..showFloatingPill = visible;
      GetIt.I.registerSingleton<ApiLogManager>(manager);
      addTearDown(() async {
        await GetIt.I.reset();
        manager.dispose();
      });
      await tester.pumpWidget(
        MaterialApp(
          home: FloatingApiLogOverlay(
            child: Builder(
              builder: (_) {
                manager.logAppError('invoice', StateError('original error'));
                return const SizedBox();
              },
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(manager.errorCount, 1);
      expect(find.text('API: 1'), visible ? findsOneWidget : findsNothing);
    });
  }
}
