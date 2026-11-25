# خريطة الانتقال إلى الحاجز المالي الدائم — بعد الجولة 133

المصدر المراجع: `70ffbf0e63146f9e5becb57eb8f5dca06c3470e5`.
الحالة: قبول اختبارات موجهة، لا قبول شامل ولا تفعيل إنتاجي.

## نتائج مثبتة
- الجولة 132 المصححة: 2 اختبار / 108 تحقق، تشغيل 45694340887، المصدر d29c3745e6e2f5843f6adf19aa60d91a4cd8ccaa.
- الجولة 133: 4 اختبارات / 138 تحقق، تشغيل 45694984110.
- منع إخفاء خسارة نقدية جديدة مثبتة بسبب عجز سابق، مع استمرار منع الصرف ونقص الإثبات.
- التراجع الذري عن التحصيل غير النقدي وتسليم النقد المؤجل مثبت في الحالات الجديدة، لا يساوي إثبات التسجيل الكامل تحت الحاجز الدائم.

## المسارات المراجعة فعلياً (ليست حصراً نهائياً)
| المسار | الاستدعاء الحالي | الأساس المرشح | الفجوة |
|---|---|---|---|
| جرد الخزينة وتسويتها | TreasuryCashCountController::adjust → TreasuryCashCountService::adjust | GatedCashCountAdjustmentWriter | تحويل الاستدعاء مع هوية عملية مستقرة وتاريخ معتمد؛ إثبات تغطية التسجيل |
| فتح خزينة وتمويل محصل | TreasuryController::store/fundCollector | GatedTreasuryOpeningFundingWriter | تفعيل منضبط لجميع نقاط الدخول |
| تحويل خزينة واستلامه | TreasuryController::dispatchTransfer/receiveTransfer | GatedTreasuryTransferWriter | تحويل الاستدعاءات بعد اعتماد جميع المسارات |
| ترحيل خزينة عام | TreasuryController::post → TreasuryRegisterService::post | TreasuryCustodyEvidence | محول كتابة يسجل جميع الآثار الفعلية، لا مجرد إثبات قراءة |
| قبض نقدي عادي | PaymentService::collectPayment | GatedCashReceiptWriter | حصر كل مستدعي API وFilament والمزامنة والمهام؛ لا تفعيل جزئي |
| إثبات طلب غير نقدي | NonCashClaimController::clear → PaymentService::clearNonCashClaim | FinancialLedgerEvidence + TreasuryCollectionEvidence | محول حاجز أولاً يسجل القيدين معاً، ويرفض تبني مستند قديم غير مسجل |
| نقد مؤجل محسوب سابقاً | LateReceiptReviewController::decide → LateReceiptReviewService::decide | GatedCountedCashWriter | توحيد نقطة الدخول وتأكيد صلاحية التاريخ والمرجع |
| تسليم نقد مؤجل مستقل | LateReceiptReviewController::clearCash → LateReceiptReviewService::receiveCash | FinancialLedgerEvidence + TreasuryCollectionEvidence | محول يسجل القيدين؛ معالجة تاريخ القبض وتاريخ التسليم المختلفين بلا إعادة كتابة تاريخية |
| تحصيل مؤجل بنكي | LateReceiptReviewController::clearBank → PaymentService::clearLateNonCash | FinancialLedgerEvidence + TreasuryCollectionEvidence | محول يسجل القيدين وتوثيق تاريخ التحصيل الفعلي |
| إلغاء وتحويل واسترداد | PaymentService / PaymentRefundService / PaymentAccountTransferService / InvoiceCancellationService | FinancialLedgerEvidence | محولات تغطي الآثار المتعددة والفترة دون مساس بالمستند الأصلي |
| إصدار فاتورة | InvoiceService::generateInvoice / Invoice::save | FinancialLedgerEvidence | حصر الإنشاء المباشر والدفعات والفوترة التلقائية وربطها بالحاجز |
| الإغلاق | FinancialCloseCoordinator + FinancialCloseProofSource | سياسة وحاجز وسجل إغلاق | لا تنفيذ إنتاجي موثوق لـ FinancialCloseProofSource؛ لا اعتماد إغلاق بمجرد صحة سجل العمليات المسجلة |

## ترتيب التنفيذ
1. استكمال محولات الكتابة للقيدين البنكي والنقد المؤجل، ثم المحولات متعددة الآثار للفواتير والردود والتحويلات.
2. إثبات المطابقة في الاتجاهين: كل أثر مسجل له مصدر صحيح، وكل مصدر مالي جديد مؤهل له أثر مسجل واحد فقط؛ لا استنتاج فترات من طابع زمني غير معتمد.
3. حصر API وFilament والأوامر المجدولة والمزامنة والكتابة المباشرة، ثم تفعيل موحد تحت gate-first؛ منع المسارات البديلة. لا تفعيل جزئي.
4. تدقيق التزامن، التراجع، التكرار، منع الكتابة عند التجميد والإغلاق؛ اختبارات المتغيرات فقط حتى مرحلة القبول المتكامل.
5. مصدر إثبات إغلاق فعلي، تغطية المصادر والأرصدة الافتتاحية والاستمرارية وحماية صلاحيات قاعدة البيانات.
6. تحديد شروط تخفيض احتياطي النقد المحتجز الفعلي عند استيفاء تغطية الإعادة وعدم إحداث عجز؛ لا إنفاق اعتماداً على سجل ظلي.

## حدود
- النقد المجهول الزائد أثناء عجز سابق ليس تعويضاً مثبتاً. يظل مسار التعامل مع الفائض الجديد في هذه الحالة مقيداً؛ سياسة إثبات التعويض المستقل مفتوحة.
- لم تُراجع جميع ملفات Filament والمزامنة والأوامر أو صلاحيات الإنتاج؛ القائمة أعلاه جزئية مثبتة بالمصدر.
- الحصر لا يعادل إغلاقاً ولا إثباتاً لتغطية المصادر. لا تغيير لبيانات فعلية أو main أو نشر.
- التصحيحات التاريخية خارج النطاق بناء على قرار المالك؛ المسارات الحالية الصحيحة والتراجع والتوافق باقية.
