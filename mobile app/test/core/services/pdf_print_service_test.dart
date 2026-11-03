import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pdf/pdf.dart';
import 'package:water_project/core/error/failures.dart';
import 'package:water_project/core/finance/finance_api_client.dart';
import 'package:water_project/core/l10n/printing_strings.dart';
import 'package:water_project/core/services/pdf_print_service.dart';

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  const channel = MethodChannel('net.nfet.printing');
  final pdf = Uint8List.fromList('%PDF-1.7 test'.codeUnits);
  late Dio dio;
  late PdfPrintService service;
  late List<RequestOptions> requests;
  late Map<String, dynamic> printArgs;
  var status = 200;
  var fallbackStatus = 200;
  var body = pdf;
  var completed = true;
  var nativeError = false;
  var prints = 0;

  setUp(() {
    status = fallbackStatus = 200;
    completed = true;
    nativeError = false;
    prints = 0;
    body = pdf;
    requests = [];
    printArgs = {};
    dio = Dio(BaseOptions(baseUrl: 'https://example.test/api/'));
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          requests.add(options);
          final code = requests.length == 1 ? status : fallbackStatus;
          final response = Response<List<int>>(
            requestOptions: options,
            statusCode: code,
            data: body,
          );
          if (code >= 400) {
            handler.reject(
              DioException(
                requestOptions: options,
                response: response,
                type: DioExceptionType.badResponse,
              ),
            );
          } else {
            handler.resolve(response);
          }
        },
      ),
    );
    service = PdfPrintService(client: FinanceApiClient(dio));
    binding.defaultBinaryMessenger.setMockMethodCallHandler(channel, (
      call,
    ) async {
      expect(call.method, 'printPdf');
      prints++;
      if (nativeError) throw PlatformException(code: 'printer_error');
      printArgs = Map<String, dynamic>.from(call.arguments as Map);
      await binding.defaultBinaryMessenger.handlePlatformMessage(
        channel.name,
        channel.codec.encodeMethodCall(
          MethodCall('onCompleted', {
            'job': printArgs['job'],
            'completed': completed,
          }),
        ),
        (_) {},
      );
      return 1;
    });
  });

  tearDown(() {
    dio.close();
    debugDefaultTargetPlatformOverride = null;
    binding.defaultBinaryMessenger.setMockMethodCallHandler(channel, null);
  });

  test('loads authenticated API path with a bounded PDF timeout', () async {
    expect(await service.fetchPdf('/invoices/5/pdf'), pdf);
    expect(requests.single.uri.path, '/api/invoices/5/pdf');
    expect(requests.single.receiveTimeout, const Duration(seconds: 45));
  });

  test('rejects HTML and empty responses before the native printer', () async {
    for (final content in ['', '<html>login</html>', '{"message":"error"}']) {
      body = Uint8List.fromList(content.codeUnits);
      await expectLater(
        service.fetchPdf('invoices/5/pdf'),
        throwsA(isA<UnexpectedFailure>()),
      );
      await expectLater(
        service.printBytes(body),
        throwsA(isA<UnexpectedFailure>()),
      );
    }
    expect(prints, 0);
  });

  for (final code in [401, 403, 500, 504]) {
    test('does not retry or open a browser after HTTP $code', () async {
      status = code;
      await expectLater(
        service.fetchPdf(
          'invoices/5/pdf',
          fallbackUrl: 'https://example.test/invoices/5/print',
        ),
        throwsA(isA<AppFailure>()),
      );
      expect(requests, hasLength(1));
    });
  }

  test('legacy fallback is same-server and must also be a PDF', () async {
    status = 404;
    expect(
      await service.fetchPdf(
        'invoices/5/pdf',
        fallbackUrl: 'https://example.test/invoices/5/print',
      ),
      pdf,
    );
    expect(requests.last.uri.path, '/invoices/5/print');
  });

  test(
    'busy PDF response keeps the Arabic server message without retry',
    () async {
      status = 503;
      const message = 'الطباعة مشغولة حالياً. انتظر قليلاً ثم أعد المحاولة.';
      body = Uint8List.fromList(utf8.encode(jsonEncode({'message': message})));
      await expectLater(
        service.fetchPdf('invoices/5/pdf'),
        throwsA(
          isA<ServerFailure>().having((e) => e.message, 'message', message),
        ),
      );
      expect(requests, hasLength(1));
    },
  );

  test('rejects duplicate, foreign and malformed fallback URLs', () async {
    status = 404;
    for (final fallback in [
      'https://example.test/api/invoices/5/pdf',
      'https://other.test/invoices/5/print',
      'http://[',
    ]) {
      requests.clear();
      await expectLater(
        service.fetchPdf('invoices/5/pdf', fallbackUrl: fallback),
        throwsA(isA<NotFoundFailure>()),
      );
      expect(requests, hasLength(1));
    }
  });

  test('Windows uses modern dialog and fixed A5 landscape', () async {
    debugDefaultTargetPlatformOverride = TargetPlatform.windows;
    expect(
      await PdfPrintService.printDocument(pdf, name: 'invoice-5.pdf'),
      isTrue,
    );
    expect(printArgs['windowsModernDialog'], isTrue);
    expect(printArgs['name'], 'invoice-5.pdf');
    expect(printArgs['width'], PdfPageFormat.a5.landscape.width);
    expect(printArgs['height'], PdfPageFormat.a5.landscape.height);
    expect(printArgs['dynamic'], isFalse);
  });

  test('cancelling releases the action and lets the next print run', () async {
    completed = false;
    expect(await PdfPrintService.printDocument(pdf), isFalse);
    completed = true;
    await service.printBytes(pdf);
    expect(prints, 2);
  });

  test('report printing preserves its A4 page format', () async {
    await PdfPrintService.printDocument(pdf, format: PdfPageFormat.a4);
    expect(printArgs['width'], PdfPageFormat.a4.width);
    expect(printArgs['height'], PdfPageFormat.a4.height);
  });

  test(
    'native failure becomes an actionable Arabic error without retry',
    () async {
      nativeError = true;
      await expectLater(
        service.printBytes(pdf),
        throwsA(
          isA<UnexpectedFailure>().having(
            (e) => e.message,
            'message',
            PrintingStrings.failed,
          ),
        ),
      );
      expect(prints, 1);
    },
  );
}
