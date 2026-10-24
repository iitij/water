/// وعاء حقن التبعيات للوحدة المالية.
///
/// كل تبعيات Dev 3 تُقرأ من `getIt` الخاص بـ Dev 1 بعد أن يتأكد [FinanceModule.ensure]
/// من تسجيلها — فلا يحتاج المضيف إلى تعديل أي ملف لتشغيل الوحدة المالية.
library;
import 'package:get_it/get_it.dart';

import 'finance_api_client.dart';
import 'finance_module.dart';

export '../di/injection_container.dart' show getIt, configureDependencies;

/// الاختصار المستعمل في طبقة Dev 3 كلها (`sl<InvoicesRepository>()`).
T sl<T extends Object>() {
  FinanceModule.ensure();
  return GetIt.I<T>();
}

/// الاختصار الآمن: يعيد `null` إذا لم تُسجَّل التبعية بعد.
///
/// يُستخدم للتبعيات الاختيارية الجديدة (مثل صندوق المحصّل) حتى لا تتعطل
/// الشاشات القديمة في الاختبارات التي لا تحمّل الوحدة كاملة.
T? trySl<T extends Object>() => GetIt.I.isRegistered<T>() ? GetIt.I<T>() : null;

/// الوحدة مسجّلة؟ (تُستعمل في الاختبارات و`FinanceModule.ensure`).
bool get isFinanceModuleReady => GetIt.I.isRegistered<FinanceApiClient>();
