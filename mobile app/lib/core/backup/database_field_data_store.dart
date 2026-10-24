import '../database/app_database.dart';
import 'local_backup.dart';

/// يربط خدمة النسخ الاحتياطي بقاعدة الميدان (Drift/SQLite).
///
/// الفصل مقصود: الخدمة نفسها لا تعرف Drift، فتُختبر بخرائط في الذاكرة.
class DatabaseFieldDataStore implements FieldDataStore {
  const DatabaseFieldDataStore(this._database);

  final AppDatabase _database;

  @override
  Future<Map<String, dynamic>> exportFieldData() => _database.exportFieldData();

  @override
  Future<void> importFieldData(Map<String, dynamic> data, {bool pendingActions = true}) =>
      _database.importFieldData(data, pendingActions: pendingActions);
}
