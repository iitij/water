# G1B-I01 — حجز مساحة عدادات الإقفال (الجولة الرابعة E)
**التفويض:** [1c4e426](https://github.com/iitij/water/blob/1c4e426/docs/coordination/TASK_REGISTER.json). النطاق: إصلاح مؤكد لمحرك السياسة المعزول فقط. المرجع 7f32b30c80514c958615c479c033a1be393b3a1b (تصميم PR74) من 3bbcfb5؛ كلا الفرعين محفوظان. الفرع fix/team-a-g1b-counter-headroom-round4e. B المالك الحصري للخدمات المشتركة. لا migration أو قاعدة بيانات أو API أو تفعيل أو دمج.

## الإثبات قبل الإصلاح
نسخة PHP 8.4.24 x64 في بيئة المحادثة المعزولة، لا MSI أو Codex cloud. استرجعت المحرك حرفيًا وبصمة git hash-object المطابقة لنسخة 7f32b30: 2824797abf3add4943a6adc143cbfcab5f0625a2. الاختبار الجديد tests/Unit/financial_close_counter_headroom_check.php، blob ec254c5fc3453cf8da8597790146ca28344184a7. صُحح خطأ صياغة وسيط في قيمة default argument للاختبار قبل القياس؛ نفس ملف الاختبار النهائي دون تغيير استُخدم في القياسين.
- الأمر: php tests/Unit/financial_close_counter_headroom_check.php
- BEFORE: exit 1; 40 cases / 27 passed / 91 checks / 13 failures / 0 skips.
- grid headroom (MAX-version, MAX-epoch) = (1,1),(1,2),(1,3),(2,1),(2,2),(2,3),(3,1),(4,1): freeze قبل الإصلاح يقبل حالات غير آمنة. (2,2)/(2,3) يسمح abort قبل prepare ولكن prepare يستهلك آخر version ويمنع approve وabort. الحالات الست الأخرى لا تستطيع abort حتى قبل prepare. النتائج من استدعاء public pure methods، لا استنتاج من النص فقط.
- ثلاث حالات إعادة freeze بعد abort تقبل محاولة جديدة دون مساحة كافية: initial (3,3) unprepared abort و(4,3) prepared/unprepared abort.
- حالتان prepare في حالة FROZEN اصطناعية: version=MAX-1 (آخر خطوة تصلح للإلغاء) أو epoch=MAX (تحرير مستحيل) كانتا مقبولتين.
لا يوجد إثبات لحالة إنتاجية عالقة، المحرك لم يُفعّل أصلًا.

## الإصلاح المحدود
لتكن M=PHP_INT_MAX=9223372036854775807، V=version وE=epoch. لا جمع قبل التحقق من الحدود:
- freeze: require E <= M-2 وV <= M-3. التجميد يزيد كليهما 1؛ prepare يزيد V مرة؛ approve أو abort يزيد V وE مرة. إذًا أطول مسار يحتاج 3 versions و2 epochs، والمسار القصير freeze→abort يحتاج 2 versions و2 epochs. تحجز السياسة الأطول دائمًا. الخطأ counter_overflow الحالي، لا تغيير لرموز الخطأ أو عقد الخرج.
- prepare: require E_frozen < M وV_frozen <= M-2 قبل بناء snapshot، بما يحفظ آخر خطوة للقرار النهائي. حالة قديمة اصطناعية V=M-1,E<M ترفض prepare لكن abort المستقل يبقى ممكنًا. لا تغيير approve/abort/replay/increment؛ لا reset أو clamp أو wrap. حالة hydrated عالقة بالفعل E=M أو V=M لا تُصلح بالقوة، تُعامل كخلل سلامة وتحتاج قرار استعادة مصرح به قبل أي تفعيل. لا محاولة تخفيض العداد أو إعادة فتح CLOSED.
- حالة الحد المقبول V=M-3,E=M-2 تكمل freeze→prepare→approve إلى V=M,E_release=M؛ replay المطابق read-only لا يستهلك عدادًا؛ abort قبل/بعد prepare يصل إلى OPEN ويحفظ سجل المحاولة. محاولة جديدة دون headroom ترفض، ولو كانت الحالة OPEN.
- ليست هذه سياسة استنفاد طويلة الأمد لنطاق مالي دائم: الوصول إلى الحد عند release ممكن وآمن لدورة حالية، لكن محاولة تجميد لاحقة ترفض. مراقبة headroom مبكرًا وخطة ترقية نطاق العدادات معتمدة لازمة قبل الإنتاج، دون تغيير تصميم أو مخطط الآن.

## النتائج بعد الإصلاح
- php -l app/Support/FinancialClosePolicy.php — PASS.
- php -l tests/Unit/financial_close_counter_headroom_check.php — PASS.
- نفس أمر CLI ونفس الاختبار: AFTER exit 0; 40 cases / 40 passed / 102 checks / 0 failures / 0 skips.
- 20 حالة grid، 15 حالة approve/abort/replay عند الحدود وnormal headroom، 5 حالات prepare/normal/invalid epoch. الاختبارات تحقق no input mutation عند رفض freeze/prepare، تطابق increments، حفظ snapshot/carry history of aborted attempt، exact replay at max، ورفض freeze التالي. لا قاعدة بيانات أو خدمات أو شبكات أو مستخدم حقيقي.
- الالتزام المختبر بمطابقة البصمات: 6b2e4dcbed3adf4acd2dbdddf91b32f35555bf57. blob الجديد للمحرك c9695d1c3c3a3eb673314bff4fb624102572d729، blob الاختبار ec254c5fc3453cf8da8597790146ca28344184a7. GitHub fetch verified both. الوثيقة المضافة بعد ذلك فقط. نتائج 91/102 من 4C تبقى عند SHA السابق ولا تُجمع مع 40/102 على أنها تشغيل للمرشح الجديد. لم أعد تشغيل المجموعة القديمة أو Laravel/SQLite/MySQL/Flutter/CI؛ هذه مراجعة focused فقط، D يراجع قبل إدراج المحرك في المرشح.
- G1B-I01 policy-level fixed/REVIEW، I02–I07 وCLOSE-01..12 durable integration وA01-10..13 لا تزال معلقة. يجب أن يستخرج المحول المستقبلي V/E من الصفوف المحمية تحت gate، ويحقق نفس headroom وCAS قبل commit، لا من API؛ شرط scope gate first, sequence last وتصميم 7f32b30 محفوظان. B لا يغير ترتيبه أو خدماته بسبب هذه الشريحة. لا main merge، لا تغيير register، لا إغلاق issue.
