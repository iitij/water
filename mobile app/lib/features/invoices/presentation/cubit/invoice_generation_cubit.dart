import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../tariffs/domain/repositories/tariffs_repository.dart';
import '../../data/datasources/invoices_remote_datasource.dart';
import '../../data/datasources/references_remote_datasource.dart';
import '../../data/models/invoice_model.dart';
import '../../data/models/invoice_query.dart';
import '../../domain/accounting/invoice_accounting.dart';
import '../../domain/repositories/invoices_repository.dart';
import 'invoice_generation_state.dart';

/// يدير النوافذ الثلاث لإصدار الفواتير (`SCR-16`) بنفس تسلسل منطق Filament
/// `InvoiceResource::generate_invoice` (مفاتيح قسط الاشتراك والوايت) ثم يستدعي
/// `InvoiceService` على الخادم عبر المسارات الثلاثة.
class InvoiceGenerationCubit extends Cubit<InvoiceGenerationState> {
  InvoiceGenerationCubit({
    required InvoicesRepository invoicesRepository,
    required InvoicesRemoteDataSource invoicesSource,
    required ReferencesRemoteDataSource references,
    required TariffsRepository tariffsRepository,
  })  : _repository = invoicesRepository,
        _invoicesSource = invoicesSource,
        _references = references,
        _tariffs = tariffsRepository,
        super(const InvoiceGenerationState());

  final InvoicesRepository _repository;
  final InvoicesRemoteDataSource _invoicesSource;
  final ReferencesRemoteDataSource _references;
  final TariffsRepository _tariffs;

  /// يحمّل المربعات/المشتركين/السعر النشط — يُستدعى عند فتح النافذة.
  Future<void> bootstrap({String? preselectedSubscriberId}) async {
    emit(state.copyWith(loadingReferences: true, clearError: true));
    try {
      final results = await Future.wait(<Future<Object>>[
        _references.squares(),
        _references.subscribers(status: 'active', limit: 500),
        _tariffs.activePrice(),
      ]);

      final subscribers = results[1] as List<SubscriberOption>;
      final unitPrice = results[2] as double;

      SubscriberOption? preselected;
      if (preselectedSubscriberId != null) {
        final id = int.tryParse(preselectedSubscriberId);
        final matches = subscribers.where((s) => s.id == id).toList();
        preselected = matches.isEmpty ? null : matches.first;
      }

      emit(state.copyWith(
        loadingReferences: false,
        squares: results[0] as List<SquareOption>,
        subscribers: subscribers,
        unitPrice: unitPrice,
        selectedSubscriber: preselected,
      ));

      if (preselected != null) {
        await _hydrateSubscriber(preselected);
      }
    } catch (error) {
      emit(state.copyWith(loadingReferences: false, errorMessage: '$error'));
    }
  }

  Future<void> searchSubscribers(String query) async {
    try {
      final results = await Future.wait(<Future<Object>>[
        _references.subscribers(search: query, status: 'active', limit: 100),
        _tariffs.activePrice(),
      ]);
      emit(state.copyWith(
        subscribers: results[0] as List<SubscriberOption>,
        unitPrice: results[1] as double,
      ));
    } catch (error) {
      emit(state.copyWith(errorMessage: '$error'));
    }
  }

  /// اختيار المشترك/العداد — يعيد حساب المفاتيح الأربعة (كما في `afterStateUpdated`).
  /// يفرّغ الاختيار الحالي (يُستخدم من شارة «المشترك المحدد»).
  void clearSubscriber() {
    emit(state.copyWith(clearSubscriber: true, clearMeter: true));
  }

  Future<void> selectSubscriber(SubscriberOption subscriber) async {
    emit(state.copyWith(selectedSubscriber: subscriber, clearMeter: true));
    await _hydrateSubscriber(subscriber);
  }

  Future<void> _hydrateSubscriber(SubscriberOption subscriber) async {
    try {
      final results = await Future.wait(<Future<Object>>[
        _references.metersOf(subscriber.id),
        _references.uninvoicedSubscriptionFees(subscriber.id),
        _references.pendingTankerAmount(subscriber.id),
      ]);

      final meters = results[0] as List<MeterOption>;
      final subscriptionSum = results[1] as double;
      final tankerSum = results[2] as double;

      // منطق Filament: إذا كان للمشترك عداد واحد فقط يُختار تلقائياً.
      final autoMeter = meters.length == 1 ? meters.single : state.selectedMeter;

      emit(state.copyWith(
        hasUnpaidSubscriptionFee: subscriptionSum > 0,
        hasSubscriptionFee: subscriptionSum > 0 ? state.hasSubscriptionFee : false,
        subscriptionInstallment: subscriptionSum > 0 ? subscriptionSum : 0,
        hasPendingTanker: tankerSum > 0,
        hasTankerFee: tankerSum > 0 ? state.hasTankerFee : false,
        tankerInstallment: tankerSum > 0 ? tankerSum : 0,
        selectedMeter: autoMeter,
      ));

      await _recomputeAccountFacts();
    } catch (error) {
      emit(state.copyWith(errorMessage: '$error'));
    }
  }

  Future<void> selectMeter(MeterOption meter) async {
    emit(state.copyWith(selectedMeter: meter));
    await _recomputeAccountFacts();
  }

  void toggleSubscriptionFee(bool enabled) {
    emit(state.copyWith(
      hasSubscriptionFee: enabled,
      subscriptionInstallment: enabled
          ? (state.subscriptionInstallment > 0
              ? state.subscriptionInstallment
              : 0)
          : 0,
    ));
  }

  void toggleTankerFee(bool enabled) {
    emit(state.copyWith(
      hasTankerFee: enabled,
      tankerInstallment: enabled ? state.tankerInstallment : 0,
    ));
  }

  void setSubscriptionInstallment(double value) =>
      emit(state.copyWith(subscriptionInstallment: value));

  void setTankerInstallment(double value) =>
      emit(state.copyWith(tankerInstallment: value));

  void setIncludeReadings(bool value) => emit(state.copyWith(includeReadings: value));

  void setNotes(String? value) => emit(state.copyWith(notes: value));

  /// يقدّر `previous_balance` والرصيد الدائن من فواتير الحساب المعزول المفتوحة،
  /// تماماً كما تفعل `InvoiceService` (السطر 62-74) قبل الإنشاء.
  Future<void> _recomputeAccountFacts() async {
    final subscriber = state.selectedSubscriber;
    if (subscriber == null) return;

    try {
      final invoices = await _invoicesSource.list(
        InvoiceQuery(subscriberId: subscriber.id, limit: 300),
      );

      final meterId = state.selectedMeter?.id;
      final open = InvoiceAccounting.openInvoicesForAccount(
        invoices: invoices,
        subscriberId: subscriber.id,
        meterId: meterId,
      );

      final previousBalance = open.fold<double>(
        0,
        (sum, invoice) => sum + InvoiceAccounting.ownRemaining(invoice),
      );

      // العزل: فاتورة العداد تخصم من رصيد العداد، وغيرُها من الرصيد العام.
      final credit = meterId == null
          ? subscriber.availableGeneralCredit
          : (state.selectedMeter?.credit ?? 0);

      emit(state.copyWith(
        previousBalance: previousBalance,
        availableCredit: credit > 0 ? credit : 0,
      ));
    } catch (_) {
      // أوفلاين: نبقي المعاينة على آخر قيمة معروفة.
    }
  }

  // ─── الإصدار ────────────────────────────────────────────────────────────
  Future<InvoiceModel?> submitMeterInvoice() async {
    final subscriber = state.selectedSubscriber;
    final meter = state.selectedMeter;
    if (subscriber == null || meter == null) {
      emit(state.copyWith(errorMessage: 'اختر المشترك والعداد معاً.'));
      return null;
    }

    emit(state.copyWith(submitting: true, clearError: true));
    try {
      final invoice = await _repository.generateMeter(
        GenerateMeterInvoiceRequest(
          subscriberId: subscriber.id,
          meterId: meter.id,
          includeReadings: state.includeReadings,
          hasSubscriptionFee: state.hasSubscriptionFee,
          subscriptionInstallment: state.subscriptionInstallment,
          hasTankerFee: state.hasTankerFee,
          tankerInstallment: state.tankerInstallment,
          notes: state.notes,
        ),
      );
      emit(state.copyWith(submitting: false, createdInvoice: invoice));
      return invoice;
    } catch (error) {
      emit(state.copyWith(submitting: false, errorMessage: _read(error)));
      return null;
    }
  }

  Future<InvoiceModel?> submitSubscriptionInvoice() async {
    final subscriber = state.selectedSubscriber;
    if (subscriber == null) {
      emit(state.copyWith(errorMessage: 'اختر المشترك أولاً.'));
      return null;
    }
    emit(state.copyWith(submitting: true, clearError: true));
    try {
      final invoice = await _repository.generateSubscription(
        GenerateSubscriptionInvoiceRequest(
          subscriberId: subscriber.id,
          subscriptionInstallment: state.subscriptionInstallment,
          notes: state.notes,
        ),
      );
      emit(state.copyWith(submitting: false, createdInvoice: invoice));
      return invoice;
    } catch (error) {
      emit(state.copyWith(submitting: false, errorMessage: _read(error)));
      return null;
    }
  }

  Future<InvoiceModel?> submitTankerInvoice() async {
    final subscriber = state.selectedSubscriber;
    if (subscriber == null) {
      emit(state.copyWith(errorMessage: 'اختر المشترك أولاً.'));
      return null;
    }
    emit(state.copyWith(submitting: true, clearError: true));
    try {
      final invoice = await _repository.generateTanker(
        GenerateTankerInvoiceRequest(
          subscriberId: subscriber.id,
          tankerInstallment: state.tankerInstallment,
          notes: state.notes,
        ),
      );
      emit(state.copyWith(submitting: false, createdInvoice: invoice));
      return invoice;
    } catch (error) {
      emit(state.copyWith(submitting: false, errorMessage: _read(error)));
      return null;
    }
  }

  static String _read(Object error) {
    final text = error.toString();
    final match = RegExp(r'''message["']?\s*[:=]\s*["']([^"']+)''').firstMatch(text);
    return match?.group(1) ?? text.replaceFirst('Exception: ', '');
  }
}
