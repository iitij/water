import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';

/// Serializes Windows `flutter_secure_storage` operations (`flutter_secure_storage.dat`)
/// across independent storage wrappers so concurrent DPAPI read-modify-write cycles
/// cannot clobber each other's keys.
class WindowsSecureStorageLock {
  const WindowsSecureStorageLock._();

  static Future<void> _pending = Future<void>.value();

  static Future<T> run<T>(
    Future<T> Function() operation, {
    bool? isWindows,
  }) {
    final activeOnWindows = isWindows ?? (!kIsWeb && Platform.isWindows);
    if (!activeOnWindows) {
      return operation();
    }
    final next = _pending.then((_) => operation());
    _pending = next.then<void>((_) {}, onError: (Object _, StackTrace __) {});
    return next;
  }
}
