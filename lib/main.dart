import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import 'app.dart';
import 'bootstrap.dart';
import 'core/config/app_config.dart';

/// نقطة الدخول للتطبيق (شريحة Dev 3).
///
/// `bootstrap` يهيّئ التخزين المحلي و`get_it` ويحدّث `GET /api/settings` في
/// الخلفية، ثم يبنى الجذر داخل `ScreenUtilInit` (التصميم 360×690 كما في
/// `FLUTTER_MOBILE_MASTER_PLAN.md` §3.1).
///
/// ملاحظة دمج: عند إضافة Dev 1 لطابور المزامنة/Drift تبقى هذه الدالة كما هي،
/// لأن التهيئة كلها معزولة داخل `bootstrap.dart`.
Future<void> main() async {
  runZonedGuarded<Future<void>>(
    () async {
      WidgetsFlutterBinding.ensureInitialized();

      FlutterError.onError = (details) {
        FlutterError.presentError(details);
        if (kDebugMode) {
          debugPrint('[water] ${details.exceptionAsString()}');
        }
      };

      await bootstrap(const AppConfig());
      runApp(buildResponsiveGate(child: const WaterApp()));
    },
    (error, stack) {
      if (kDebugMode) {
        debugPrint('[water][uncaught] $error\n$stack');
      }
    },
  );
}
