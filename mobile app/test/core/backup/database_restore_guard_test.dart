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

  test('restore cannot resurrect an acknowledged UUID or erase discarded evidence', () async {
    final db = AppDatabase.inMemory();
    addTearDown(db.closeDatabase);
    await db.cacheSquares([{'id': 1, 'name': 'Original square'}]);
    await db.enqueueAction(
      id: 'confirmed', type: 'payment.create', payloadJson: '{"amount":"10.25"}',
      state: 'synced', ownerUserId: '1', ownerServer: 'https://water.test/api/',
    );
    await db.enqueueAction(
      id: 'discarded', type: 'payment.create', payloadJson: '{"amount":"5.00"}',
      state: 'discarded', ownerUserId: '1', ownerServer: 'https://water.test/api/',
    );
    final before = await db.exportFieldData();
    await expectLater(db.importFieldData({
      'squares': [{'server_id': 2, 'name': 'Replacement square'}],
      'pending_actions': [
        {'id': 'confirmed', 'action_type': 'payment.create', 'state': 'pending',
          'payload_json': '{"amount":"99.99"}'},
      ],
    }), throwsStateError);
    expect(await db.exportFieldData(), before);
    await db.importFieldData({'pending_actions': <Object>[]});
    expect(await db.exportFieldData(), before);
  });

  test('duplicate or malformed backup action rejects whole restore before cache replacement', () async {
    final db = AppDatabase.inMemory();
    addTearDown(db.closeDatabase);
    await db.cacheSquares([{'id': 1, 'name': 'Original square'}]);
    final before = await db.exportFieldData();
    final row = {'id': 'new-action', 'action_type': 'payment.create',
      'state': 'pending', 'payload_json': '{"amount":"10.25"}'};
    for (final actions in [
      [row, row],
      [row, {...row, 'id': 'other-action', 'payload_json': '[]'}],
      [row, {...row, 'id': 'other-action', 'state': 'not-a-state'}],
    ]) {
      await expectLater(db.importFieldData({
        'squares': [{'server_id': 2, 'name': 'Replacement square'}],
        'pending_actions': actions,
      }), throwsA(anyOf(isA<StateError>(), isA<FormatException>())));
      expect(await db.exportFieldData(), before);
    }
  });
}
