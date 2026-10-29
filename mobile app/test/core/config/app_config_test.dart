import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/config/app_config.dart';

void main() {
  test('normalizes local and hosted API URLs', () {
    expect(
      AppConfig.normalizeApiBaseUrl(' http://localhost:8000 '),
      'http://localhost:8000/api/',
    );
    expect(
      AppConfig.normalizeApiBaseUrl('http://192.168.1.20:8000/api///'),
      'http://192.168.1.20:8000/api/',
    );
    expect(
      AppConfig.normalizeApiBaseUrl('https://water.example/sub/api'),
      'https://water.example/sub/api/',
    );
    expect(
      AppConfig.normalizeApiBaseUrl('https://34.57.211.224/'),
      'https://34.57.211.224/api/',
    );
  });

  test('rejects malformed URLs and embedded credentials', () {
    for (final url in [
      '',
      'localhost:8000',
      'ftp://server/api',
      'https://',
      'https://user:password@server/api',
      'https://server/api?token=x',
      'https://server/api#fragment',
    ]) {
      expect(
        () => AppConfig.normalizeApiBaseUrl(url),
        throwsFormatException,
        reason: url,
      );
    }
  });

  group('startup server selection', () {
    test('uses the same preference key as existing developer settings', () {
      expect(AppConfig.serverUrlPreferenceKey, 'water.server_url');
    });

    test('defaults to the hosted HTTPS server on every platform', () {
      for (final platform in TargetPlatform.values) {
        expect(
          AppConfig.defaultApiBaseUrlForPlatform(platform),
          'https://34.57.211.224/',
        );
      }
    });

    test(
      'missing or blank preferences use the normalized platform default',
      () {
        for (final stored in <String?>[null, '', '  ']) {
          expect(
            AppConfig.resolveStartupApiBaseUrl(
              stored,
              platform: TargetPlatform.windows,
            ),
            'https://34.57.211.224/api/',
          );
          expect(
            AppConfig.resolveStartupApiBaseUrl(
              stored,
              platform: TargetPlatform.android,
            ),
            'https://34.57.211.224/api/',
          );
        }
      },
    );

    test('keeps the developer-saved URL on Windows and physical Android', () {
      for (final platform in <TargetPlatform>[
        TargetPlatform.windows,
        TargetPlatform.android,
      ]) {
        expect(
          AppConfig.resolveStartupApiBaseUrl(
            ' http://192.168.50.4:8000/api/// ',
            platform: platform,
          ),
          'http://192.168.50.4:8000/api/',
        );
        expect(
          AppConfig.resolveStartupApiBaseUrl(
            'https://hosted.example/customer/api',
            platform: platform,
          ),
          'https://hosted.example/customer/api/',
        );
      }
    });

    test(
      'invalid saved selection stays disconnected instead of switching backends',
      () {
        for (final saved in <String>[
          'not a URL',
          'ftp://192.168.1.20/api',
          'https://user:password@server/api',
          'https://server/api?token=x',
        ]) {
          expect(
            AppConfig.resolveStartupApiBaseUrl(
              saved,
              platform: TargetPlatform.windows,
            ),
            isEmpty,
            reason: saved,
          );
        }
      },
    );

    test(
      'a disconnected startup can be repaired with the existing server editor',
      () {
        final previous = AppConfig.apiBaseUrl;
        addTearDown(() => AppConfig.configureStartupServer(previous));

        AppConfig.configureStartupServer('invalid saved selection');
        expect(AppConfig.apiBaseUrl, isEmpty);
        expect(AppConfig.currentApiBaseUrl, anyOf(isNull, isEmpty));

        AppConfig.configureServer('http://192.168.50.4:8000/api');
        expect(AppConfig.apiBaseUrl, 'http://192.168.50.4:8000/api/');
        expect(AppConfig.currentApiBaseUrl, 'http://192.168.50.4:8000/api/');
      },
    );
  });

  group('HTTP release policy', () {
    bool releaseAllows(String host) => AppConfig.isHttpAllowedForHost(
      host,
      releaseMode: true,
      allowInsecureHttp: false,
    );

    test(
      'allows local backend PC, emulator, and private LAN addresses in release',
      () {
        for (final host in <String>[
          'localhost',
          '127.0.0.1',
          '127.255.255.254',
          '::1',
          '10.0.2.2',
          '10.255.255.254',
          '172.16.0.1',
          '172.31.255.254',
          '192.168.0.1',
          '192.168.255.254',
        ]) {
          expect(releaseAllows(host), isTrue, reason: host);
        }
      },
    );

    test(
      'requires HTTPS for public hosts and lookalike local addresses in release',
      () {
        for (final host in <String>[
          'example.com',
          '8.8.8.8',
          '100.64.0.1',
          '169.254.1.1',
          '172.15.255.255',
          '172.32.0.1',
          '192.167.255.255',
          '192.169.0.1',
          'localhost.evil.example',
          '192.168.1.20.evil.example',
          '192.168.1.999',
          '192.168.01.20',
          '0177.0.0.1',
          '10.0.2',
          '2001:4860:4860::8888',
        ]) {
          expect(releaseAllows(host), isFalse, reason: host);
        }
      },
    );

    test('retains development and explicit insecure-build overrides', () {
      expect(
        AppConfig.isHttpAllowedForHost(
          'public.example',
          releaseMode: false,
          allowInsecureHttp: false,
        ),
        isTrue,
      );
      expect(
        AppConfig.isHttpAllowedForHost(
          'public.example',
          releaseMode: true,
          allowInsecureHttp: true,
        ),
        isTrue,
      );
    });
  });
}
