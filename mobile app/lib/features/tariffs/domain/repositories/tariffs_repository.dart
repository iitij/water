import '../../data/models/tariff_model.dart';

/// عقد مستودع التعرفة — القراءة تستخدم أيضاً في حساب «سعر الوحدة» المتوقع داخل
/// شاشة القراءة لدى Dev 2 (`consumption_fee = units × activePrice`).
abstract interface class TariffsRepository {
  Future<List<TariffModel>> list({
    TariffsTrashFilter trash = TariffsTrashFilter.without,
    String? search,
  });

  /// السعر النشط (مصدر الحقيقة: `Tariff::getActivePrice()` في الخادم).
  Future<double> activePrice();

  Future<TariffModel> create(TariffModel tariff);
  Future<TariffModel> update(TariffModel tariff);

  /// حذف ناعم (`TariffsTable::DeleteBulkAction`).
  Future<void> softDelete(int id);

  /// استعادة (`RestoreBulkAction`).
  Future<void> restore(int id);

  /// حذف نهائي (`ForceDeleteBulkAction`).
  Future<void> forceDelete(int id);

  /// استعادة جماعية — تعيد عدد ما استُعيد (فجوة v1 رقم 1).
  Future<int> bulkRestore(List<int> ids);

  /// حذف نهائي جماعي — تعيد عدد ما حُذف.
  Future<int> bulkForceDelete(List<int> ids);
}
