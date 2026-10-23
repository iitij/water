import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/config/app_config.dart';

void main() {
  test('normalizes local and hosted API URLs', () {
    expect(AppConfig.normalizeApiBaseUrl(' http://localhost:8000 '), 'http://localhost:8000/api/');
    expect(AppConfig.normalizeApiBaseUrl('http://192.168.1.20:8000/api///'), 'http://192.168.1.20:8000/api/');
    expect(AppConfig.normalizeApiBaseUrl('https://water.example/sub/api'), 'https://water.example/sub/api/');
  });

  test('rejects malformed URLs and embedded credentials', () {
    for (final url in ['', 'localhost:8000', 'ftp://server/api', 'https://',
      'https://user:password@server/api', 'https://server/api?token=x', 'https://server/api#fragment']) {
      expect(() => AppConfig.normalizeApiBaseUrl(url), throwsFormatException, reason: url);
    }
  });
}
