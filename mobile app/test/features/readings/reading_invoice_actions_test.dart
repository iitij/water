import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/error/failures.dart';
import 'package:water_project/core/l10n/reading_invoice_strings.dart';
import 'package:water_project/features/readings/data/models/reading.dart';
import 'package:water_project/features/readings/data/services/reading_invoice_actions_service.dart';

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  const printing = MethodChannel('net.nfet.printing');
  const sharing = MethodChannel('dev.fluttercommunity.plus/share');
  const launcher = MethodChannel('plugins.flutter.io/url_launcher');
  const paths = MethodChannel('plugins.flutter.io/path_provider');
  final invoice = InvoiceSummary(
    id: 592,
    billNumber: 'INV-202611-0003',
    totalAmount: 47200,
    subscriberName: 'مشترك الاختبار',
    subscriberPhone: '0777488791',
    meterCode: '2025060644',
    consumptionUnits: 20,
    periodStart: DateTime(2026, 7),
    periodEnd: DateTime(2026, 7, 31),
    pdfUrl: 'https://example.test/api/invoices/592/pdf',
  );
  late Dio dio;
  late ReadingInvoiceActionsService service;
  late Directory directory;
  late List<MethodCall> shares;
  late List<Uri> launched;
  late List<String> downloads;
  late List<MethodCall> printCalls;
  var printError = false;
  var pdf = '%PDF-1.7 test';
  var pages = 1;
  var failRaster = false;
  var launchResult = true;
  var httpStatus = 200;
  var dismissShare = false;

  setUp(() async {
    directory = await Directory.systemTemp.createTemp('water-invoice-test-');
    shares = [];
    launched = [];
    downloads = [];
    printCalls = [];
    printError = false;
    pdf = '%PDF-1.7 test';
    pages = 1;
    failRaster = false;
    launchResult = true;
    httpStatus = 200;
    dismissShare = false;
    dio = Dio(BaseOptions(baseUrl: 'https://example.test/api/'));
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (request, handler) {
          downloads.add(request.path);
          if (httpStatus != 200) {
            handler.reject(
              DioException(
                requestOptions: request,
                response: Response<dynamic>(
                  requestOptions: request,
                  statusCode: httpStatus,
                ),
                type: DioExceptionType.badResponse,
              ),
            );
            return;
          }
          handler.resolve(
            Response<List<int>>(
              requestOptions: request,
              data: Uint8List.fromList(pdf.codeUnits),
              statusCode: 200,
            ),
          );
        },
      ),
    );
    service = ReadingInvoiceActionsService(dio);
    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      paths,
      (_) async => directory.path,
    );
    binding.defaultBinaryMessenger.setMockMethodCallHandler(sharing, (
      call,
    ) async {
      shares.add(call);
      return dismissShare ? '' : 'whatsapp';
    });
    binding.defaultBinaryMessenger.setMockMethodCallHandler(launcher, (
      call,
    ) async {
      final args = call.arguments as Map;
      launched.add(Uri.parse(args['url'] as String));
      return launchResult;
    });
    binding.defaultBinaryMessenger.setMockMethodCallHandler(printing, (
      call,
    ) async {
      if (call.method == 'printPdf') {
        printCalls.add(call);
        if (printError) throw PlatformException(code: 'print_failed');
        final args = call.arguments as Map;
        await binding.defaultBinaryMessenger.handlePlatformMessage(
          printing.name,
          printing.codec.encodeMethodCall(
            MethodCall('onCompleted', {'job': args['job'], 'completed': false}),
          ),
          (_) {},
        );
        return 1;
      }
      expect(call.method, 'rasterPdf');
      final args = call.arguments as Map;
      expect(
        String.fromCharCodes(args['doc'] as Uint8List),
        startsWith('%PDF'),
      );
      expect(
        args['pages'],
        isNull,
        reason: 'All invoice pages must be included',
      );
      for (var index = 0; index < pages && !failRaster; index++) {
        await binding.defaultBinaryMessenger.handlePlatformMessage(
          printing.name,
          printing.codec.encodeMethodCall(
            MethodCall('onPageRasterized', {
              'job': args['job'],
              'width': 1,
              'height': 1,
              'image': Uint8List.fromList([255, index, 0, 255]),
            }),
          ),
          (_) {},
        );
      }
      await binding.defaultBinaryMessenger.handlePlatformMessage(
        printing.name,
        printing.codec.encodeMethodCall(
          MethodCall('onPageRasterEnd', {
            'job': args['job'],
            if (failRaster) 'error': 'render failed',
          }),
        ),
        (_) {},
      );
      return null;
    });
  });

  test('reading result prints a single authenticated official PDF', () async {
    await service.printInvoice(invoice);
    expect(downloads, ['invoices/592/pdf']);
    expect(printCalls, hasLength(1));
    expect(
      (printCalls.single.arguments as Map)['name'],
      'invoice-INV-202611-0003.pdf',
    );
    expect(launched, isEmpty);
  });

  test(
    'printing failures never trigger repeated downloads or a browser',
    () async {
      printError = true;
      await expectLater(
        service.printInvoice(invoice),
        throwsA(isA<UnexpectedFailure>()),
      );
      expect(downloads, hasLength(1));
      expect(printCalls, hasLength(1));
      expect(launched, isEmpty);
    },
  );

  test('failed server PDF is reported after one request', () async {
    httpStatus = 500;
    await expectLater(
      service.printInvoice(invoice),
      throwsA(isA<ServerFailure>()),
    );
    expect(downloads, hasLength(1));
    expect(printCalls, isEmpty);
    expect(launched, isEmpty);
  });

  tearDown(() async {
    dio.close();
    for (final channel in [printing, sharing, launcher, paths]) {
      binding.defaultBinaryMessenger.setMockMethodCallHandler(channel, null);
    }
    await directory.delete(recursive: true);
  });

  testWidgets(
    'WhatsApp shares official invoice as PNG images with caption and recipient',
    (tester) async {
      await tester.runAsync(() async {
        pages = 2;
        await service.shareOnWhatsApp(invoice);
        expect(downloads, ['invoices/592/pdf']);
        expect(
          launched,
          isEmpty,
          reason: 'Do not silently fall back to a text-only wa.me link',
        );
        final call = shares.single;
        expect(call.method, 'shareFiles');
        final args = call.arguments as Map;
        final filePaths = (args['paths'] as List).cast<String>();
        expect(filePaths, hasLength(2));
        expect(args['mimeTypes'], ['image/png', 'image/png']);
        for (var i = 0; i < filePaths.length; i++) {
          expect(
            filePaths[i],
            endsWith('invoice-INV-202611-0003-${i + 1}.png'),
          );
          expect((await File(filePaths[i]).readAsBytes()).take(8), [
            137,
            80,
            78,
            71,
            13,
            10,
            26,
            10,
          ]);
        }
        expect(args['text'], contains('+967777488791'));
        expect(args['text'], contains('INV-202611-0003'));
        expect(args['text'], contains('47200'));
        expect(args['text'], isNot(contains('https://')));
      });
    },
  );

  testWidgets('SMS opens addressed composer with intact Arabic invoice body', (
    tester,
  ) async {
    await service.sendSms(invoice);
    final uri = launched.single;
    expect(uri.scheme, 'sms');
    expect(uri.path, '+967777488791');
    expect(uri.queryParameters['body'], contains('فاتورة استهلاك مياه'));
    expect(uri.queryParameters['body'], contains('INV-202611-0003'));
    expect(uri.queryParameters['body'], contains('47200'));
    expect(downloads, isEmpty);
    expect(shares, isEmpty);
  });

  testWidgets('unsupported SMS handler returns an actionable error', (
    tester,
  ) async {
    launchResult = false;
    await expectLater(
      service.sendSms(invoice),
      throwsA(
        isA<StateError>().having(
          (e) => e.message,
          'message',
          ReadingInvoiceStrings.smsUnavailable,
        ),
      ),
    );
  });

  testWidgets('missing phone prevents both messaging actions', (tester) async {
    const missing = InvoiceSummary(id: 1, totalAmount: 0);
    for (final action in [service.sendSms, service.shareOnWhatsApp]) {
      await expectLater(
        action(missing),
        throwsA(
          isA<StateError>().having(
            (e) => e.message,
            'message',
            ReadingInvoiceStrings.missingPhone,
          ),
        ),
      );
    }
    expect(downloads, isEmpty);
    expect(launched, isEmpty);
    expect(shares, isEmpty);
  });

  testWidgets(
    'unauthorized PDF never becomes a text-only or unauthenticated share',
    (tester) async {
      await tester.runAsync(() async {
        httpStatus = 403;
        await expectLater(
          service.shareOnWhatsApp(invoice),
          throwsA(isA<DioException>()),
        );
        expect(downloads, ['invoices/592/pdf']);
        expect(launched, isEmpty);
        expect(shares, isEmpty);
      });
    },
  );

  testWidgets('non-PDF content is rejected before rendering', (tester) async {
    await tester.runAsync(() async {
      pdf = '<html>login</html>';
      await expectLater(
        service.shareOnWhatsApp(invoice),
        throwsA(
          isA<StateError>().having(
            (e) => e.message,
            'message',
            ReadingInvoiceStrings.invalidPdf,
          ),
        ),
      );
      expect(shares, isEmpty);
    });
  });

  for (final fail in [false, true]) {
    testWidgets(
      'empty or failed raster is reported without text-only fallback ($fail)',
      (tester) async {
        await tester.runAsync(() async {
          pages = 0;
          failRaster = fail;
          await expectLater(
            service.shareOnWhatsApp(invoice),
            throwsA(
              isA<StateError>().having(
                (e) => e.message,
                'message',
                ReadingInvoiceStrings.imageFailed,
              ),
            ),
          );
          expect(shares, isEmpty);
          expect(launched, isEmpty);
        });
      },
    );
  }

  testWidgets('dismissing the share sheet does not trigger another send', (
    tester,
  ) async {
    await tester.runAsync(() async {
      dismissShare = true;
      await service.shareOnWhatsApp(invoice);
      expect(shares, hasLength(1));
      expect(launched, isEmpty);
    });
  });
}
