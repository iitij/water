import 'package:flutter/widgets.dart';

/// نصوص الواجهة الأساسية (S5-04).
///
/// المشروع لا يستخدم `flutter gen-l10n` ولا ملفات `.arb` بعد — وهذا مذكور
/// صراحة في تقرير البطاقة. ما هنا هو طبقة مركزية واحدة تُغلّف أسطح التنقل
/// وأخطاء الشبكة القياسية، حتى لا تتفرّق نفس الجملة في عشر شاشات.
///
/// القاعدة: أي نص جديد في التنقل أو في رسالة خطأ عامة يُضاف هنا، ولا يُكتب
/// داخل الشاشة. النصوص الخاصة بكل شاشة (عناوين حقول، شروح) تُرحَّل تدريجياً.
/// نصوص ثابتة قابلة للاستخدام كقيم افتراضية في `const` constructors.
///
/// لا يمكن استخدام `AppStrings.ar.networkError` كقيمة افتراضية لأن الوصول إلى
/// حقل كائن ثابت ليس تعبيراً ثابتاً؛ لذلك نُعرّف النص هنا مرة واحدة ويشير إليه
/// كلٌّ من `AppStrings.ar` و`core/error/failures.dart`.
abstract final class AppStringsDefaults {
  static const String networkError =
      'تعذر الاتصال بالخادم — تحقق من الشبكة أو جرّب الوضع أوفلاين.';
  static const String timeoutError = 'انتهت مهلة الاتصال بالخادم.';
  static const String unauthorizedError = 'انتهت الجلسة، يرجى تسجيل الدخول مجدداً.';
  static const String forbiddenError = 'لا تملك صلاحية تنفيذ هذه العملية.';
  static const String notFoundError = 'السجل غير موجود أو تم حذفه.';
  static const String errorTitle = 'تعذّر تحميل البيانات';
  static const String conflictError =
      'العملية متعارضة مع حالة السجل الحالية. أعد التحميل ثم حاول.';
  static const String serverError = 'حدث خطأ في الخادم. حاول بعد قليل.';
  static const String unknownError =
      'تعذر تنفيذ العملية. تحقق من البيانات أو الاتصال بالخادم.';
}

enum AppLanguage { ar, en }

@immutable
class AppStrings {
  const AppStrings._({
    required this.language,
    required this.appTitle,
    required this.navHome,
    required this.navField,
    required this.navFinance,
    required this.navSubscribers,
    required this.navMore,
    required this.homeTitle,
    required this.homeSubtitle,
    required this.fieldTitle,
    required this.fieldSubtitle,
    required this.financeTitle,
    required this.financeSubtitle,
    required this.subscribersTitle,
    required this.subscribersSubtitle,
    required this.moreTitle,
    required this.moreSubtitle,
    required this.networkError,
    required this.timeoutError,
    required this.unauthorizedError,
    required this.forbiddenError,
    required this.notFoundError,
    required this.errorTitle,
    required this.conflictError,
    required this.serverError,
    required this.unknownError,
    required this.offlineBanner,
    required this.retry,
    required this.cancel,
    required this.save,
    required this.pendingSync,
    required this.syncedQueue,
    required this.cannotReadMeter,
  });

  final AppLanguage language;

  final String appTitle;

  final String navHome;
  final String navField;
  final String navFinance;
  final String navSubscribers;
  final String navMore;

  final String homeTitle;
  final String homeSubtitle;
  final String fieldTitle;
  final String fieldSubtitle;
  final String financeTitle;
  final String financeSubtitle;
  final String subscribersTitle;
  final String subscribersSubtitle;
  final String moreTitle;
  final String moreSubtitle;

  final String networkError;
  final String timeoutError;
  final String unauthorizedError;
  final String forbiddenError;
  final String notFoundError;

  /// عنوان موحّد لشاشة/بطاقة الخطأ القابلة لإعادة المحاولة.
  final String errorTitle;
  final String conflictError;
  final String serverError;
  final String unknownError;

  final String offlineBanner;
  final String retry;
  final String cancel;
  final String save;
  final String pendingSync;
  final String syncedQueue;
  final String cannotReadMeter;

  static const AppStrings ar = AppStrings._(
    language: AppLanguage.ar,
    appTitle: 'مشروع المياه',
    navHome: 'الرئيسية',
    navField: 'الميدان',
    navFinance: 'المالية',
    navSubscribers: 'المشتركون',
    navMore: 'المزيد',
    homeTitle: 'مساحة العمل',
    homeSubtitle: 'خلاصة اليوم وإجراءاتك السريعة',
    fieldTitle: 'العمليات الميدانية',
    fieldSubtitle: 'القراءات والعدادات',
    financeTitle: 'التحصيل والمالية',
    financeSubtitle: 'الفواتير والسندات والتعرفة',
    subscribersTitle: 'المشتركون والخدمات',
    subscribersSubtitle: 'الملفات والمربعات والطلبات',
    moreTitle: 'المزيد',
    moreSubtitle: 'التقارير والإعدادات والحساب',
    networkError: AppStringsDefaults.networkError,
    timeoutError: AppStringsDefaults.timeoutError,
    unauthorizedError: AppStringsDefaults.unauthorizedError,
    forbiddenError: AppStringsDefaults.forbiddenError,
    notFoundError: AppStringsDefaults.notFoundError,
    errorTitle: AppStringsDefaults.errorTitle,
    conflictError: AppStringsDefaults.conflictError,
    serverError: AppStringsDefaults.serverError,
    unknownError: AppStringsDefaults.unknownError,
    offlineBanner: 'تعمل دون اتصال — ستُزامن العمليات عند عودة الشبكة.',
    retry: 'إعادة المحاولة',
    cancel: 'إلغاء',
    save: 'حفظ',
    pendingSync: 'بانتظار المزامنة',
    syncedQueue: 'تمت مزامنة كل العمليات',
    cannotReadMeter: 'تعذّر قراءة العداد',
  );

  /// الإنجليزية للتنقل والأخطاء العامة فقط — بقية الشاشات عربية حتى تُرحَّل.
  static const AppStrings en = AppStrings._(
    language: AppLanguage.en,
    appTitle: 'Water Project',
    navHome: 'Home',
    navField: 'Field',
    navFinance: 'Finance',
    navSubscribers: 'Subscribers',
    navMore: 'More',
    homeTitle: 'Workspace',
    homeSubtitle: 'Today at a glance',
    fieldTitle: 'Field operations',
    fieldSubtitle: 'Readings and meters',
    financeTitle: 'Collections and finance',
    financeSubtitle: 'Invoices, receipts and tariffs',
    subscribersTitle: 'Subscribers and services',
    subscribersSubtitle: 'Files, squares and requests',
    moreTitle: 'More',
    moreSubtitle: 'Reports, settings and account',
    networkError: 'Cannot reach the server — check the network or work offline.',
    timeoutError: 'The server took too long to respond.',
    unauthorizedError: 'Your session expired. Please sign in again.',
    forbiddenError: 'You do not have permission to do this.',
    notFoundError: 'The record no longer exists.',
    errorTitle: 'Could not load data',
    conflictError: 'This conflicts with the current record. Reload and try again.',
    serverError: 'Server error. Please try again shortly.',
    unknownError: 'The operation failed. Check the data or the connection.',
    offlineBanner: 'Working offline — changes will sync when the network returns.',
    retry: 'Retry',
    cancel: 'Cancel',
    save: 'Save',
    pendingSync: 'Waiting to sync',
    syncedQueue: 'Everything is synced',
    cannotReadMeter: 'Could not read the meter',
  );

  static AppStrings of(BuildContext context) {
    final locale = Localizations.maybeLocaleOf(context);
    return forLanguageCode(locale?.languageCode);
  }

  static AppStrings forLanguageCode(String? code) =>
      code != null && code.toLowerCase().startsWith('en') ? en : ar;

  /// نص رسالة خطأ عام من رمز HTTP — تُستخدم في الشبكة وفي الشاشات.
  String forStatusCode(int? statusCode) {
    if (statusCode == null || statusCode == 0) return networkError;
    if (statusCode == 401) return unauthorizedError;
    if (statusCode == 403) return forbiddenError;
    if (statusCode == 404) return notFoundError;
    if (statusCode == 408) return timeoutError;
    if (statusCode == 409) return conflictError;
    if (statusCode == 422) return unknownError;
    if (statusCode >= 500) return serverError;
    return unknownError;
  }
}
