import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/error/failures.dart';
import 'package:water_project/features/cash_sessions/data/cash_session_api.dart';
import 'package:water_project/features/cash_sessions/domain/cash_session.dart';
import 'package:water_project/features/cash_sessions/services/cash_session_service.dart';

class _FakeApi extends CashSessionApi {
  _FakeApi() : super(Dio());

  CashSession? currentValue;
  Object? currentError;
  int currentCalls = 0;
  CashSession? openValue;
  CashSession? closeValue;

  @override
  Future<CashSession?> current() async {
    currentCalls++;
    final error = currentError;
    if (error != null) throw error;
    return currentValue;
  }

  @override
  Future<CashSession> open({
    double openingAmount = 0,
    int? squareId,
    String? notes,
  }) async {
    final session = openValue ?? _session(id: 1, state: CashSessionState.open);
    currentValue = session;
    return session;
  }

  @override
  Future<CashSession> close(int id, {required double countedAmount, String? notes}) async =>
      closeValue ?? _session(id: id, state: CashSessionState.closed);
}

CashSession _session({required int id, required CashSessionState state}) => CashSession(
      id: id,
      state: state,
      sessionDate: DateTime.utc(2026, 10, 24),
      openingAmount: 0,
      expectedAmount: 500,
      countedAmount: state == CashSessionState.open ? null : 500,
      varianceAmount: state == CashSessionState.open ? null : 0,
      receipts: 2,
    );

void main() {
  group('CashSessionService', () {
    test('before any load it blocks collection and reports not loaded', () {
      final service = CashSessionService(api: _FakeApi());

      expect(service.hasLoaded, isFalse);
      expect(service.isOpen, isFalse);
      expect(service.canCollect, isFalse);
    });

    test('refresh stores the open session and allows collection', () async {
      final api = _FakeApi()..currentValue = _session(id: 7, state: CashSessionState.open);
      final service = CashSessionService(api: api);

      final session = await service.refresh();

      expect(session?.id, 7);
      expect(service.hasLoaded, isTrue);
      expect(service.canCollect, isTrue);
      expect(service.lastError, isNull);
    });

    test('closing the session clears it so the next collection needs a new one', () async {
      final api = _FakeApi()..currentValue = _session(id: 2, state: CashSessionState.open);
      final service = CashSessionService(api: api);
      await service.refresh();

      final closed = await service.close(countedAmount: 500);

      expect(closed.state, CashSessionState.closed);
      expect(service.current, isNull);
      expect(service.canCollect, isFalse);
      expect(service.hasLoaded, isTrue);
    });

    test('network failure keeps the last known session', () async {
      final api = _FakeApi()..currentValue = _session(id: 3, state: CashSessionState.open);
      final service = CashSessionService(api: api);
      await service.refresh();

      api.currentError = const NetworkFailure();
      await service.refresh();

      expect(service.current?.id, 3);
      expect(service.lastError, isA<NetworkFailure>());
      expect(service.canCollect, isTrue);
    });

    test('unauthorized response clears the cached session', () async {
      final api = _FakeApi()..currentValue = _session(id: 4, state: CashSessionState.open);
      final service = CashSessionService(api: api);
      await service.refresh();

      api.currentError = const ForbiddenFailure();
      await service.refresh();

      expect(service.current, isNull);
      expect(service.lastError, isA<ForbiddenFailure>());
    });

    test('ensureCanCollect throws a typed exception for a closed session', () async {
      final api = _FakeApi()..currentValue = _session(id: 5, state: CashSessionState.closed);
      final service = CashSessionService(api: api);
      await service.refresh();

      expect(service.canCollect, isFalse);
      expect(
        service.ensureCanCollect,
        throwsA(isA<CashSessionRequiredException>()),
      );
    });

    test('reset forgets the session so another user cannot inherit it', () async {
      final api = _FakeApi()..currentValue = _session(id: 6, state: CashSessionState.open);
      final service = CashSessionService(api: api);
      await service.refresh();

      service.reset();

      expect(service.current, isNull);
      expect(service.hasLoaded, isFalse);
    });
  });
}
