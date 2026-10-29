import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:water_project/core/config/app_config.dart';
import 'package:water_project/core/database/app_database.dart';
import 'package:water_project/core/finance/finance_api_client.dart';
import 'package:water_project/core/setup/server_config_sheet.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('ServerConnectionService.validateInput', () {
    test('accepts LAN, emulator, and HTTPS hosts', () {
      expect(
        ServerConnectionService.validateInput(
          'http://192.168.1.20:8000/api',
          isAndroid: true,
        ),
        isNull,
      );
      expect(
        ServerConnectionService.validateInput(
          'http://10.0.2.2:8000/api',
          isAndroid: true,
        ),
        isNull,
      );
      expect(
        ServerConnectionService.validateInput(
          'https://water.example.com/api',
          isAndroid: true,
        ),
        isNull,
      );
    });

    test('rejects localhost on Android only', () {
      expect(
        ServerConnectionService.validateInput(
          'http://localhost:8000/api',
          isAndroid: true,
        ),
        isNotNull,
      );
      expect(
        ServerConnectionService.validateInput(
          'http://localhost:8000/api',
          isAndroid: false,
        ),
        isNull,
      );
    });

    test('rejects malformed URLs and embedded credentials', () {
      for (final input in <String>[
        '',
        'localhost:8000',
        'ftp://server/api',
        'https://',
        'https://user:password@server/api',
        'https://server/api?token=x',
        'https://server/api#fragment',
      ]) {
        expect(
          ServerConnectionService.validateInput(input, isAndroid: false),
          isNotNull,
          reason: input,
        );
      }
    });
  });

  group('ServerConnectionService.switchServerUrl', () {
    const original = 'https://original.example/api/';
    late AppDatabase database;
    late String previousUrl;
    late String previousFinanceUrl;

    setUp(() async {
      await GetIt.I.reset();
      previousUrl = AppConfig.apiBaseUrl;
      previousFinanceUrl = financeApiBaseUrl;
      AppConfig.configureServer(original);
      financeApiBaseUrl = original;
      SharedPreferences.setMockInitialValues(<String, Object>{
        'water.server_url': original,
        'dev5.cached': 'old server setting',
        'finance.cached': 'old finance setting',
        'theme': 'dark',
      });
      database = AppDatabase.inMemory();
      GetIt.I.registerSingleton<AppDatabase>(database);
      await database.cacheSquares(<Map<String, dynamic>>[
        <String, dynamic>{'id': 7, 'name': 'المربع السابق'},
      ]);
    });

    tearDown(() async {
      await GetIt.I.reset();
      await database.closeDatabase();
      AppConfig.configureServer(previousUrl);
      financeApiBaseUrl = previousFinanceUrl;
    });

    test('persists the developer URL under the existing startup key', () async {
      final updated = await ServerConnectionService.switchServerUrl(
        ' http://192.168.50.4:8000/api/// ',
        isAndroid: true,
      );

      expect(updated, 'http://192.168.50.4:8000/api/');
      expect(AppConfig.apiBaseUrl, updated);
      expect(financeApiBaseUrl, updated);
      final preferences = await SharedPreferences.getInstance();
      await preferences.reload();
      expect(preferences.getString('water.server_url'), updated);
      expect(preferences.containsKey('dev5.cached'), isFalse);
      expect(preferences.containsKey('finance.cached'), isFalse);
      expect(preferences.getString('theme'), 'dark');
      expect(await database.getCachedSquares(), isEmpty);

      AppConfig.configureServer(original);
      AppConfig.configureStartupServer(
        preferences.getString(AppConfig.serverUrlPreferenceKey),
      );
      expect(AppConfig.apiBaseUrl, updated);
    });

    test(
      'keeps the current server and cached data while actions are pending',
      () async {
        await database.enqueueAction(
          id: 'pending-payment',
          type: 'payment.create',
          payloadJson: '{"amount":1250}',
        );

        await expectLater(
          ServerConnectionService.switchServerUrl(
            'http://192.168.50.4:8000/api',
            isAndroid: true,
          ),
          throwsStateError,
        );

        expect(AppConfig.apiBaseUrl, original);
        expect(financeApiBaseUrl, original);
        final preferences = await SharedPreferences.getInstance();
        expect(preferences.getString('water.server_url'), original);
        expect(preferences.getString('dev5.cached'), isNotNull);
        expect(await database.getPendingActionCount(), 1);
        expect(await database.getSyncAction('pending-payment'), isNotNull);
        expect(await database.getCachedSquares(), hasLength(1));
      },
    );
  });
}
