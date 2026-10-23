import '../../../service_requests/domain/entities/service_request.dart';
import '../../../service_requests/domain/repositories/service_requests_repository.dart';
import '../../../squares/domain/entities/square.dart';
import '../../../squares/domain/repositories/squares_repository.dart';
import '../../../subscription_fees/domain/entities/subscription_fee.dart';
import '../../../subscription_fees/domain/repositories/subscription_fees_repository.dart';
import '../../../transactions/domain/entities/ledger_transaction.dart';
import '../../../transactions/domain/repositories/transactions_repository.dart';
import '../../domain/entities/subscriber.dart';
import '../../domain/repositories/subscribers_repository.dart';

/// طبقة بيانات تجريبية (Mock — عقد اليوم الأول في الخطة 5.3) لعرض وحدات فؤاد دون خادم.
/// تُستبدل تلقائياً بالمستودعات الحقيقية عند --dart-define=DEMO=false.
final _squares = <Square>[
  const Square(id: 1, name: 'مربع 1 - الحارة الشمالية', description: 'شمال المدينة', subscribersCount: 42),
  const Square(id: 2, name: 'مربع 2 - الحارة الجنوبية', description: 'جنوب المدينة', subscribersCount: 35),
  const Square(id: 4, name: 'مربع 4 - حارة السوق', description: 'بجوار السوق المركزي', subscribersCount: 57),
];

final _subscribers = <Subscriber>[
  const Subscriber(id: 1, name: 'أحمد محمد الحداد', phone: '+967 771 234 567', squareId: 4, squareName: 'مربع 4 - حارة السوق', metersCount: 2, creditBalance: 0, debitBalance: 12500, generalBalance: -3000, meterNumber: 'W-1001'),
  const Subscriber(id: 2, name: 'فاطمة علي السقاف', phone: '+967 733 987 654', squareId: 1, squareName: 'مربع 1 - الحارة الشمالية', metersCount: 1, creditBalance: 5000, debitBalance: 0, generalBalance: 5000, meterNumber: 'W-1002'),
  const Subscriber(id: 3, name: 'خالد عمر باوزير', phone: '+967 700 111 222', squareId: 4, squareName: 'مربع 4 - حارة السوق', status: SubscriberStatus.suspended, metersCount: 1, creditBalance: 0, debitBalance: 48000, generalBalance: -48000, meterNumber: 'W-1003'),
  const Subscriber(id: 4, name: 'مريم سالم العولقي', phone: '+967 715 555 333', squareId: 2, squareName: 'مربع 2 - الحارة الجنوبية', metersCount: 3, creditBalance: 2200, debitBalance: 1800, generalBalance: 400, meterNumber: 'W-1004'),
];

class FakeSquaresRepository implements SquaresRepository {
  @override
  Future<List<Square>> getSquares({String? search}) async => _squares
      .where((s) => search == null || search.isEmpty || s.name.contains(search))
      .toList();
  @override
  Future<({Square square, List<Subscriber> subscribers})> getSquare(int id) async {
    final sq = _squares.firstWhere((s) => s.id == id, orElse: () => _squares.first);
    return (square: sq, subscribers: _subscribers.where((s) => s.squareId == id).toList());
  }

  @override
  Future<Square> createSquare(String name, String? description) async =>
      Square(id: _squares.length + 10, name: name, description: description);
  @override
  Future<Square> updateSquare(Square square) async => square;
  @override
  Future<void> deleteSquare(int id) async {}
  @override
  Future<int> bulkDelete(List<int> ids) async => ids.length;
}

class FakeSubscribersRepository implements SubscribersRepository {
  @override
  Future<List<Subscriber>> getSubscribers({String? query, int? squareId, SubscriberStatus? status, int? perPage}) async =>
      _subscribers.where((s) =>
          (query == null || query.isEmpty || s.name.contains(query) || (s.phone ?? '').contains(query)) &&
          (squareId == null || s.squareId == squareId) &&
          (status == null || s.status == status)).toList();

  @override
  Future<Subscriber360> getSubscriber360(int id) async {
    final sub = _subscribers.firstWhere((s) => s.id == id, orElse: () => _subscribers.first);
    return Subscriber360(
      subscriber: sub,
      summary: FinancialSummary(
        generalBalance: sub.generalBalance,
        totalCredit: sub.creditBalance,
        totalDebit: sub.debitBalance,
        remainingSubscriptionFee: 15000,
        subscriptionFeeStatus: 'uninvoiced',
        meterBalances: const [
          MeterBalance(meterId: 11, uniqueCode: 'W-1001', tenantName: 'محل البقالة', credit: 0, debit: 9500, readingsCount: 12),
          MeterBalance(meterId: 12, uniqueCode: 'W-1005', tenantName: null, credit: 2000, debit: 0, readingsCount: 8),
        ],
      ),
      invoices: const [
        {'bill_number': 'INV-202610-0001', 'bill_date': '2026-10-01', 'total_amount': 9500, 'amount_paid': 0, 'status': 'overdue'},
        {'bill_number': 'INV-202609-0007', 'bill_date': '2026-09-01', 'total_amount': 8200, 'amount_paid': 8200, 'status': 'paid'},
      ],
      payments: const [
        {'receipt_number': 'REC-202609-0012', 'amount': 8200, 'payment_date': '2026-09-05', 'payment_method': 'cash'},
      ],
    );
  }

  @override
  Future<Subscriber> createSubscriber(Subscriber draft) async => draft;
  @override
  Future<Subscriber> updateSubscriber(Subscriber s) async => s;
  @override
  Future<void> deleteSubscriber(int id) async {}
  @override
  Future<List<LedgerEntry>> getStatement(int subscriberId, {PartyFilter party = const PartyAll()}) async {
    const all = <LedgerEntry>[
      LedgerEntry(id: 3, type: 'payment', amount: 8200, description: 'سند قبض REC-202609-0012', createdAt: '2026-09-05 10:30', runningBalance: -1300),
      LedgerEntry(id: 2, type: 'invoice', amount: -9500, meterId: 11, party: 'عداد رقم: 11 - محل البقالة', description: 'فاتورة INV-202610-0001', createdAt: '2026-10-01 09:00', runningBalance: -9500),
      LedgerEntry(id: 1, type: 'invoice', amount: -3000, description: 'فاتورة اشتراك INV-202608-0003', createdAt: '2026-08-15 11:00', runningBalance: -3000),
    ];
    return switch (party) {
      PartyAll() => all,
      PartyGeneral() => all.where((e) => e.meterId == null).toList(),
      PartyMeter(meter: final m) => all.where((e) => e.meterId == m.meterId).toList(),
    };
  }

  @override
  Future<List<Square>> getSquaresLookup() async => _squares;
  @override
  Future<Map<String, dynamic>> collectPayment(int subscriberId, {required double amount, int? invoiceId, String method = 'cash', String? reason}) async =>
      {'message': 'تم تحصيل الدفعة بنجاح (وضع تجريبي)', 'payment': {'receipt_number': 'REC-DEMO-0001', 'amount': amount}};
  @override
  Future<double?> lastTankerAmount(int subscriberId) async => 7000;
}

class FakeSubscriptionFeesRepository implements SubscriptionFeesRepository {
  static const _fees = <SubscriptionFee>[
    SubscriptionFee(id: 1, subscriberId: 1, subscriberName: 'أحمد محمد الحداد', meterId: 11, meterCode: 'W-1001', amount: 15000, status: FeeStatus.uninvoiced, createdAt: '2026-08-01'),
    SubscriptionFee(id: 2, subscriberId: 2, subscriberName: 'فاطمة علي السقاف', amount: 15000, status: FeeStatus.paid, invoiceId: 5, invoiceNumber: 'INV-202608-0003', createdAt: '2026-08-03'),
  ];
  @override
  Future<FeesPage> getFees({FeeStatus? status, int? subscriberId}) async {
    final list = _fees.where((f) => (status == null || f.status == status) && (subscriberId == null || f.subscriberId == subscriberId)).toList();
    final total = list.fold<double>(0, (a, f) => a + f.amount);
    final invoiced = list.where((f) => f.status != FeeStatus.uninvoiced).fold<double>(0, (a, f) => a + f.amount);
    return FeesPage(fees: list, totalSum: total, invoicedSum: invoiced, remainingSum: total - invoiced);
  }

  @override
  Future<SubscriptionFee> createFee({required int subscriberId, int? meterId, required double amount, String? notes}) async =>
      SubscriptionFee(id: 99, subscriberId: subscriberId, meterId: meterId, amount: amount, notes: notes);
  @override
  Future<SubscriptionFee> updateFee(SubscriptionFee fee) async => fee;
  @override
  Future<void> deleteFee(int id) async {}
  @override
  Future<Map<String, dynamic>> invoiceFee(int id) async => {'message': 'تم إصدار الفاتورة (وضع تجريبي)', 'invoice': {'bill_number': 'INV-DEMO-001'}};
}

class FakeServiceRequestsRepository implements ServiceRequestsRepository {
  static const _reqs = <ServiceRequest>[
    ServiceRequest(id: 1, subscriberId: 1, subscriberName: 'أحمد محمد الحداد', amount: 7000, serviceDate: '2026-10-20', status: TankerStatus.pending, notes: 'التوصيل قبل المغرب'),
    ServiceRequest(id: 2, subscriberId: 3, subscriberName: 'خالد عمر باوزير', amount: 5000, serviceDate: '2026-10-18', status: TankerStatus.completed),
    ServiceRequest(id: 3, subscriberId: 2, subscriberName: 'فاطمة علي السقاف', amount: 5000, serviceDate: '2026-10-10', status: TankerStatus.paid, invoiceId: 9, invoiceNumber: 'INV-202610-0009'),
  ];
  @override
  Future<TankerPage> getRequests({TankerStatus? status, int? subscriberId, String? from, String? until}) async {
    final list = _reqs.where((r) =>
        (status == null || r.status == status) &&
        (subscriberId == null || r.subscriberId == subscriberId) &&
        (from == null || r.serviceDate.compareTo(from) >= 0) &&
        (until == null || r.serviceDate.compareTo(until) <= 0)).toList();
    return TankerPage(requests: list, totalSum: list.fold(0, (a, r) => a + r.amount));
  }

  @override
  Future<ServiceRequest> createRequest({required int subscriberId, required double amount, required String serviceDate, TankerStatus status = TankerStatus.completed, String? notes}) async =>
      ServiceRequest(id: 99, subscriberId: subscriberId, amount: amount, serviceDate: serviceDate, status: status, notes: notes);
  @override
  Future<ServiceRequest> updateRequest(ServiceRequest r) async => r;
  @override
  Future<void> deleteRequest(int id) async {}
  @override
  Future<ServiceRequest> markCompleted(int id) async =>
      ServiceRequest(id: id, subscriberId: 1, amount: 7000, serviceDate: '2026-10-20', status: TankerStatus.completed);
  @override
  Future<Map<String, dynamic>> invoiceRequest(int id) async => {'message': 'تم إصدار الفاتورة (وضع تجريبي)', 'invoice': {'bill_number': 'INV-DEMO-002'}};
  @override
  Future<double?> lastAmountFor(int subscriberId) async {
    final matches = _reqs.where((r) => r.subscriberId == subscriberId);
    return matches.isEmpty ? null : matches.first.amount;
  }
}

class FakeTransactionsRepository implements TransactionsRepository {
  @override
  Future<List<LedgerTransaction>> getTransactions({String? search, int? subscriberId, String? meterId, String? type, String? fromDate, String? toDate}) async {
    const all = <LedgerTransaction>[
      LedgerTransaction(id: 3, type: 'payment', amount: 8200, subscriberId: 2, subscriberName: 'فاطمة علي السقاف', description: 'سند قبض REC-202609-0012', createdAt: '2026-09-05 10:30'),
      LedgerTransaction(id: 2, type: 'invoice', amount: -9500, subscriberId: 1, subscriberName: 'أحمد محمد الحداد', meterId: 11, description: 'فاتورة INV-202610-0001', createdAt: '2026-10-01 09:00'),
      LedgerTransaction(id: 1, type: 'adjustment', amount: 500, subscriberId: 4, subscriberName: 'مريم سالم العولقي', description: 'تسوية رصيد افتتاحي', createdAt: '2026-08-01 08:00'),
    ];
    return all.where((t) =>
        (type == null || t.type == type) &&
        (subscriberId == null || t.subscriberId == subscriberId) &&
        (meterId == null || (meterId == 'general' ? t.meterId == null : t.meterId?.toString() == meterId)) &&
        (search == null || search.isEmpty || (t.subscriberName ?? '').contains(search) || (t.description ?? '').contains(search))).toList();
  }
}
