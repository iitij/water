import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/database/app_database.dart';

void main() {
  test('offline subscriber cache prefers server unified balance over legacy general balance', () async {
    final db = AppDatabase.inMemory();
    addTearDown(db.close);
    await db.cacheSubscribers([
      {'id': 1, 'name': 'Account one', 'balance': 2000, 'account_balance': -2499.75},
      {'id': 2, 'name': 'Account two', 'balance': -400, 'account_balance': 0},
      {'id': 3, 'name': 'Legacy fallback', 'balance': 100},
    ]);
    final rows = await db.getCachedSubscribers();
    expect(rows.singleWhere((row) => row['server_id'] == 1)['balance'], -2499.75);
    expect(rows.singleWhere((row) => row['server_id'] == 2)['balance'], 0);
    expect(rows.singleWhere((row) => row['server_id'] == 3)['balance'], 100);
  });
}
