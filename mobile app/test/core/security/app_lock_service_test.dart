import 'dart:math';

import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/security/app_lock_service.dart';
import 'package:water_project/core/security/pin_hasher.dart';

/// قفل التطبيق (S5-01): PIN محلي + خمول + منع تخمين.
void main() {
  late InMemoryLockStorage storage;
  late DateTime now;

  AppLockService build({Duration idle = const Duration(minutes: 5)}) => AppLockService(
        storage: storage,
        hasher: const PinHasher(iterations: 2),
        now: () => now,
        idleTimeout: idle,
        maxAttempts: 3,
        lockoutDuration: const Duration(seconds: 30),
        random: Random(42),
      );

  setUp(() {
    storage = InMemoryLockStorage();
    now = DateTime(2026, 10, 24, 8);
  });

  group('AppLockService', () {
    test('جهاز بلا رقم: مفتوح وليس مقفلاً', () async {
      final lock = build();
      await lock.load();

      expect(lock.hasPin, isFalse);
      expect(lock.isLocked, isFalse);
      expect(lock.isLoaded, isTrue);
    });

    test('تعيين رقم يفعّل القفل ثم يفتحه ويقفله يدوياً', () async {
      final lock = build();
      await lock.load();
      await lock.setPin('1234');

      expect(lock.hasPin, isTrue);
      expect(lock.isLocked, isFalse);

      lock.lockNow();
      expect(lock.isLocked, isTrue);

      expect(await lock.unlock('1234'), UnlockResult.success);
      expect(lock.isLocked, isFalse);
    });

    test('الرقم الخطأ يزيد عدّاد المحاولات ثم يمنع الإدخال مؤقتاً', () async {
      final lock = build();
      await lock.load();
      await lock.setPin('1234');
      lock.lockNow();

      expect(await lock.unlock('0000'), UnlockResult.wrongPin);
      expect(lock.failedAttempts, 1);
      expect(lock.isLockedOut, isFalse);

      await lock.unlock('0001');
      await lock.unlock('0002');

      expect(lock.isLockedOut, isTrue);
      expect(await lock.unlock('1234'), UnlockResult.lockedOut);

      // انتهاء مدة المنع يعيد المحاولة الصحيحة للعمل.
      now = now.add(const Duration(seconds: 31));
      expect(lock.isLockedOut, isFalse);
      expect(await lock.unlock('1234'), UnlockResult.success);
    });

    test('النجاح يُصفّر عدّاد المحاولات', () async {
      final lock = build();
      await lock.load();
      await lock.setPin('1234');
      lock.lockNow();

      await lock.unlock('9999');
      expect(lock.failedAttempts, 1);

      expect(await lock.unlock('1234'), UnlockResult.success);
      expect(lock.failedAttempts, 0);
    });

    test('الخمول يقفل بعد المهلة، والتفاعل يؤجّل القفل', () async {
      final lock = build(idle: const Duration(minutes: 2));
      await lock.load();
      await lock.setPin('1234');

      now = now.add(const Duration(minutes: 1));
      lock.recordActivity();
      expect(lock.checkIdle(), isFalse);
      expect(lock.isLocked, isFalse);

      now = now.add(const Duration(minutes: 2));
      expect(lock.checkIdle(), isTrue);
      expect(lock.isLocked, isTrue);
    });

    test('تغيير الرقم يتطلب الرقم الحالي', () async {
      final lock = build();
      await lock.load();
      await lock.setPin('1234');

      await expectLater(lock.setPin('5678', currentPin: '0000'), throwsStateError);
      await lock.setPin('5678', currentPin: '1234');

      expect(await lock.unlock('5678'), UnlockResult.success);
      expect(await lock.unlock('1234'), isNot(UnlockResult.success));
    });

    test('الرقم غير الصالح يُرفض قبل أي تخزين', () async {
      final lock = build();
      await lock.load();

      await expectLater(lock.setPin('12'), throwsArgumentError);
      await expectLater(lock.setPin('abcd'), throwsArgumentError);
      expect(lock.hasPin, isFalse);
      expect(storage.values[AppLockService.hashKey], isNull);
    });

    test('إزالة القفل تمحو التجزئة والملح', () async {
      final lock = build();
      await lock.load();
      await lock.setPin('1234');
      lock.lockNow();

      await lock.clearPin();

      expect(lock.hasPin, isFalse);
      expect(lock.isLocked, isFalse);
      expect(storage.values[AppLockService.hashKey], isNull);
      expect(storage.values[AppLockService.saltKey], isNull);
    });

    test('إعادة التشغيل تقرأ وجود الرقم وتبدأ مقفلة', () async {
      final first = build();
      await first.load();
      await first.setPin('1234');

      final second = build();
      await second.load();

      expect(second.hasPin, isTrue);
      expect(second.isLocked, isTrue);
      expect(await second.unlock('1234'), UnlockResult.success);
    });
  });
}
