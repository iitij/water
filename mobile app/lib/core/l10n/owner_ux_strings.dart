abstract final class OwnerUxStrings {
  static const dailyWork = 'ماذا تريد أن تفعل اليوم؟';
  static const dailyHelp = 'اختر المهمة، وسيرشدك التطبيق إلى خطواتها.';
  static const projectSummary = 'حالة المشروع';
  static const summaryHelp = 'الديون والتحصيل والفواتير من آخر بيانات محمّلة';
  static const moreNumbers = 'تفاصيل الأرقام والتقارير';
  static const recordsSearch = 'ابحث عن مشترك أو فاتورة';
  static const recordsSearchHelp = 'بالاسم أو الهاتف أو رقم العداد';
  static const allServices = 'كل الخدمات';
  static const searchService = 'ابحث عن خدمة…';
  static const directoryHelp =
      'اختر ما تريد عمله؛ ستجد الإعدادات والحساب في الأقسام أدناه.';
  static const reviewSync = 'مراجعة الإرسال';
  static const noPending = 'لا توجد عمليات معلقة';
  static const online = 'متصل بالشبكة';
  static const offline = 'غير متصل بالشبكة';
  static const checking = 'جارٍ التحقق من الاتصال';
  static const emptyHome =
      'يمكنك الوصول إلى حسابك والمزامنة من كل الخدمات. لطلب صلاحيات إضافية، تواصل مع مدير النظام.';
  static const quickAccess = 'الوصول السريع';
  static const browseServices = 'استعراض كل الخدمات';
  static const searchServiceLabel = 'ابحث عن خدمة';
  static const serviceExample = 'مثلاً: فاتورة، مشترك، واتساب';
  static const clearSearch = 'مسح البحث';
  static const all = 'الكل';
  static const noServices = 'لم نجد خدمة بهذا الاسم';
  static const noServicesHelp = 'جرّب اسماً آخر أو اختر قسماً مختلفاً.';
  static const showAll = 'عرض كل الخدمات';
  static const refresh = 'تحديث الأرقام';
  static const loadingNumbers = 'جارٍ تحميل أرقام المشروع';
  static const failedNumbers = 'تعذر تحديث أرقام المشروع';
  static const failedNumbersHelp =
      'أعد المحاولة عند توفر الاتصال. يمكنك متابعة بقية المهام.';
  static const cachedNumbers = 'بيانات محفوظة؛ قد تكون تغيرت على النظام';
  static const refreshFailed =
      'تعذر التحديث؛ الأرقام المعروضة من آخر تحميل ناجح';
  static const outstanding = 'إجمالي الديون المستحقة';
  static const monthlyCollection = 'المحصّل هذا الشهر';
  static const currency = 'ر.ي';
  static const dashboard = 'تفاصيل حالة المشروع';
  static const support = 'الدعم والمعلومات الفنية';
  static const supportHelp = 'افتح هذا القسم عند طلب المساعدة من الدعم';
  static const diagnostics = 'فحص التطبيق';
  static const diagnosticsHelp = 'حالة الإرسال وآخر الأخطاء وتقرير للدعم';
  static const connectionLog = 'سجل الاتصال';
  static const connectionLogHelp = 'تفاصيل الطلبات والأخطاء لمساعدة الدعم';
  static const account = 'حسابي والمزامنة';
  static const accountHelp = 'متابعة الإرسال وحماية الحساب';
  static const dailyReports = 'التقارير ومتابعة العمل';
  static const dailyReportsHelp = 'راجع وضع المشروع وحسابات المحصلين';
  static const administration = 'إدارة المشروع';
  static const administrationHelp = 'المستخدمون والإعدادات والخدمات الإدارية';
  static const fieldTitle = 'سجّل قراءة العداد';
  static const fieldHelp =
      'ابحث عن العداد، راجع اسم المشترك، ثم أدخل القراءة الجديدة.';
  static const fieldOffline = 'حفظ القراءات عند انقطاع الشبكة';
  static const fieldOfflineHelp =
      'القراءة التي يظهر أنها محفوظة على الجهاز تنتظر الإرسال. راجع المزامنة للتأكد من وصولها إلى النظام.';
  static const crmTitle = 'كل بيانات المشترك في مكان واحد';
  static const crmHelp =
      'ابحث عن المشترك لعرض عداداته وفواتيره ودفعاته وكشف حسابه.';
  static const subscriberFile = 'ملف المشترك';
  static const billingTitle = 'الفواتير والتحصيل';
  static const billingHelp =
      'ابحث عن الفاتورة أو المشترك، سجّل المبلغ، ثم راجع سند القبض.';
  static String servicesCount(int count) => '$count خدمة متاحة';
  static String invoiceCounts(int unpaid, int overdue) =>
      '$unpaid فاتورة غير مسددة · $overdue متأخرة';
  static String updatedAt(String time) => 'آخر تحميل ناجح: $time';
  static String greeting(String name) => 'أهلاً، $name';
  static String pending(int count) => '$count عملية بانتظار المزامنة';
  static String failed(int count) => '$count عملية تحتاج مراجعة';
}
