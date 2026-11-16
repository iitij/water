import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../tariffs/domain/repositories/tariffs_repository.dart';
import '../../data/datasources/invoices_remote_datasource.dart';
import '../../data/datasources/references_remote_datasource.dart';
import '../../data/models/invoice_model.dart';
import '../../data/models/invoice_query.dart';
import '../../domain/accounting/invoice_accounting.dart';
import '../../domain/repositories/invoices_repository.dart';
import 'invoice_generation_state.dart';

/// يدير نوافذ إصدار الفواتير لإصدار الفواتير (`SCR-16`) بنفس تسلسل منطق Filament
/// `InvoiceResource::generate_invoice` ثم يستدعي
/// `InvoiceService` على الخادم عبر مسارات الفوترة.
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
      final meters = await _references.metersOf(subscriber.id);

      // منطق Filament: إذا كان للمشترك عداد واحد فقط يُختار تلقائياً.
      final autoMeter = meters.length == 1 ? meters.single : state.selectedMeter;

      emit(state.copyWith(
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
