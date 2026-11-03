import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/fouad/api_client.dart';
import 'package:water_project/features/invoices/data/datasources/references_remote_datasource.dart';
import 'package:water_project/features/squares/data/datasources/squares_remote_datasource.dart';
import 'package:water_project/features/squares/data/models/square_model.dart';
import 'package:water_project/features/squares/data/repositories/squares_repository_impl.dart';
import 'package:water_project/features/squares/domain/entities/square.dart';
import 'package:water_project/features/squares/domain/repositories/squares_repository.dart';
import 'package:water_project/features/squares/presentation/cubit/squares_cubit.dart';
import 'package:water_project/features/squares/presentation/pages/squares_screen.dart';
import 'package:water_project/features/subscribers/domain/entities/subscriber.dart';

class _Api extends Mock implements ApiClient {}

class _Squares implements SquaresRepository {
  List<Square> rows = [];
  Square? saved;
  String? search;
  bool failSave = false;

  @override
  Future<List<Square>> getSquares({String? search}) async {
    this.search = search;
    return rows;
  }

  @override
  Future<({Square square, List<Subscriber> subscribers})> getSquare(
    int id,
  ) async => (
    square: rows.singleWhere((square) => square.id == id),
    subscribers: <Subscriber>[],
  );

  @override
  Future<Square> createSquare(
    String name,
    String? description, {
    String? code,
  }) async {
    if (failSave) throw StateError('duplicate');
    saved = Square(id: 8, name: name, description: description, code: code);
    rows = [saved!];
    return saved!;
  }

  @override
  Future<Square> updateSquare(Square square) async {
    saved = square;
    rows = [square];
    return square;
  }

  @override
  Future<void> deleteSquare(int id) async {}

  @override
  Future<int> bulkDelete(List<int> ids) async => 0;
}

void main() {
  test('legacy squares stay unnamed by code and owner code keeps its case', () {
    final old = SquareModel.fromJson({'id': 1, 'name': 'اسم قديم'});
    expect(old.code, isNull);
    expect(old.displayName, 'اسم قديم');
    final cached = SquareModel.fromJson({
      'server_id': 4,
      'name': 'اسم قديم',
      'code': 'B1',
    });
    expect(cached.id, 4);
    expect(cached.code, 'B1');
    final square = SquareModel.fromJson({
      'id': 1,
      'name': 'اسم المالك',
      'code': 'Ab 1 / الحارة',
    });
    expect(square.code, 'Ab 1 / الحارة');
    expect(square.displayName, 'Ab 1 / الحارة — اسم المالك');
    expect(square.toPayload(), containsPair('code', 'Ab 1 / الحارة'));
    expect(square, isNot(old));
    expect(
      const Square(id: 1, name: 'اسم', code: 'a1'),
      isNot(const Square(id: 1, name: 'اسم', code: 'a2')),
    );
    final option = SquareOption.fromJson({
      'id': 1,
      'name': 'اسم',
      'code': 'B1',
    });
    expect(option.displayName, 'B1 — اسم');
    expect(
      SquareOption.fromJson({'id': 1, 'name': 'قديم'}).displayName,
      'قديم',
    );
  });

  test(
    'repository forwards create, update, clear and search code to API',
    () async {
      final api = _Api();
      final repository = SquaresRepositoryImpl(SquaresRemoteDatasource(api));
      when(() => api.post('/squares', data: any(named: 'data'))).thenAnswer(
        (call) async => {
          'square': {
            'id': 8,
            ...call.namedArguments[#data] as Map<String, dynamic>,
          },
        },
      );
      when(() => api.put('/squares/8', data: any(named: 'data'))).thenAnswer(
        (call) async => {
          'square': {
            'id': 8,
            ...call.namedArguments[#data] as Map<String, dynamic>,
          },
        },
      );
      when(() => api.get('/squares', query: any(named: 'query'))).thenAnswer(
        (_) async => [
          {'id': 8, 'name': 'اختياري', 'code': 'a1'},
        ],
      );
      final created = await repository.createSquare(
        'اختياري',
        null,
        code: 'a1',
      );
      expect(created.code, 'a1');
      verify(
        () => api.post(
          '/squares',
          data: {'name': 'اختياري', 'description': null, 'code': 'a1'},
        ),
      ).called(1);
      await repository.updateSquare(
        const Square(id: 8, name: 'اختياري', code: 'B1'),
      );
      verify(
        () => api.put(
          '/squares/8',
          data: {'name': 'اختياري', 'description': null, 'code': 'B1'},
        ),
      ).called(1);
      await repository.updateSquare(const SquareModel(id: 8, name: 'اختياري'));
      verify(
        () => api.put(
          '/squares/8',
          data: {'name': 'اختياري', 'description': null, 'code': null},
        ),
      ).called(1);
      expect((await repository.getSquares(search: 'a1')).single.code, 'a1');
      verify(
        () => api.get('/squares', query: {'search': 'a1', 'page': 1}),
      ).called(1);
    },
  );

  test(
    'Cubit passes owner code on both save paths and does not assign defaults',
    () async {
      final repository = _Squares();
      final cubit = SquaresCubit(repository);
      addTearDown(cubit.close);
      await cubit.save(name: 'اسم يختاره المالك', code: 'A1 / المربع');
      expect(repository.saved!.name, 'اسم يختاره المالك');
      expect(repository.saved!.code, 'A1 / المربع');
      await cubit.save(id: 8, name: 'اسم يختاره المالك', code: null);
      expect(repository.saved!.code, isNull);
      await cubit.load(search: 'a1');
      expect(repository.search, 'a1');
    },
  );

  for (final width in [390.0, 1280.0]) {
    testWidgets('owner enters and clears square code at width $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 1000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final repository = _Squares();
      final cubit = SquaresCubit(repository);
      addTearDown(cubit.close);
      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: const [Locale('ar')],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          home: BlocProvider.value(value: cubit, child: const SquaresScreen()),
        ),
      );
      await tester.tap(find.text('إضافة مربع'));
      await tester.pumpAndSettle();
      final codeField = find.byKey(const ValueKey('square-code'));
      expect(tester.widget<TextField>(codeField).controller!.text, isEmpty);
      final fields = find.descendant(
        of: find.byType(AlertDialog),
        matching: find.byType(TextField),
      );
      await tester.enterText(fields.first, 'اسم المالك');
      await tester.enterText(codeField, '  Ab / الحارة  ');
      await tester.tap(find.text('حفظ'));
      await tester.pumpAndSettle();
      expect(repository.saved!.name, 'اسم المالك');
      expect(repository.saved!.code, 'Ab / الحارة');
      expect(find.text('Ab / الحارة — اسم المالك'), findsOneWidget);
      await tester.tap(find.byTooltip('خيارات المربع'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('تعديل المربع'));
      await tester.pumpAndSettle();
      expect(
        tester.widget<TextField>(codeField).controller!.text,
        'Ab / الحارة',
      );
      await tester.enterText(codeField, '');
      await tester.tap(find.text('حفظ'));
      await tester.pumpAndSettle();
      expect(repository.saved!.code, isNull);
      expect(find.text('اسم المالك'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    });
  }

  testWidgets('failed save preserves owner entries and permits retry', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final repository = _Squares()..failSave = true;
    final cubit = SquaresCubit(repository);
    addTearDown(cubit.close);
    await tester.pumpWidget(
      MaterialApp(
        home: BlocProvider.value(value: cubit, child: const SquaresScreen()),
      ),
    );
    await tester.tap(find.text('إضافة مربع'));
    await tester.pumpAndSettle();
    final fields = find.descendant(
      of: find.byType(AlertDialog),
      matching: find.byType(TextField),
    );
    final codeField = find.byKey(const ValueKey('square-code'));
    await tester.enterText(fields.first, 'اسم المالك');
    await tester.enterText(codeField, 'Ab 1');
    await tester.tap(find.text('حفظ'));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsOneWidget);
    expect(tester.widget<TextField>(codeField).controller!.text, 'Ab 1');
    expect(
      tester.widget<TextField>(fields.first).controller!.text,
      'اسم المالك',
    );
    repository.failSave = false;
    await tester.tap(find.text('حفظ'));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsNothing);
    expect(repository.saved!.code, 'Ab 1');
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
