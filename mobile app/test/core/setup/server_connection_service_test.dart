import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/setup/server_config_sheet.dart';

void main() {
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
}
