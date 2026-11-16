// QA regression probes for confirmed current defects. Enable explicitly with
// --dart-define=RUN_KNOWN_BUG_PROBES=true; the normal suite skips this group.
import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/finance/finance_search.dart';
import 'package:water_project/features/invoices/data/datasources/references_remote_datasource.dart';
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/invoices/domain/repositories/invoices_repository.dart';
import 'package:water_project/features/meters/data/models/meter.dart' as meters;
import 'package:water_project/features/meters/domain/meters_repository.dart';
import 'package:water_project/features/meters/presentation/cubit/meters_cubit.dart';
import 'package:water_project/features/payments/domain/repositories/payments_repository.dart';
import 'package:water_project/features/payments/presentation/cubit/collect_payment_cubit.dart';
import 'package:water_project/features/payments/presentation/cubit/quick_collector_cubit.dart';
import 'package:water_project/features/subscribers/domain/entities/subscriber.dart';
import 'package:water_project/features/subscribers/domain/repositories/subscribers_repository.dart';
import 'package:water_project/features/subscribers/presentation/cubit/subscribers_cubit.dart';

class _Payments extends Mock implements PaymentsRepository {}
class _References extends Mock implements ReferencesRemoteDataSource {}
class _Subscribers extends Mock implements SubscribersRepository {}
class _Meters extends Mock implements MetersRepository {}
class _Invoices extends Mock implements InvoicesRepository {}

InvoiceModel _invoice(int id, {int subscriberId = 1, int meterId = 10, double due = 100}) =>
    InvoiceModel(id: id, subscriberId: subscriberId, meterId: meterId,
      invoiceType: InvoiceType.meter, billNumber: 'QA-$id', totalAmount: due);

void main() {
  setUpAll(() => registerFallbackValue(const FinanceSearchQuery(rawQuery: '')));
  group('QA confirmed financial defects (opt-in regression probes)', () {
    test('selected invoice is silently replaced and request crosses meter accounts', () async {
      final payments = _Payments();
      final references = _References();
      when(() => references.dueSummary(1)).thenAnswer((_) async => const SubscriberDueSummary(subscriberId: 1));
      when(() => payments.latestUnpaidInvoice(1)).thenAnswer((_) async => _invoice(200, meterId: 20, due: 500));
      final cubit = CollectPaymentCubit(paymentsRepository: payments, references: references, meterId: 10);
      addTearDown(cubit.close);
      await cubit.openForInvoice(_invoice(100, meterId: 10, due: 100));
      final request = cubit.buildRequest();
      expect(request.invoiceId, 100, reason: 'Must preserve the invoice explicitly selected by the caller');
      expect(request.meterId, 10);
      expect(request.amount, 100);
      expect(cubit.state.dueAmount, 100);
    });

    test('switching to subscriber without invoices retains previous subscriber invoice', () async {
      final payments = _Payments();
      final references = _References();
      when(() => references.dueSummary(1)).thenAnswer((_) async => const SubscriberDueSummary(subscriberId: 1));
      when(() => references.dueSummary(2)).thenAnswer((_) async => const SubscriberDueSummary(subscriberId: 2));
      when(() => payments.latestUnpaidInvoice(1)).thenAnswer((_) async => _invoice(100));
      when(() => payments.latestUnpaidInvoice(2)).thenAnswer((_) async => null);
      final cubit = CollectPaymentCubit(paymentsRepository: payments, references: references, meterId: null);
      addTearDown(cubit.close);
      await cubit.openForSubscriber(1);
      await cubit.openForSubscriber(2);
      cubit.setAmount(50);
      final request = cubit.buildRequest();
      expect(request.subscriberId, 2);
      expect(request.invoiceId, isNull, reason: 'A new subscriber must not inherit another subscriber invoice');
      expect(request.validate(), isNull);
    });

    test('partial payment invoice remains hidden after authoritative refresh', () async {
      final invoices = _Invoices();
      final remaining = _invoice(100, due: 50).copyWith(status: InvoiceStatus.partiallyPaid);
      when(() => invoices.collectorList()).thenAnswer((_) async => [remaining]);
      final cubit = QuickCollectorCubit(invoicesRepository: invoices, references: _References());
      addTearDown(cubit.close);
      await cubit.loadUnpaid();
      cubit.markCollected(_invoice(100));
      await cubit.refreshQuietly();
      expect(cubit.state.invoices.single.netPayable, 50);
      expect(cubit.state.visibleInvoices, hasLength(1), reason: 'Partially paid invoice remains collectible');
    });

    test('refresh after collecting ignores selected square', () async {
      final invoices = _Invoices();
      when(() => invoices.collectorList(squareId: 7, search: any(named: 'search'))).thenAnswer((_) async => [_invoice(7)]);
      when(() => invoices.collectorList()).thenAnswer((_) async => [_invoice(99)]);
      final cubit = QuickCollectorCubit(invoicesRepository: invoices, references: _References());
      addTearDown(cubit.close);
      await cubit.selectSquare(7);
      expect(cubit.state.invoices.single.id, 7);
      await cubit.refreshQuietly();
      expect(cubit.state.selectedSquareId, 7);
      expect(cubit.state.invoices.single.id, 7, reason: 'Refresh must preserve the selected square filter');
    });

    test('subscriber results can be overwritten by an older slower search', () async {
      final repo = _Subscribers();
      final old = Completer<List<Subscriber>>();
      final current = Completer<List<Subscriber>>();
      when(() => repo.getSubscribers(query: 'old', squareId: null, status: null)).thenAnswer((_) => old.future);
      when(() => repo.getSubscribers(query: 'current', squareId: null, status: null)).thenAnswer((_) => current.future);
      final cubit = SubscribersCubit(repo);
      addTearDown(cubit.close);
      cubit.search('old');
      cubit.search('current');
      current.complete([const Subscriber(id: 2, name: 'current')]);
      await Future<void>.delayed(Duration.zero);
      old.complete([const Subscriber(id: 1, name: 'old')]);
      await Future<void>.delayed(Duration.zero);
      expect(cubit.state.query, 'current');
      expect(cubit.state.subscribers.single.name, 'current', reason: 'Older responses must not overwrite newer search results');
    });

    test('closing subscribers during load throws StateError', () async {
      final repo = _Subscribers();
      final response = Completer<List<Subscriber>>();
      when(() => repo.getSubscribers(query: '', squareId: null, status: null)).thenAnswer((_) => response.future);
      final cubit = SubscribersCubit(repo);
      final pending = cubit.load();
      final assertion = expectLater(pending, completes);
      await cubit.close();
      response.complete([]);
      await assertion;
    });

    test('closing meters during load throws StateError', () async {
      final repo = _Meters();
      final response = Completer<List<meters.Meter>>();
      when(() => repo.getMeters(query: '', squareId: null, status: null)).thenAnswer((_) => response.future);
      when(() => repo.getSquares()).thenAnswer((_) async => <meters.SquareOption>[]);
      final cubit = MetersCubit(repo);
      final pending = cubit.load();
      final assertion = expectLater(pending, completes);
      await cubit.close();
      response.complete([]);
      await assertion;
    });

  }, skip: !const bool.fromEnvironment('RUN_KNOWN_BUG_PROBES'));
}
