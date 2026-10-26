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
  static const String unauthorizedError =
      'انتهت الجلسة، يرجى تسجيل الدخول مجدداً.';
  static const String forbiddenError = 'لا تملك صلاحية تنفيذ هذه العملية.';
  static const String notFoundError = 'السجل غير موجود أو تم حذفه.';
  static const String errorTitle = 'تعذّر تحميل البيانات';
  static const String invalidResponse = 'وصلت بيانات غير مكتملة من الخادم. أعد المحاولة.';
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
    required this.devicesTitle,
    required this.devicesSubtitle,
    required this.backupTitle,
    required this.backupSubtitle,
    required this.remindersTitle,
    required this.remindersSubtitle,
    required this.dataQualityTitle,
    required this.dataQualitySubtitle,
    required this.saveAndAddAnother,
    required this.savedFiltersTitle,
    required this.savedFiltersHint,
    required this.actionRestore,
    required this.actionShare,
    required this.actionDelete,
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

  // ── إضافات 2.0 (S8-01) ──────────────────────────────────────────────
  final String devicesTitle;
  final String devicesSubtitle;
  final String backupTitle;
  final String backupSubtitle;
  final String remindersTitle;
  final String remindersSubtitle;
  final String dataQualityTitle;
  final String dataQualitySubtitle;
  final String saveAndAddAnother;
  final String savedFiltersTitle;
  final String savedFiltersHint;
  final String actionRestore;
  final String actionShare;
  final String actionDelete;

  // Cash session review copy shared with approval and pagination surfaces.
  String get cashReviewLoadError => language == AppLanguage.en
      ? 'Could not load cash sessions. Try again.'
      : 'تعذر تحميل الصناديق. أعد المحاولة.';
  String get cashReviewDecisionError => language == AppLanguage.en
      ? 'Could not save the decision. Refresh and try again.'
      : 'تعذر حفظ القرار. حدّث القائمة وأعد المحاولة.';

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
    devicesTitle: 'الأجهزة المسجّلة',
    devicesSubtitle: 'أبطل جهازاً مفقوداً أو مسروقاً',
    backupTitle: 'نسخة احتياطية محلية',
    backupSubtitle: 'احفظ بيانات الميدان واستعدها',
    remindersTitle: 'تذكيرات الجهاز',
    remindersSubtitle: 'قراءات وفواتير وإقفال صندوق',
    dataQualityTitle: 'جودة البيانات',
    dataQualitySubtitle: 'مشكلات قابلة للإصلاح',
    saveAndAddAnother: 'حفظ وإضافة آخر',
    savedFiltersTitle: 'فلاتر محفوظة',
    savedFiltersHint: 'احفظ الفلاتر الحالية باسم',
    actionRestore: 'استعادة',
    actionShare: 'مشاركة',
    actionDelete: 'حذف',
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
    networkError:
        'Cannot reach the server — check the network or work offline.',
    timeoutError: 'The server took too long to respond.',
    unauthorizedError: 'Your session expired. Please sign in again.',
    forbiddenError: 'You do not have permission to do this.',
    notFoundError: 'The record no longer exists.',
    errorTitle: 'Could not load data',
    conflictError:
        'This conflicts with the current record. Reload and try again.',
    serverError: 'Server error. Please try again shortly.',
    unknownError: 'The operation failed. Check the data or the connection.',
    offlineBanner:
        'Working offline — changes will sync when the network returns.',
    retry: 'Retry',
    cancel: 'Cancel',
    save: 'Save',
    pendingSync: 'Waiting to sync',
    syncedQueue: 'Everything is synced',
    cannotReadMeter: 'Could not read the meter',
    devicesTitle: 'Registered devices',
    devicesSubtitle: 'Revoke a lost or stolen device',
    backupTitle: 'Local backup',
    backupSubtitle: 'Save and restore field data',
    remindersTitle: 'Device reminders',
    remindersSubtitle: 'Readings, invoices and cash close',
    dataQualityTitle: 'Data quality',
    dataQualitySubtitle: 'Fixable data issues',
    saveAndAddAnother: 'Save and add another',
    savedFiltersTitle: 'Saved filters',
    savedFiltersHint: 'Save the current filters as',
    actionRestore: 'Restore',
    actionShare: 'Share',
    actionDelete: 'Delete',
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

  // Operational parity screens share these stable keys with their catalogs.
  String get auditTitle => AppStringsParity.auditTitle;
  String get auditSubtitle => AppStringsParity.auditSubtitle;
  String get auditEmpty => 'لا توجد أحداث مطابقة للفلاتر.';
  String get auditSearch => 'ابحث في الأحداث والكيانات';
  String get auditEvent => 'نوع الحدث';
  String get auditSource => 'المصدر';
  String get auditActor => 'المستخدم';
  String get auditEntity => 'نوع السجل';
  String get auditEntityId => 'رقم السجل';
  String get auditOccurredAt => 'وقت الحدث';
  String get auditDevice => 'الجهاز';
  String get auditIp => 'عنوان الشبكة';
  String get auditBefore => 'قبل التغيير';
  String get auditAfter => 'بعد التغيير';
  String get auditAll => 'الكل';
  String get auditDetails => 'تفاصيل حدث التدقيق';
  String get auditSystem => 'النظام';
  String get auditNoValue => 'لا توجد بيانات';
  String get settingKey => 'المفتاح';
  String get settingValue => 'القيمة';
  String get settingLabel => 'اسم الإعداد';
  String auditSourceLabel(String? source) => switch (source) {
    'mobile' => 'التطبيق', 'api' => 'واجهة الربط', 'web' => 'لوحة الويب',
    'console' => 'مهام النظام', _ => source ?? auditSystem,
  };
  String get saveAndAddMeter => 'حفظ وإضافة عداد';
  String get setupAddFee => 'إضافة رسوم اشتراك للعداد';
  String get setupFeeAmount => 'رسوم الاشتراك (ريال)';
  String get setupFeeNotes => 'ملاحظات رسوم الاشتراك';
  String get setupSkipFee => 'إنهاء دون رسوم';
  String get setupFeeSaved => 'تم حفظ رسوم الاشتراك.';
  String get setupSubscriberSaved => 'تم حفظ المشترك؛ أكمل إضافة العداد.';
  String get setupMeterCancelled =>
      'المشترك محفوظ. يمكنك إضافة عداده لاحقاً من ملفه.';
  String get setupAmountInvalid => 'أدخل مبلغاً صحيحاً أكبر من صفر.';
  String get serviceFinancialLocked =>
      'المبلغ والمشترك ثابتان لارتباط الطلب بفاتورة؛ يمكنك تعديل التاريخ والحالة والملاحظات.';
  String get serviceChangeSubscriber => 'تغيير المشترك';
  String get serviceCloseSubscriber => 'إغلاق اختيار المشترك';
  String get openLinkedInvoice => 'عرض الفاتورة المرتبطة';
  String get cashReviewTitle => AppStringsParity.cashReviewTitle;
  String get cashReviewSubtitle => AppStringsParity.cashReviewSubtitle;
  String get cashReviewAllStates => 'كل الحالات';
  String get cashReviewApplyFilters => 'تطبيق الفلاتر';
  String get cashReviewApprovalNotes => 'ملاحظات الاعتماد (اختياري)';
  String get cashReviewApprove => 'اعتماد الصندوق';
  String get cashReviewApproved => 'تم اعتماد الصندوق.';
  String get cashReviewClearDate => 'مسح التاريخ';
  String get cashReviewClosingNotes => 'ملاحظات الإغلاق';
  String get cashReviewCollectorId => 'رقم المحصّل';
  String get cashReviewCounted => 'المبلغ المعدود';
  String get cashReviewDetails => 'تفاصيل الصندوق';
  String get cashReviewEmpty => 'لا توجد صناديق مطابقة للفلاتر.';
  String get cashReviewExpected => 'المبلغ المتوقع';
  String get cashReviewFromDate => 'من تاريخ';
  String get cashReviewInvalidCollector => 'أدخل رقم محصّل صحيحاً.';
  String get cashReviewInvalidDates =>
      'تاريخ البداية يجب ألا يتجاوز تاريخ النهاية.';
  String get cashReviewNext => 'التالي';
  String get cashReviewPrevious => 'السابق';
  String get cashReviewReasonRequired => 'اكتب سبب الرفض (10 أحرف على الأقل).';
  String get cashReviewRefresh => 'تحديث';
  String get cashReviewReject => 'رفض الصندوق';
  String get cashReviewRejected => 'تم رفض الصندوق.';
  String get cashReviewRejectionReason => 'سبب الرفض';
  String get cashReviewReport => 'تقرير الصندوق';
  String get cashReviewResetFilters => 'مسح الفلاتر';
  String get cashReviewState => 'الحالة';
  String get cashReviewToDate => 'إلى تاريخ';
  String get cashReviewVariance => 'الفرق النقدي';
  String get cashReviewPage => 'الصفحة';
  String get cashReviewTotal => 'الإجمالي';
  String get cashReviewDecisionFailed => 'تعذر حفظ قرار الصندوق. أعد المحاولة.';
  String get cashReviewCollector => 'المحصّل';
  String get cashReviewAllCollectors => 'كل المحصّلين';
  String get feeChangeSubscriber => 'تغيير مشترك رسوم الاشتراك';
  String get roleGuard => 'نطاق المصادقة';
  String get roleReadOnly => 'المصفوفة للعرض فقط';
  String get roleViewMatrix => 'عرض مصفوفة الصلاحيات';
  String get roleGuardChangeTitle => 'تغيير نطاق المصادقة';
  String get roleGuardChangeMessage => 'ستُزال الصلاحيات غير المتاحة في النطاق الجديد من الاختيار. راجع المصفوفة قبل الحفظ. الدور المرتبط بمستخدمين لا يقبل تغيير نطاقه.';
  String get roleGuardConfirm => 'تغيير النطاق ومراجعة الصلاحيات';
  String get readingActualDateLabel => 'تاريخ إدخال القراءة الفعلي';
  String get readingActualDateHelp => 'اختر اليوم الذي تمت فيه قراءة العداد؛ فترة الاستهلاك تبقى كما حددها النظام.';
  String get readingActualDateChange => 'تغيير تاريخ القراءة';
}

/// Const labels allow the permission-aware navigation inventories to stay const.
abstract final class AppStringsParity {
  static const auditTitle = 'سجل التدقيق المركزي';
  static const auditSubtitle = 'أحداث النظام والمستخدمون والتغييرات';
  static const cashReviewTitle = 'مراجعة صناديق المحصّلين';
  static const cashReviewSubtitle =
      'راجع النقد والتقارير واعتمد الصناديق أو ارفضها';
}
