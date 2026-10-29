import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';
import 'package:water_project/features/settings/data/datasources/settings_remote_data_source.dart';
import 'package:water_project/features/settings/data/repositories/settings_repository_impl.dart';
import 'package:water_project/features/settings/domain/entities/settings_entities.dart';
import 'package:water_project/features/settings/presentation/cubit/template_settings_cubit.dart';
import 'package:water_project/features/settings/presentation/pages/template_settings_editor.dart';
import 'package:water_project/features/settings/presentation/widgets/setting_image_field.dart';
import 'package:water_project/features/settings/presentation/widgets/settings_form_widgets.dart';
import 'package:water_project/features/settings/presentation/widgets/template_preview.dart';

import '../support/fake_admin_api_client.dart';
import '../support/memory_settings_cache.dart';

void main() {
  for (final kind in TemplateKind.values) {
    for (final embedded in [false, true]) {
      for (final width in [400.0, 1440.0]) {
        testWidgets('${kind.name} opens without an ancestor cubit '
            '(embedded: $embedded, width: $width)', (tester) async {
          tester.view.physicalSize = Size(width, 1000);
          tester.view.devicePixelRatio = 1;
          addTearDown(tester.view.resetPhysicalSize);
          addTearDown(tester.view.resetDevicePixelRatio);
          final cache = MemorySettingsCache();
          addTearDown(cache.dispose);
          final api = FakeAdminApiClient();
          final repository = SettingsRepositoryImpl(
            SettingsRemoteDataSource(api),
            cache,
          );

          await tester.pumpWidget(
            MaterialApp(
              home: Directionality(
                textDirection: TextDirection.rtl,
                child: TemplateSettingsEditor(
                  repository: repository,
                  kind: kind,
                  embedded: embedded,
                ),
              ),
            ),
          );
          await tester.pumpAndSettle();

          expect(tester.takeException(), isNull);
          expect(find.byType(TabBar), findsOneWidget);
          expect(find.byType(SettingsSaveBar), findsOneWidget);
          expect(
            find.byType(SettingImageField),
            findsNWidgets(kind == TemplateKind.invoice ? 3 : 2),
          );
          expect(
            find.byType(TemplatePreview),
            width >= 1100 ? findsOneWidget : findsNothing,
          );
          expect(api.requests.single.path, '/settings/${kind.name}');
          await tester.pumpWidget(const SizedBox.shrink());
        });
      }
    }

    testWidgets('${kind.name} uses its own upload state and saves the image', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(1440, 1000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final cache = MemorySettingsCache();
      final outerCache = MemorySettingsCache();
      addTearDown(cache.dispose);
      addTearDown(outerCache.dispose);
      final api = FakeAdminApiClient();
      final outerApi = FakeAdminApiClient();
      final upload = Completer<Map<String, dynamic>>();
      final outerUpload = Completer<Map<String, dynamic>>();
      api.handlers['UPLOAD:/settings/upload-logo'] = upload.future;
      outerApi.handlers['UPLOAD:/settings/upload-logo'] = outerUpload.future;
      final repository = SettingsRepositoryImpl(
        SettingsRemoteDataSource(api),
        cache,
      );
      final outerCubit = TemplateSettingsCubit(
        SettingsRepositoryImpl(SettingsRemoteDataSource(outerApi), outerCache),
        kind,
      );
      addTearDown(outerCubit.close);
      await outerCubit.load();
      final outerUploadTask = outerCubit.uploadImage(
        'invoice_logo',
        Uint8List.fromList([1, 2, 3]),
        'outer.png',
      );
      await tester.pumpWidget(
        MaterialApp(
          home: BlocProvider.value(
            value: outerCubit,
            child: TemplateSettingsEditor(repository: repository, kind: kind),
          ),
        ),
      );
      await tester.pumpAndSettle();

      SettingImageField imageField(String key) =>
          tester.widget<SettingImageField>(
            find.byWidgetPredicate(
              (widget) =>
                  widget is SettingImageField && widget.settingKey == key,
            ),
          );

      expect(imageField('invoice_logo').isUploading, isFalse);
      imageField('invoice_watermark').onSelected(
        XFile.fromData(
          Uint8List.fromList([1, 2, 3]),
          path: 'watermark.png',
          name: 'watermark.png',
        ),
      );
      await tester.pump();
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(imageField('invoice_watermark').isUploading, isTrue);
      expect(imageField('invoice_logo').isUploading, isFalse);
      expect(
        tester.widget<SettingsSaveBar>(find.byType(SettingsSaveBar)).enabled,
        isFalse,
      );

      upload.complete({'path': 'uploads/watermark.png'});
      await tester.pumpAndSettle();
      expect(imageField('invoice_watermark').isUploading, isFalse);
      expect(imageField('invoice_watermark').path, 'uploads/watermark.png');
      expect(
        tester.widget<SettingsSaveBar>(find.byType(SettingsSaveBar)).enabled,
        isTrue,
      );
      await tester.tap(find.text('حفظ الإعدادات وتحديث التطبيق'));
      await tester.pumpAndSettle();
      final save = api.requests.singleWhere(
        (request) => request.method == 'POST',
      );
      expect(save.path, '/settings/${kind.name}');
      expect((save.data as Map)['invoice_watermark'], 'uploads/watermark.png');
      expect(tester.takeException(), isNull);

      outerUpload.complete({'path': 'uploads/outer.png'});
      await outerUploadTask;
      await tester.pumpWidget(const SizedBox.shrink());
    });
  }
}
