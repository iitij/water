import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/theme/app_colors.dart';
import 'package:water_project/core/theme/app_theme.dart';
import 'package:water_project/core/ui/app_tiles.dart';
import 'package:water_project/features/meters/data/models/meter.dart';
import 'package:water_project/features/meters/domain/meters_repository.dart';
import 'package:water_project/features/meters/presentation/widgets/meter_card_widget.dart';
import 'package:water_project/features/readings/domain/readings_repository.dart';
import 'package:water_project/features/readings/presentation/pages/field_reader_screen.dart';
import 'package:water_project/features/service_requests/domain/repositories/service_requests_repository.dart';
import 'package:water_project/features/service_requests/presentation/cubit/service_requests_cubit.dart';
import 'package:water_project/features/subscribers/domain/entities/subscriber.dart';
import 'package:water_project/features/subscribers/domain/repositories/subscribers_repository.dart';
import 'package:water_project/features/subscribers/presentation/cubit/subscriber360_cubit.dart';
import 'package:water_project/features/subscribers/presentation/cubit/subscribers_cubit.dart';
import 'package:water_project/features/subscribers/presentation/pages/subscriber_360_screen.dart';
import 'package:water_project/features/subscribers/presentation/pages/subscribers_screen.dart';
import 'package:water_project/features/subscription_fees/domain/repositories/subscription_fees_repository.dart';
import 'package:water_project/features/subscription_fees/presentation/cubit/subscription_fees_cubit.dart';

class _Subscribers extends Mock implements SubscribersRepository {}

class _Fees extends Mock implements SubscriptionFeesRepository {}

class _Requests extends Mock implements ServiceRequestsRepository {}

class _Meters extends Mock implements MetersRepository {}

class _Readings extends Mock implements ReadingsRepository {}

const _subscriber = Subscriber(
  id: 42,
  name: 'أحمد أحمد الشتيوي',
  phone: '777123456',
  squareName: 'الربع الثاني',
  metersCount: 1,
  debitBalance: 66600,
);
const _meter = Meter(
  id: 42,
  uniqueCode: '2025067437',
  subscriberName: 'حسن الحقي',
  squareName: 'الربع الثاني',
  pathNumber: 1,
  balance: -55800,
  lastReading: 1287,
);
const _summary = FinancialSummary(
  totalDebit: 66600,
  meterBalances: [
    MeterBalance(meterId: 42, uniqueCode: '2025067437', debit: 66600),
  ],
);

class _ListCubit extends SubscribersCubit {
  _ListCubit() : super(_Subscribers()) {
    emit(const SubscribersState(subscribers: [_subscriber]));
  }
}

class _DetailCubit extends Subscriber360Cubit {
  _DetailCubit({List<Map<String, dynamic>> invoices = const []})
    : super(_Subscribers()) {
    emit(
      Subscriber360State(
        detail: Subscriber360(
          subscriber: _subscriber,
          summary: _summary,
          invoices: invoices,
        ),
      ),
    );
  }

  @override
  Future<void> load(int subscriberId) async {}
}

const _previewKey = ValueKey('experience-preview');

Widget _app(Widget screen, {bool dark = false, double scale = 1}) =>
    MaterialApp(
      theme: dark ? AppTheme.dark : AppTheme.light,
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: Builder(
        builder: (context) => MediaQuery(
          data: MediaQuery.of(
            context,
          ).copyWith(textScaler: TextScaler.linear(scale)),
          child: RepaintBoundary(key: _previewKey, child: screen),
        ),
      ),
    );

Widget _list({void Function(Subscriber)? onOpen}) => MultiBlocProvider(
  providers: [
    BlocProvider<SubscribersCubit>(create: (_) => _ListCubit()),
    BlocProvider<ServiceRequestsCubit>(
      create: (_) => ServiceRequestsCubit(_Requests()),
    ),
  ],
  child: SubscribersScreen(onOpen360: onOpen),
);

Widget _detail({
  CollectPaymentLauncher? onCollect,
  List<Map<String, dynamic>> invoices = const [],
}) => MultiBlocProvider(
  providers: [
    BlocProvider<Subscriber360Cubit>(
      create: (_) => _DetailCubit(invoices: invoices),
    ),
    BlocProvider<SubscriptionFeesCubit>(
      create: (_) => SubscriptionFeesCubit(_Fees()),
    ),
    BlocProvider<ServiceRequestsCubit>(
      create: (_) => ServiceRequestsCubit(_Requests()),
    ),
  ],
  child: Subscriber360Screen(
    subscriber: _subscriber,
    onCollectPayment: onCollect,
    onAddMeter: (_, _) {},
    onRecordReading: (_, _) {},
  ),
);

void _size(WidgetTester tester, double width, {double height = 900}) {
  tester.view.physicalSize = Size(width, height);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
}

Future<void> _preview(WidgetTester tester, String name) async {
  if (!const bool.fromEnvironment('WRITE_REDESIGN_PREVIEWS')) return;
  await tester.runAsync(() async {
    final boundary = tester.renderObject<RenderRepaintBoundary>(
      find.byKey(_previewKey),
    );
    final image = await boundary.toImage();
    final data = await image.toByteData(format: ui.ImageByteFormat.png);
    image.dispose();
    final directory = Directory('build/redesign-preview')
      ..createSync(recursive: true);
    await File(
      '${directory.path}/$name.png',
    ).writeAsBytes(data!.buffer.asUint8List());
  });
}

void main() {
  testWidgets(
    'populated subscriber invoices render after selecting their section',
    (tester) async {
      _size(tester, 390);
      await tester.pumpWidget(
        _app(
          _detail(
            invoices: const [
              {
                'id': 1,
                'bill_number': 'INV-43',
                'invoice_type': 'meter',
                'bill_date': '2026-10-26',
                'total_amount': 16000,
                'amount_paid': 0,
                'status': 'issued',
              },
            ],
          ),
          dark: true,
        ),
      );
      await tester.pumpAndSettle();
      final section = find.text('الفواتير').first;
      await Scrollable.ensureVisible(tester.element(section), alignment: .5);
      await tester.tap(section);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(find.text('INV-43'), findsOneWidget);
    },
  );
  test(
    'secondary text and primary actions meet accessible contrast in both themes',
    () {
      double contrast(Color first, Color second) {
        final a = first.computeLuminance();
        final b = second.computeLuminance();
        return ((a > b ? a : b) + .05) / ((a < b ? a : b) + .05);
      }

      for (final theme in [AppTheme.light, AppTheme.dark]) {
        final scheme = theme.colorScheme;
        for (final background in [
          scheme.surface,
          scheme.surfaceContainerHighest,
          theme.scaffoldBackgroundColor,
        ]) {
          expect(
            contrast(scheme.onSurfaceVariant, background),
            greaterThanOrEqualTo(4.5),
          );
        }
        expect(
          contrast(scheme.onPrimary, scheme.primary),
          greaterThanOrEqualTo(4.5),
        );
      }
    },
  );

  setUpAll(() async {
    final loader = FontLoader('IBMPlexSansArabic');
    for (final weight in ['Regular', 'Medium', 'SemiBold', 'Bold']) {
      loader.addFont(
        rootBundle.load('assets/fonts/IBMPlexSansArabic-$weight.ttf'),
      );
    }
    await loader.load();
    final icons = FontLoader('MaterialIcons')
      ..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf'));
    await icons.load();
  });

  for (final dark in [false, true]) {
    for (final width in [320.0, 390.0, 1024.0]) {
      testWidgets(
        'subscriber pages fit RTL at $width, dark=$dark, large text',
        (tester) async {
          _size(tester, width, height: 760);
          await tester.pumpWidget(_app(_list(), dark: dark, scale: 1.6));
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull);
          await tester.pumpWidget(_app(_detail(), dark: dark, scale: 1.6));
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull);
          final tabs = DefaultTabController.of(
            tester.element(find.text('العدادات').first),
          );
          for (var index = 1; index < tabs.length; index++) {
            tabs.animateTo(index);
            await tester.pumpAndSettle();
            expect(
              tester.takeException(),
              isNull,
              reason: 'section $index at $width',
            );
          }
        },
      );
    }
  }

  testWidgets('meter reading and menu actions keep their callbacks', (
    tester,
  ) async {
    _size(tester, 320);
    var records = 0;
    var edits = 0;
    await tester.pumpWidget(
      _app(
        Scaffold(
          body: SingleChildScrollView(
            child: MeterCardWidget(
              meter: _meter,
              onRecordReading: () => records++,
              onEdit: () => edits++,
            ),
          ),
        ),
        dark: true,
        scale: 1.6,
      ),
    );
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await tester.tap(find.text('تسجيل قراءة'));
    expect(records, 1);
    expect(
      tester.getSize(find.byType(FilledButton)).height,
      greaterThanOrEqualTo(48),
    );
    await tester.tap(find.byType(PopupMenuButton<String>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تعديل العداد'));
    await tester.pumpAndSettle();
    expect(edits, 1);
  });

  for (final width in [390.0, 1440.0]) {
    for (final useButton in [false, true]) {
      testWidgets('subscriber file route at $width, button=$useButton', (
        tester,
      ) async {
        _size(tester, width);
        Subscriber? routedSubscriber;
        String? routedId;
        final router = GoRouter(
          initialLocation: '/subscribers',
          routes: [
            GoRoute(path: '/subscribers', builder: (_, _) => _list()),
            GoRoute(
              path: '/subscribers/:id',
              builder: (_, state) {
                routedSubscriber = state.extra as Subscriber;
                routedId = state.pathParameters['id'];
                return const Scaffold(body: Text('ملف المشترك'));
              },
            ),
          ],
        );
        addTearDown(router.dispose);
        await tester.pumpWidget(
          MaterialApp.router(
            routerConfig: router,
            theme: AppTheme.light,
            locale: const Locale('ar'),
            supportedLocales: const [Locale('ar')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
          ),
        );
        await tester.pumpAndSettle();
        final target = find.text(useButton ? 'عرض الملف' : _subscriber.name);
        await tester.ensureVisible(target);
        await tester.tap(target);
        await tester.pumpAndSettle();
        expect(find.text('ملف المشترك'), findsOneWidget);
        expect(routedId, '${_subscriber.id}');
        expect(routedSubscriber, same(_subscriber));
        expect(tester.takeException(), isNull);
      });
    }

    testWidgets('subscriber card opens its file at $width', (tester) async {
      _size(tester, width);
      final opened = <int>[];
      await tester.pumpWidget(
        _app(_list(onOpen: (subscriber) => opened.add(subscriber.id))),
      );
      await tester.pumpAndSettle();
      final name = find.text(_subscriber.name);
      await tester.ensureVisible(name);
      await tester.tap(name);
      await tester.pumpAndSettle();
      expect(opened, [_subscriber.id]);
      expect(tester.takeException(), isNull);
    });

    testWidgets('subscriber selection stays separate at $width', (
      tester,
    ) async {
      _size(tester, width);
      final opened = <int>[];
      await tester.pumpWidget(
        _app(_list(onOpen: (subscriber) => opened.add(subscriber.id))),
      );
      await tester.pumpAndSettle();
      final checkbox = find.byType(Checkbox);
      await tester.ensureVisible(checkbox);
      await tester.tap(checkbox);
      await tester.pumpAndSettle();
      expect(tester.widget<Checkbox>(checkbox).value, isTrue);
      expect(opened, isEmpty);
      await tester.longPress(find.text(_subscriber.name));
      await tester.pumpAndSettle();
      expect(tester.widget<Checkbox>(checkbox).value, isFalse);
      expect(opened, isEmpty);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('subscriber quick action opens the same financial file', (
    tester,
  ) async {
    _size(tester, 390);
    Subscriber? opened;
    await tester.pumpWidget(
      _app(_list(onOpen: (subscriber) => opened = subscriber)),
    );
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('عرض الملف'));
    await tester.tap(find.text('عرض الملف'));
    expect(opened?.id, _subscriber.id);
  });

  testWidgets('all six subscriber sections and collection remain reachable', (
    tester,
  ) async {
    _size(tester, 390);
    var collected = 0;
    await tester.pumpWidget(
      _app(
        _detail(
          onCollect: (_, subscriber) async {
            expect(subscriber.id, _subscriber.id);
            collected++;
          },
        ),
        dark: true,
      ),
    );
    await tester.pumpAndSettle();
    for (final label in [
      'الفواتير',
      'المدفوعات',
      'الاشتراكات',
      'طلبات الوايت',
      'كشف الحساب',
      'العدادات',
    ]) {
      final section = find.text(label).first;
      await Scrollable.ensureVisible(tester.element(section), alignment: .5);
      await tester.tap(section);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull, reason: label);
    }
    await tester.tap(find.byTooltip('تحصيل دفعة').first);
    await tester.pumpAndSettle();
    expect(collected, 1);
  });

  testWidgets(
    'financial strip keeps long amounts at narrow width and large text',
    (tester) async {
      _size(tester, 320);
      await tester.pumpWidget(
        _app(
          const Scaffold(
            body: SingleChildScrollView(
              child: Padding(
                padding: EdgeInsets.all(16),
                child: AppStatStrip(
                  items: [
                    AppStatItem(
                      label: 'إجمالي الرسوم',
                      value: '284,500 ريال',
                      icon: Icons.payments,
                      color: AppColors.primary,
                    ),
                    AppStatItem(
                      label: 'المفوتر',
                      value: '267,000 ريال',
                      icon: Icons.receipt,
                      color: AppColors.success,
                    ),
                    AppStatItem(
                      label: 'المتبقي غير المفوتر',
                      value: '17,500 ريال',
                      icon: Icons.hourglass_bottom,
                      color: AppColors.warning,
                    ),
                  ],
                ),
              ),
            ),
          ),
          dark: true,
          scale: 2,
        ),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(find.textContaining('284,500'), findsOneWidget);
    },
  );

  testWidgets('field search and status filters preserve repository query', (
    tester,
  ) async {
    _size(tester, 390);
    final meters = _Meters();
    when(
      () => meters.getMeters(
        query: any(named: 'query'),
        squareId: any(named: 'squareId'),
        status: any(named: 'status'),
      ),
    ).thenAnswer(
      (_) async => [
        _meter,
        _meter.copyWith(
          id: 43,
          subscriberName: 'محمد مقبل سعيد العمري',
          uniqueCode: '2025060461',
        ),
      ],
    );
    when(() => meters.getSquares()).thenAnswer((_) async => []);
    await tester.pumpWidget(
      _app(
        FieldReaderScreen(
          metersRepository: meters,
          readingsRepository: _Readings(),
        ),
        dark: true,
      ),
    );
    await tester.pump(const Duration(milliseconds: 350));
    await tester.pumpAndSettle();
    expect(find.text(_meter.subscriberName!), findsOneWidget);
    expect(tester.takeException(), isNull);
    await _preview(tester, 'field-dark');
    await tester.tap(find.text('الاسم أو رقم العداد'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '2025067437');
    await tester.pump(const Duration(milliseconds: 350));
    await tester.pumpAndSettle();
    verify(
      () => meters.getMeters(query: '2025067437', squareId: null, status: null),
    ).called(1);
  });

  testWidgets('capture subscriber list and financial file previews', (
    tester,
  ) async {
    _size(tester, 390);
    await tester.pumpWidget(_app(_list(), dark: true));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await _preview(tester, 'subscribers-dark');
    await tester.pumpWidget(_app(_detail(), dark: true));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await _preview(tester, 'subscriber-dark');
    await tester.tap(find.text('الاشتراكات'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await _preview(tester, 'subscriber-fees-dark');
    await tester.pumpWidget(_app(_detail()));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await _preview(tester, 'subscriber-light');
  });
}
