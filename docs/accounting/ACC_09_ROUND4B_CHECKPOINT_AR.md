# ACC-09 — نقطة المراجعة الأولى، الجولة 4B
السلطة: 301b49b. الأساس a0448f9d3e1890d0e6877405bda8ed4a317cee4c، المحرك 2f4ac2bf430441f73dd5001346900e6876f5b87d مستورد ببصماته دون تعديل. مصفوفتا ACC-09/14 محفوظتان في هذا الفرع. لا تفعيل ACC14، لا migration ولا تعديل حقل/بيانات فعلية أو ملفات C.

## التنفيذ
- FinancialAmount بوابة توافق لمدخلات API القديمة int/float/string. الأعداد العشرية النصية تقرأ دون float حتى الصيغة الأسية والأصفار الزائدة إذا كانت القيمة عدداً صحيحاً من أجزاء المئة. float المقبول سابقاً يعبر تمثيل JSON round-trip دون تقريب؛ لا يمكن استرجاع دقة فقدها عميل JSON قبل وصوله، لذلك النص العشري هو الصيغة المستقبلية المفضلة. لم نغيّر شكل الاستجابة أو المسارات أو صلاحياتها. المدخلات ذات كسور أقل من سنت أو تجاوز النطاق ترفض؛ لا تقريب صامت. API controllers الثلاثة وwidget يمرران قيمة التحقق نفسها بدلاً من cast.
- PaymentService: التحقق والمقارنة في replay والمطالبات والتصفية، جمع الاستردادات الفردية دون SUM float، تخصيص minor مع نفس target/replacement/FIFO وترتيب المصدر، حفظ decimal من minor، مجموع صحيح مضبوط. PaymentAllocationHistory يحافظ على البصمة الأصلية، ويمنع تجاوز int64. لا تغيير لتوليد رقم السند أو شروط النقد/غير النقد أو ترتيب الأقفال.
- TransactionService: التحقق من مستند مصدر الحركة، إعادة الطلب، عكس الأصل حرفياً، كتابة decimal دقيقة، حساب رصيد الدفتر من الصفوف الفردية في معاملة المشترك القائمة. تحقق صريح من DECIMAL10,2 meter cache وDECIMAL12,2 subscriber cache قبل الكتابة؛ تجاوزها يرفض كامل العملية (لا تعديل المخطط حتى قرار المشرف).
- HistoricalTariffService: ExactMoney.tariffLine، تقريب HALF_UP مرة واحدة لكل سطر، ثم جمع مضبوط داخل حد الفاتورة. لا تغيير لاختيار التعرفة أو فترات القياس. InvoiceService: متأخرات من المبالغ الفردية الدقيقة؛ الرصيد الدائن من الدفتر الموحد؛ صافي دين الفاتورة هو الاستهلاك الجديد فقط، لا مضاعفة المتأخرات؛ قيد سالب دقيق.
- LateReceiptReviewService تمرير المبلغ المخزن إلى collectPayment دون تحويل float في موضع الاستدعاء. بقية حسابات الصندوق والتسوية مؤجلة.
- القفل الحالي محفوظ: subscriber ثم issuance حيث ينطبق، موارد المجال الحالية ثم تخصيص/دفتر. لا إدخال لقفل sequence. لا تعديل لمسار الشيك المرتجع أو الإقفال. ترتيب جديد شامل يحتاج موافقة A/C/D؛ لا تعاد المحاولات داخلياً بشكل جديد.
- لا توسيع schema هنا؛ لا backfill، لا migration، rollback قبل release = عدم اعتماد الشريحة؛ عند نشر مستقبلي لا تعدل أو تحذف قيوداً صادرة. الحاجز وخطة ACC14 منفصلان.

## خارج هذه الشريحة
HistoricalInvoiceCorrectionService، refund/transfer/void thresholds خارج المحرك المركزي، cash/treasury DECIMAL14 وBIGINT، تقارير ومجاميع أوسع، API response exact strings/version، Flutter business call sites، حالات status وقراءات C، ودمج ACC11/ACC10/ACC12/13. Eloquent decimal casts وSQLite storage affinity لا تكفي لإثبات schema exact end-to-end في كل المحركات. لا ادعاء اكتمال ACC09. يجب فحص أداء مسح الصفوف، وحدود المجموع التراكمي، وMySQL actual DB rollback/concurrency. 
## الاختبارات
- CLI مستقل tests/Unit/acc09_financial_boundary_check.php: بوابة التوافق/حدود التخزين/التقريب/overflow، 30,000 تحقق حتمي إضافي.
- Acc09CanonicalIntegrationTest: خمس حالات Laravel عادية (تسعير نصف سنت، التخصيص والدفتر وإعادة الطلب والعكس، رفض الكسور، rollback ضيق العداد عند الفاتورة والقبض، حد رصيد المشترك).
- تشغيل مستقل على قاعدة اصطناعية SQLite/MySQL مطلوب؛ لا تُحسب الاختبارات المكتوبة كناجحة. سجل النتائج في PR.

## دليل التنفيذ المحلي المعزول (ليس جهاز المستخدم)
PHP 8.4.24 CLI: php -l على ExactMoney.php وFinancialAmount.php واختبار CLI = نجح؛ php tests/Unit/acc09_financial_boundary_check.php = 30048 checks PASS. بصمات Git blob مطابقة للالتزام ac66369: c4de0741b111ef2c66f75a4cc7d9f81cd1f6e4a9، 42cffbd82c483e1e6622e063312a10cdcccf90c2، 1c33da2d610f8ba8dcdbbbf006e28ef7c2a8ea2f. البيئة لا تحتوي Composer أو PDO SQLite/MySQL؛ لا دليل تشغيل Laravel. CI مخصص للفرع فقط، SQLite ثم MySQL على قواعد اصطناعية، بدون أسرار الإنتاج، ولا إعادة اختبارات أمنية متخصصة. نتائج CI غير متحققة حتى وصول سجل فعلي.
