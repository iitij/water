import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/database/app_database.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('استعادة النسخة لا تستبدل طابور الجهاز الجاري', () async {
    final database = AppDatabase.inMemory();
    addTearDown(database.closeDatabase);

    await database.enqueueAction(
      id: 'local-action',
      type: 'reading.create',
      payloadJson: '{}',
      state: 'needs_attention',
    );

    await expectLater(
      database.importFieldData(<String, dynamic>{'squares': <Object>[]}),
      throwsA(
        isA<StateError>().having(
          (error) => error.message,
          'message',
          contains('عمل ميداني غير مرسل'),
        ),
      ),
    );
    expect(await database.getSyncAction('local-action'), isNotNull);
  });

  test('استعادة النسخة لا تمحو قراءة محلية حتى لو غاب صف الطابور', () async {
    final database = AppDatabase.inMemory();
    addTearDown(database.closeDatabase);

    await database.saveOfflineReading(
      localId: 'local-reading',
      actionId: 'local-reading',
      reading: <String, dynamic>{'meter_id': 1, 'current_reading': 45},
    );
    await database.removeSyncAction('local-reading');

    await expectLater(
      database.importFieldData(<String, dynamic>{'readings': <Object>[]}),
      throwsA(isA<StateError>()),
    );
    final data = await database.exportFieldData();
    expect(data['readings'], hasLength(1));
  });
}
