# الجولة 76 — مصفوفة تغطية مسارات الكتابة المالية

## القرار

حظر التفعيل والدمج في main حتى إغلاق جميع شروط القبول. آخر أساس تراكمي مقبول ضمن نطاقه هو الجولة 69 عند c542312de9573bb56b6b448b7559aaea9ae8bbd2. الفروع 70–75 مستقلة وغير مقبولة تشغيلياً. لا نعتبر وجود محول داخلي دليلاً على منع تجاوز الرقابة عبر API أو Filament أو المهام المجدولة أو قاعدة البيانات.

المصادر: الجرد السابق للجولة 53 (PR130، run 45568716429، job 139049798930، V8 عند 8a914bf804b47edee8fc9ec6e6d484296321b28b)، ومراجعة مباشرة لخدمات ووحدات تحكم API على أساس الجولة 69. الجرد 179 مساراً مرشحاً وليس 179 كاتباً مثبتاً؛ لم نكرر التشغيل السابق. المصفوفة أدناه تصنيف أولي قابل للتتبع، وليس إثبات تغطية ديناميكية.

## 1. حركات مالية مباشرة

| ID | الخدمة / نقطة الدخول الفعلية | أثر العملية | الحالة |
|---|---|---|---|
| W01 | TreasuryRegisterService::open؛ TreasuryController::store | افتتاح حساب، إثبات افتتاح مستقل ولو صفر | محول 68 مقبول ضمن نطاقه، الربط الفعلي متبقٍ |
| W02 | CashSessionService::openFunded؛ TreasuryController::fundCollector | صرف تمويل الصندوق من الخزينة | محول 68 مقبول ضمن نطاقه |
| W03 | TreasuryTransferService::dispatch/receive؛ TreasuryController::dispatchTransfer/receiveTransfer | طرفان في تاريخين محتملين مع نقدية بالطريق | محول 67 مقبول ضمن نطاقه |
| W04 | PaymentService::collectPayment؛ PaymentController::store؛ InvoiceController::pay/payFromPayload؛ واجهات Filament | سند نقدي، طرف مشترك، وجهة صندوق صريحة | محول 69 مقبول ضمن نطاقه، الربط الفعلي متبقٍ |
| W05 | InvoiceService::generateInvoice؛ InvoiceController::store/generateMeterInvoice؛ ReadingController::bulkInvoice/syncBatch؛ صفحات الفوترة | فاتورة وقيد مدين، مجموعة قراءات كاملة | 70 غير مختبرة |
| W06 | PaymentRefundService::record + TreasuryRegisterService::post؛ PaymentController::recordRefund؛ TreasuryController::post | استرداد وقيدان منفصلان في نطاقين، معاملة واحدة | 71 نقدي فقط غير مختبر؛ البنكي والدليل الخارجي متبقيان |
| W07 | PaymentService::requestVoid/decideVoidRequest؛ PaymentController::void/voidDecision؛ VoidRequestsTable | قرار اعتماد أو رفض؛ القيد العكسي عند الاعتماد فقط | 72 و73 غير مختبرتين؛ الطلب والرفض متبقيان؛ سياسة الصفر والصلاحية تحتاج قراراً |
| W08 | PaymentAccountTransferService::transfer؛ PaymentController::transferAccount | عكس تخصيص كامل السند ونقله، مجموع الطرفين صفر، لا حركة خزينة | 74 نقدي غير مختبر |
| W09 | HistoricalInvoiceCorrectionService::correct/correctReadings؛ InvoiceController::correctHistorical | عكس الأصل وترحيل البديل بالتعرفة التاريخية | 75 غير مختبرة؛ لا اعتماد تاريخي آلي |
| W10 | InvoiceCancellationService::cancel؛ InvoiceController::cancel | عكس الفاتورة وتحرير القراءات وإعادة التوزيع | غير مربوط؛ أولوية عالية |
| W11 | ClosedCashCorrectionService::correctDuplicate؛ CashSessionController::correctDuplicate | عكس إدخال مكرر مثبت، لا تغيير للصندوق المعتمد | غير مربوط؛ يلزم إثبات السندين وقرار التصحيح |
| W12 | CashHandoverService::record + TreasuryRegisterService::post؛ CashSessionController::recordHandover؛ TreasuryController::post | استلام النقدية من الصندوق، دفعات جزئية/كاملة | أدلة 61 مقبولة؛ الكاتب الذري غير مربوط |
| W13 | BankReconciliationService::recordFee؛ BankReconciliationController::recordFee | رسوم بنك سالبة، سطر كشف ومطابقة | أدلة 63 مقبولة؛ الكاتب غير مربوط |
| W14 | TreasuryCashCountService::adjust؛ TreasuryCashCountController::adjust | عجز مثبت أو فائض محتجز، لا إيراد تلقائي | أدلة 63 مقبولة؛ الكاتب غير مربوط |
| W15 | PaymentService::clearNonCashClaim؛ NonCashClaimController::clear | سند وقيد مشترك وقيد خزينة ومطابقة واحدة | أدلة 65 مقبولة؛ الكاتب غير مربوط |
| W16 | PaymentService::clearLateNonCash؛ LateReceiptReviewController::clearBank | تسوية متأخرة، قيد مشترك وبنك | أدلة 65 مقبولة؛ الكاتب غير مربوط |
| W17 | LateReceiptReviewService::receiveCash؛ LateReceiptReviewController::clearCash | نقدية مستلمة فعلاً، قيد مشترك وخزينة | أدلة 64/65؛ الكاتب غير مربوط |
| W18 | LateReceiptReviewService::decide (already_counted)؛ LateReceiptReviewController::decide | قيد مشترك فقط واستهلاك سعة الفائض المحتسب، دون قبض ثانٍ | دليل تخصيص مستقل وإثبات السعة والكاتب متبقية |
| W19 | BankReconciliationService::confirmCollection؛ BankReconciliationController::confirmCollection | ربط تحصيل سابق مثبت بالخزينة دون تكرار ائتمان المشترك | أدلة 64؛ سياسة الانتقال ومصدر السند القديم والكاتب متبقية |

## 2. عمليات تؤثر على الدليل ولو لم تنشئ قيداً

لا تُنشأ قيود بقيمة صفر مصطنعة لتسجيل قرار إداري. تحتاج هذه العمليات حماية نطاق ومعاملة وسجل قرار مستقل، وتحديد التاريخ والإصدار، أو منعاً صريحاً عند التجميد. يسمح فقط بالقراءة المثبتة أثناء التجميد.

| ID | المسار | واجب الرقابة |
|---|---|---|
| E01 | CashSessionService::open/close/decide؛ CashSessionController::store/close/approve | حماية حالة الصندوق ورصيد الإغلاق وعدّ النقد والقرار واستقلال المعتمد |
| E02 | NonCashClaimService::submit/reject؛ NonCashClaimController::store/reject | منع الترحيل قبل التحصيل؛ تثبيت هوية المطالبة وقرار رفضها |
| E03 | LateReceiptReviewService::submit/decide؛ LateReceiptReviewController::store/decide | حماية المراجعة والهوية والسعة والقرار؛ فصل القبول المالي عن رفض الطلب |
| E04 | BankReconciliationService::import/match؛ BankReconciliationController::store/matchLine | ثبات الكشف والمطابقة والفصل بين المستورد والمراجع |
| E05 | TreasuryCashCountService::record/review؛ TreasuryCashCountController::store/review | بصمة العد والرصيد السابق والمراجعة المستقلة |
| E06 | ReadingController::store/update/destroy/bulkDelete/syncBatch؛ Reading model؛ TariffBulkService وTariff model | حماية المدخلات التي تحدد مبلغ الفاتورة وفترتها وتعرفة كل قراءة |
| E07 | SingleMeterService::create/update/replace؛ MeterDataPurger؛ FinancialMeterRetirementService؛ Meter/Subscriber/Square | ثبات هوية العداد المالي بعد حذفه ومنع الحذف المتسلسل المؤثر على الدليل |
| E08 | InvoiceController::update/recalculate/bulkRecalculate/destroy/restore/forceDestroy؛ PaymentController::update/destroy/bulkDelete؛ Filament CRUD | تصنيف كل فرع مسودة/بيانات غير مالية/أثر مالي؛ لا تعديل أو حذف مرحل |
| E09 | PaymentService::recalculateInvoices؛ PaymentAllocationHistory::append؛ RefreshInvoiceStatuses؛ Invoice model hooks | حماية التوزيعات ونسخها وحالات الفواتير؛ منع الكتابات الجانبية خارج السياق |
| E10 | DocumentSequenceService::reserveForIssue؛ TransactionService::recordTransaction/reverseDocument | خدمات داخلية فقط تحت معاملة الكاتب؛ لا نقطة دخول مستقلة |
| E11 | SettingController؛ RoleController؛ UserController؛ SyncPermissionsCommand؛ إعدادات Filament | حماية حدود الاعتماد والتقويم والصلاحيات وأهلية الموظف من سباق الإغلاق |
| E12 | BackupDevice/BackupTick؛ الاستعادة والاستيراد والترحيلات | هوية النسخة وسلامتها، فحص الاستعادة، عزل صلاحيات DDL ومنع الاستعادة أثناء التفعيل |

هذه القائمة لا تعني أن كل متحكم أو hook يكتب مالياً. مسارات Filament والموديلات وroutes والمهام والكتابة الخام تحتاج جرداً تفصيلياً بعد الدمج؛ لا يكفي إثبات متحكمات API.

## 3. قواعد الدمج

1. لا تجمع الفروع غير المختبرة باعتبارها مقبولة. ثبت لكل فرع SHA وشجرة الاختبار وحالة التنفيذ. بعد الاختبارات الفردية دمج تكاملي واحد مع مراجعة التداخل.
2. نقطة دخول عليا واحدة تبدأ المعاملة وتحصل على قفل النطاق ثم الفترة. بعدهما قفل الإصدار ثم أقفال الحسابات بترتيب ID ثابت ثم الموارد التابعة. قفل فترات سابقة تحت قفل النطاق فقط؛ ترتيب الموارد الداخلية يحتاج مراجعة قبل تفعيل الإنتاج.
3. لا تنفذ بوابة متداخلة داخل bulk أو sync. لكل عنصر مالي هوية مستقلة ثابتة؛ لا تفقد حالة العنصر عند فشل عنصر آخر. سياسة الذرية للدفعة الكاملة تحتاج قراراً موثقاً.
4. ربط الواجهات لا يكفي: منع الكتابات المباشرة عبر طبقة الخدمة والموديلات والـ query builder والأوامر المجدولة وبيانات قاعدة البيانات. لا نفترض أن transactionLevel وحده دليل امتلاك القفل.
5. لا تعتبر التحقق من registry hash دليلاً على تغطية كل المصادر. يجب تحديد حد بدء صريح موثق، وجرد مستقل لجميع آثار المصدر بعد الحد، ومطابقة ثنائية الاتجاه (مصدر بلا قيد، قيد بلا مصدر)، ومجموعات أطراف العملية وحقول التاريخ والهوية. لا يُستنتج الحد من تاريخ قراءة أو توقيت مخمن.
6. قرارات لا ترحل مبلغاً لا تسجل كحركة مالية وهمية. يجب تعريف سجل أدلة مستقل ومتسق لقرارات الحالة والأدوار والتهيئة. لا يُستخدم هذا السجل لتجاوز حماية الفترة.
7. قيود الإغلاق لا تُفعل حتى تطبيق مصدر FinancialCloseProofSource موثوق واختبار رفض الأدلة غير المكتملة. تبقى source_coverage_verified=false حتى الإثبات الفعلي.

## 4. بوابات قبول حاسمة

| Gate | الدليل المطلوب | الحالة |
|---|---|---|
| V1 | نتائج كل من 70–75 على SQLite وMySQL 8، SHA دقيق وexit 0، بلا تخطٍ غير مبرر | محجوب تشغيلياً |
| V2 | استكمال W10–W19 وفروع W06–W09 المتبقية؛ نتائج اختبار فعلية | مفتوح |
| V3 | حماية E01–E12، جرد entrypoint-to-writer شامل للنسخة المدمجة، اختبارات رفض تجاوز API/Filament/CLI/model/raw SQL ضمن الصلاحيات المقررة | مفتوح |
| V4 | اختبارات تزامن بمحرك MySQL 8 أصلي: freeze مقابل كتابة قائمة/جديدة، عاملان بنفس UUID، حسابان باتجاه معاكس، إغلاق صندوق مقابل تحصيل، تغيير التعرفة/الحد/الصلاحيات مقابل ترحيل | مفتوح |
| V5 | حماية الأدلة من تعديل أو حذف غير مصرح، جرد مستقل ومطابقة ثنائية، اختبار حذف/إضافة/تعديل أثر متوقع في قاعدة معزولة | مفتوح |
| V6 | إغلاق دائم: رفض نقص المصدر، رفض إعادة فتح أو تعديل مغلق، صحة predecessor hash، replay، انقطاع منتصف الإغلاق، استعادة معزولة | مفتوح |
| V7 | اختبارات Laravel كاملة على المحركين وFlutter وoffline وhost restore/cutover؛ مراجعة الانحرافات وحالات التخطي قبل إذن الإصدار | مفتوح |

## 5. طابور الاختبارات المعلقة (فروع مستقلة)

| Round | SHA | Test class | حالات لكل محرك |
|---|---|---|---:|
| 70 | 34ac201f711518c62064d85fd744c46cf22ae957 | GatedInvoiceWriterTest | 5 |
| 71 | 40092198d2d03130bfc78cd04b45556b5e055025 | GatedCashRefundWriterTest | 5 |
| 72 | c266cf75be841a54c3b9762f40979d12fd04277e | GatedCashVoidApprovalWriterTest | 6 |
| 73 | 1d9c879d1c54245d2289b57f4bb3a0b76d8b9f2a | GatedCashDirectVoidWriterTest | 6 |
| 74 | 1754b30ae9ee392a1f2a81d5f15882ed948bb008 | GatedPaymentAccountTransferWriterTest | 5 |
| 75 | fc3e864fde3ba304e1e1f9e7eda7905aa421f314 | GatedHistoricalInvoiceCorrectionWriterTest | 5 |

32 حالة مُعدة لكل محرك، 64 عملية تنفيذ متوقعة. ليست نتائج نجاح. يراعى عدم تشغيل الاختبارات المدمرة على قاعدة بيانات حقيقية؛ جميعها تستلزم قاعدة اختبار معزولة. قيد الأداة السابق على إنشاء workflow ما زال قائماً؛ لا محاولة لتجاوزه. هذه الجولة مراجعة وتخطيط قبول فقط.

الأولوية التالية: استكمال W10 ثم W11 ثم W12، والتحقق من الفروع المعلقة قبل توسع دمج المنتج؛ لا تفعيل لأي محول.
