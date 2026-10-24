import 'package:flutter/foundation.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import 'api_log_manager.dart';

/// مراقب عام لجميع كتل وبلوكات وحالات التطبيق (Blocs / Cubits).
///
/// يسجل أي خطأ يحدث داخل أي Cubit أو Bloc تلقائياً، ويعرضه في سجلات النظام
/// مع اسم الكلاس والخطأ والتتبع، لنعرف سبب المشكلة فوراً في أي شاشة.
class AppBlocObserver extends BlocObserver {
  AppBlocObserver({ApiLogManager? logManager})
      : _logManager = logManager ?? ApiLogManager.instance;

  final ApiLogManager _logManager;

  @override
  void onError(BlocBase bloc, Object error, StackTrace stackTrace) {
    super.onError(bloc, error, stackTrace);

    final blocName = bloc.runtimeType.toString();
    _logManager.logAppError(
      'Bloc:$blocName',
      error,
      stackTrace: stackTrace,
      endpoint: '/bloc/$blocName',
    );

    if (kDebugMode) {
      debugPrint('🚨 [BLOC ERROR] $blocName: $error');
    }
  }

  @override
  void onChange(BlocBase bloc, Change change) {
    super.onChange(bloc, change);
    // تتبع الحالات التي تمثل أخطاء في الـ State
    final nextState = change.nextState;
    if (nextState != null) {
      final str = nextState.toString().toLowerCase();
      if (str.contains('error') || str.contains('failure')) {
        if (kDebugMode) {
          debugPrint('⚠️ [BLOC STATE ERROR] ${bloc.runtimeType}: $nextState');
        }
      }
    }
  }
}
