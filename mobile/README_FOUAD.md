# وحدات المهندس فؤاد (Dev 4) — Flutter + Laravel API

## ما تم بناؤه
| الشاشة | الوصف | الملفات |
|---|---|---|
| SCR-04 | المربعات السكنية + مشتركو كل مربع + Master-Detail | `lib/features/squares/` |
| SCR-05 | قائمة المشتركين (بحث/مربع/حالة، رصيد ملون، تحصيل/وايت سريع/360°) | `lib/features/subscribers/presentation/pages/subscribers_screen.dart` |
| SCR-06 | نموذج إضافة/تعديل مشترك (+967، تاريخ بدء الاشتراك) | `.../subscriber_form_screen.dart` |
| SCR-07 | ملف 360°: ملخص مالي معزول + 6 تبويبات + كشف حساب بفلتر جهة و PDF | `.../subscriber_360_screen.dart` |
| SCR-19 | رسوم الاشتراك: إجمالي/مفوتر/متبقي + إصدار فاتورة | `lib/features/subscription_fees/` |
| SCR-20 | طلبات الوايت: إجمالي + حالة/تاريخ + 📝 + إنجاز + فوترة | `lib/features/service_requests/` |
| SCR-22 | دفتر الأستاذ العام (قراءة فقط) | `lib/features/transactions/` |
| مشترك | `SubscriberMeterSelectorWidget` لأنور وأكرم | `lib/shared/widgets/subscriber_meter_selector.dart` |

## التشغيل
```bash
cd mobile
flutter pub get
flutter run                                   # وضع تجريبي ببيانات وهمية
flutter run --dart-define=DEMO=false --dart-define=API_BASE_URL=http://10.0.2.2:8000/api --dart-define=API_TOKEN=xxxx
```

## الـ API الخلفي (Laravel)
أُضيفت/حُسّنت مسارات المجموعات 3، 4، 9 (الخطة 2.3): `SquareController`،
`SubscriptionFeeController`، `ServiceRequestController`، `TransactionController`،
وتوسعة `SubscriberController` (ملف 360°، `/transactions` بالرصيد التراكمي،
`/collect-payment` عبر FIFO، حذف فردي/جماعي) — انظر `routes/api.php`.

## عقود التكامل
- أنور: `MeterCardWidget`/`RecordReadingSheet` يحلان محل البطاقة الافتراضية في تبويب العدادات عبر `onRecordReading`/`onAddMeter`.
- أكرم: `CollectPaymentSheet` وبطاقات الفواتير/المدفوعات عبر `onCollectPayment` والتبويبين 2-3.
- محمد: المسارات في `presentation/routes.dart` لكل وحدة لاستيرادها في `app_router.dart`، و`lib/core/` هنا مؤقت لوحدات فؤاد فقط.
