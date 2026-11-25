import 'dart:convert';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'pin_hasher.dart';

/// تخزين بيانات القفل. مجرّد حتى يمكن استبداله في الاختبارات بلا قنوات منصة.
abstract interface class LockStorage {
  Future<String?> read(String key);

  Future<void> write(String key, String? value);
}

class SecureLockStorage implements LockStorage {
  SecureLockStorage({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String? value) async {
    if (value == null) {
      await _storage.delete(key: key);
      return;
    }
    await _storage.write(key: key, value: value);
  }
}

class InMemoryLockStorage implements LockStorage {
  final Map<String, String?> values = <String, String?>{};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String? value) async {
    values[key] = value;
  }
}

/// نتيجة محاولة فتح القفل — تُعرض للمستخدم كما هي بالعربية.
enum UnlockResult {
  success,
  wrongPin,
  lockedOut,
  noPin,
}

/// قفل التطبيق: PIN محلي + مهلة خمول (بطاقة S5-01).
///
/// قرارات التصميم:
/// * القفل **محلي** ولا يُرسل للخادم؛ الرقم لا يخرج من الجهاز أبداً.
/// * عند تشغيل التطبيق يكون مقفلاً إن كان هناك رقم محفوظ (الافتراض الآمن).
/// * الخمول يُقاس بآخر تفاعل حقيقي (لمسة/كتابة)، لا بمجرد بقاء الشاشة مفتوحة.
/// * بعد 5 محاولات خاطئة يُقفل الإدخال 30 ثانية — حماية من التخمين اليدوي.
class AppLockService extends ChangeNotifier {
  AppLockService({
    LockStorage? storage,
    PinHasher? hasher,
    DateTime Function()? now,
    this.idleTimeout = const Duration(minutes: 5),
    this.maxAttempts = 5,
    this.lockoutDuration = const Duration(seconds: 30),
    Random? random,
  })  : _storage = storage ?? SecureLockStorage(),
        _hasher = hasher ?? const PinHasher(),
        _now = now ?? DateTime.now,
        _random = random;

  static const String saltKey = 'water.app_lock.salt';
  static const String hashKey = 'water.app_lock.hash';
  static const String pinLengthKey = 'water.app_lock.length';
  static const String attemptsKey = 'water.app_lock.attempts.v1';

  final LockStorage _storage;
  final PinHasher _hasher;
  final DateTime Function() _now;
  final Random? _random;

  /// المدة بعد آخر تفاعل قبل القفل التلقائي.
  final Duration idleTimeout;
  final int maxAttempts;
  final Duration lockoutDuration;

  bool _hasPin = false;
  bool _isLocked = false;
  bool _loaded = false;
  int _failedAttempts = 0;
  DateTime? _lockedUntil;
  DateTime? _lastActivity;
  Future<void> _pending = Future<void>.value();

  Future<T> _serialize<T>(Future<T> Function() operation) {
    final next = _pending.then((_) => operation());
    _pending = next.then<void>((_) {}, onError: (Object _, StackTrace __) {});
    return next;
  }

  Future<void> _persistAttempts() => _storage.write(attemptsKey, jsonEncode({
    'failed': _failedAttempts,
    'until': _lockedUntil?.toUtc().toIso8601String(),
  }));

  Future<void> _restoreAttempts() async {
    _failedAttempts = 0;
    _lockedUntil = null;
    final raw = await _storage.read(attemptsKey);
    if (!_hasPin || raw == null) return;
    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map<String, dynamic>) throw const FormatException('Invalid attempt state');
      final failed = decoded['failed'];
      final until = decoded['until'];
      if (failed is! int || failed < 0 || failed >= maxAttempts ||
          (until != null && until is! String)) throw const FormatException('Invalid attempt state');
      final deadline = until == null ? null : DateTime.tryParse(until as String);
      if (until != null && (deadline == null || !deadline.isUtc || failed != 0)) {
        throw const FormatException('Invalid lockout deadline');
      }
      _failedAttempts = failed;
      _lockedUntil = deadline;
    } on FormatException {
      // Corrupt persistent state cannot silently remove the rate limit.
      _lockedUntil = _now().add(lockoutDuration);
      await _persistAttempts();
    }
  }

  bool get hasPin => _hasPin;
  bool get isLocked => _isLocked;
  bool get isLoaded => _loaded;
  int get failedAttempts => _failedAttempts;

  /// كم يتبقى من مدة منع المحاولات (صفر إن لم يكن ممنوعاً).
  Duration get lockoutRemaining {
    final until = _lockedUntil;
    if (until == null) return Duration.zero;
    final remaining = until.difference(_now());
    return remaining.isNegative ? Duration.zero : remaining;
  }

  bool get isLockedOut => lockoutRemaining > Duration.zero;

  /// يُستدعى مرة عند بدء التطبيق: يقرأ وجود رقم ويقفل عند الوجود.
  Future<void> load() => _serialize(_load);

  Future<void> _load() async {
    final hash = await _storage.read(hashKey);
    _hasPin = hash != null && hash.isNotEmpty;
    _isLocked = _hasPin;
    await _restoreAttempts();
    _loaded = true;
    _lastActivity = _now();
    notifyListeners();
  }

  /// تعيين/تغيير الرقم. الرقم غير الصالح يرمي `ArgumentError`.
  Future<void> setPin(String pin, {String? currentPin}) =>
      _serialize(() => _setPin(pin, currentPin: currentPin));

  Future<void> _setPin(String pin, {String? currentPin}) async {
    if (!_loaded) await _load();
    if (!PinHasher.isValidPin(pin)) {
      throw ArgumentError('رقم الحماية يجب أن يكون من 4 إلى 8 أرقام.');
    }
    if (_hasPin) {
      if (currentPin == null) throw StateError('الرقم الحالي مطلوب.');
      final result = await _unlock(currentPin);
      if (result != UnlockResult.success) {
        throw StateError('الرقم الحالي غير صحيح.');
      }
    }

    final salt = PinHasher.newSalt(_random);
    await _storage.write(saltKey, salt);
    await _storage.write(hashKey, _hasher.hash(pin, salt));
    await _storage.write(pinLengthKey, pin.length.toString());

    _hasPin = true;
    _isLocked = false;
    _failedAttempts = 0;
    _lockedUntil = null;
    await _persistAttempts();
    _lastActivity = _now();
    notifyListeners();
  }

  /// يحاول فتح القفل بالرقم.
  Future<UnlockResult> unlock(String pin) => _serialize(() => _unlock(pin));

  Future<UnlockResult> _unlock(String pin) async {
    if (!_loaded) await _load();
    if (!_hasPin) return UnlockResult.noPin;
    if (isLockedOut) return UnlockResult.lockedOut;

    final salt = await _storage.read(saltKey);
    final hash = await _storage.read(hashKey);
    if (salt == null || hash == null) {
      // A missing credential component is an integrity failure, not a bypass.
      _isLocked = true;
      _lockedUntil = _now().add(lockoutDuration);
      _failedAttempts = 0;
      await _persistAttempts();
      notifyListeners();
      return UnlockResult.lockedOut;
    }

    if (_hasher.verify(pin, salt: salt, expectedHash: hash)) {
      _failedAttempts = 0;
      _lockedUntil = null;
      await _persistAttempts();
      _isLocked = false;
      _lastActivity = _now();
      notifyListeners();
      return UnlockResult.success;
    }

    _failedAttempts++;
    if (_failedAttempts >= maxAttempts) {
      _lockedUntil = _now().add(lockoutDuration);
      _failedAttempts = 0;
    }
    await _persistAttempts();
    notifyListeners();
    return UnlockResult.wrongPin;
  }

  /// يسجّل تفاعلاً حقيقياً من المستخدم.
  void recordActivity() {
    _lastActivity = _now();
  }

  /// يُستدعى عند العودة للتطبيق أو دورياً: يقفل إن تجاوز الخمول الحد.
  bool checkIdle() {
    if (!_hasPin || _isLocked) return false;
    final last = _lastActivity;
    if (last == null) return false;
    if (_now().difference(last) >= idleTimeout) {
      lockNow();
      return true;
    }
    return false;
  }

  void lockNow() {
    if (!_hasPin || _isLocked) return;
    _isLocked = true;
    notifyListeners();
  }

  /// إزالة القفل (يُستخدم عند تسجيل الخروج أو من إعدادات المستخدم).
  Future<void> clearPin() => _serialize(_clearPin);

  Future<void> _clearPin() async {
    await _storage.write(saltKey, null);
    await _storage.write(hashKey, null);
    await _storage.write(pinLengthKey, null);
    await _storage.write(attemptsKey, null);
    _hasPin = false;
    _isLocked = false;
    _failedAttempts = 0;
    _lockedUntil = null;
    notifyListeners();
  }
}
