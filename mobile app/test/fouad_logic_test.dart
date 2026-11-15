import 'package:flutter_test/flutter_test.dart';
import 'package:get_it/get_it.dart';
import 'package:water_project/core/fouad/api_client.dart';
import 'package:water_project/core/fouad/currency_formatter.dart';
import 'package:water_project/core/fouad/fouad_module.dart';
import 'package:water_project/features/squares/domain/repositories/squares_repository.dart';
import 'package:water_project/features/subscribers/domain/entities/subscriber.dart';
import 'package:water_project/features/subscribers/domain/repositories/subscribers_repository.dart';
import 'package:water_project/features/subscription_fees/domain/repositories/subscription_fees_repository.dart';
import 'package:water_project/features/transactions/domain/repositories/transactions_repository.dart';

void main() {
  tearDown(FouadModule.resetForTests);

  group('قاعدة عزل الأرصدة (الخطة 1.3/1)', () {
    test('الرصيد الصافي = الدائن - المدين عبر العام والعدادات', () {
      const s = Subscriber(id: 1, name: 'تجريبي', creditBalance: 7000, debitBalance: 12500);
      expect(s.netBalance, -5500);
    });
  });

  group('فلتر جهة كشف الحساب', () {
    test('القيم المرسلة للـ API مطابقة لـ SubscriberTransactionsTable', () {
      expect(const PartyAll().apiValue, isNull);
      expect(const PartyGeneral().apiValue, 'general');
      const m = MeterBalance(meterId: 11, uniqueCode: 'W-1');
      expect(const PartyMeter(m).apiValue, '11');
    });
  });

  group('تحويل الأرقام العربية', () {
    test('٠١٢٣ تتحول إلى 0123', () {
      expect(normalizeDigits('٠١٢٣٤٥'), '012345');
      expect(normalizeDigits('+967 ٧٧١'), '+967 771');
    });
  });

  group('توحيد المسارات وحقن التبعيات (FouadModule & ApiClient)', () {
    test('fouadApiPath يزيل الشرطة المائلة البادئة عندما ينتهي baseUrl بـ /', () {
      expect(
        fouadApiPath('/squares', baseUrl: 'http://10.0.2.2:8000/api/'),
        'squares',
      );
      expect(
        fouadApiPath('/subscribers/5/transactions', baseUrl: 'http://10.0.2.2:8000/api/'),
        'subscribers/5/transactions',
      );
      expect(
        fouadApiPath('/squares', baseUrl: 'http://10.0.2.2:8000/api'),
        '/squares',
      );
    });

    test('FouadModule.ensure يسجل كافة المستودعات الخمسة في GetIt بشكل idempotent', () {
      FouadModule.ensure(demoMode: true);
      expect(GetIt.I.isRegistered<FouadDependencies>(), isTrue);
      expect(GetIt.I.isRegistered<SquaresRepository>(), isTrue);
      expect(GetIt.I.isRegistered<SubscribersRepository>(), isTrue);
      expect(GetIt.I.isRegistered<SubscriptionFeesRepository>(), isTrue);
      expect(GetIt.I.isRegistered<TransactionsRepository>(), isTrue);
      expect(FouadModule.routes, isNotEmpty);
    });
  });
}
