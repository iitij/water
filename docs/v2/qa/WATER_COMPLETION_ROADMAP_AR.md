# الخطة الموحدة لإكمال Water — 24 نوفمبر 2026

مرجع المتابعة: [#54](https://github.com/iitij/water/issues/54). فرع العمل `fix/observation-period-v2` في [PR #53](https://github.com/iitij/water/pull/53)، خط أساس `9892603f680901015be0881bd3b5299dc0aefb52`. هذه الخطة تنظم العمل ولا تثبت قبول الشيفرة. سجل `docs/v2/agents/backlog.json` التاريخي يبقى محفوظاً بحالاته الأصلية (136 بطاقة، 91 done، 14 partial، 30 todo، 1 blocked). ربط جميع البطاقات المفتوحة الـ45 أدناه يمنع عدّها مرة أخرى كمشروعات مستقلة.

## الترتيب الإلزامي

| البوابة | العمل | المرجع | الحالة |
|---|---|---|---|
| G0 | استعادة الاختبارات وإصلاح الانحدارات على SHA محدد | #55 | blocked: CI لا يبدأ |
| G1A | إكمال مصادر حركة الخزينة والتسويات | #56 | pending G0 |
| G1B | حماية الخزينة والمطابقة والإقفال | #57 | pending G1A |
| G2 | القراءات والعداد الواحد والميزات المحذوفة | #44 #43 #40 #39 #41 #42 | pending G1; اختبارات مشتركة مع G4 |
| G3A | Filament وFlutter والتقارير والصلاحيات | #58 | pending G2 |
| G3B | محرك الطباعة المؤجل | #59 | pending G3A |
| G4 | الترقية والاسترجاع المعزول والقبول الميداني | #60 #37 | pending G3 |
| G5 | مهام ما قبل الإنتاج | #19–#30 | deferred by owner |
| G6 | قبول المالك والإصدار والدعم | #61 | pending G5 |

تُنفذ الإصلاحات الخاصة بالاختبارات والترحيلات في البيئة المعزولة عند الحاجة قبل G4؛ تُغلق مهام G2 فقط بعد استيفاء جميع أدلتها بما فيها الأدلة المشتركة مع G4. لا تستعمل التبعية لترحيل اختبار ضروري. الإصلاحات الأمنية المؤجلة تبقى مؤجلة باستثناء ما يلزم لصحة الاختبار أو حماية بيانات فعلية؛ لا توقيع أو نشر قبل G5. لا إضافة ميزات اختيارية أثناء G0.

## شروط القبول المشتركة
- SHA وتاريخ وبيئة اختبار محددة، أوامر ونتائج حقيقية وحالات رفض واختبارات تزامن عند الحاجة.
- إثبات Laravel / MySQL 8 / SQLite / Flutter، والقبول الميداني ليس بديلاً عنه CI.
- لا تغيير قاعدة المستخدم الحقيقية ولا إنشاء أرصدة أو تسويات تاريخية مفترضة. النسخة المعزولة فقط، مع خطة استرجاع.
- لا دمج PR أو نشر حتى بوابات القبول المناسبة وموافقة المالك على النشر.
- لا إزالة اختبار أو تعطيل شرط أمان للحصول على نجاح ظاهري. لا إغلاق لمجرد وجود كود.
- الرصيد المسجل للخزينة جزئي حتى إكمال مصادره، ولا يجوز إقفال فترة أو اعتمادها قبل المطابقة.
- تحفظ القيود المرحّلة ويكون التصحيح بمستند مستقل. شروط قديمة في #37/#39 قد تطلب حذف قيود مالية؛ هذه نقطة تعارض يجب مراجعتها وتحديثها قبل القبول. حذف العداد الفعلي ليس حذف دفتر المشترك.
- S7-11/OWN-05 قرار محاسبي مفتوح: لا يُنقل رصيد مشترك ضمنياً بنقل عداد. يلزم توثيق العقد قبل التنفيذ.
- الاستيراد البنكي الآلي وتعدد العملات ليسا شرطاً إلزامياً حالياً دون اعتماد منفصل.

## ربط البطاقات التاريخية المفتوحة (45)

| البطاقة | حالة السجل القديمة | المهمة | مرجع القبول |
|---|---|---|---|
| S1-01 | partial | توليد keystore إنتاجي وحفظه خارج Git | [#61](https://github.com/iitij/water/issues/61) |
| S1-07 | partial | أول اختبار تكامل (دخول + قائمة مشتركين) | [#60](https://github.com/iitij/water/issues/60) |
| S5-02 | partial | تشفير قاعدة البيانات (SQLCipher) | [#24](https://github.com/iitij/water/issues/24) |
| S5-04 | partial | توطين الأسطح الأساسية | [#58](https://github.com/iitij/water/issues/58) |
| S6-01 | partial | مصفوفة الأجهزة الكاملة | [#60](https://github.com/iitij/water/issues/60) |
| S6-02 | todo | اختبار ميداني مع مستخدمين | [#37](https://github.com/iitij/water/issues/37) |
| S6-03 | partial | إصلاحات ما قبل الإطلاق | [#60](https://github.com/iitij/water/issues/60) |
| S6-04 | partial | دليل المستخدم والتدريب | [#61](https://github.com/iitij/water/issues/61) |
| S6-05 | partial | نشر متدرّج (5% ← 25% ← 100%) | [#61](https://github.com/iitij/water/issues/61) |
| S6-06 | partial | خطة دعم أسبوعين بعد الإطلاق | [#61](https://github.com/iitij/water/issues/61) |
| S7-09 | partial | تذكيرات محلية مجدولة | [#58](https://github.com/iitij/water/issues/58) |
| S7-11 | blocked | نقل ملكية عدّاد بين مشتركين | [#40](https://github.com/iitij/water/issues/40) |
| S8-02 | partial | ترحيل بقية نصوص الشاشات القديمة | [#58](https://github.com/iitij/water/issues/58) |
| S8-03 | partial | تحديث واجهات العدادات والقراءات والمسار | [#58](https://github.com/iitij/water/issues/58) |
| S9-02 | partial | سياسة الخصوصية وأجوبة Data safety | [#61](https://github.com/iitij/water/issues/61) |
| S9-03 | partial | MSIX موقّع وSHA256SUMS وملاحظات إصدار عربية | [#61](https://github.com/iitij/water/issues/61) |
| OWN-01 | todo | تثبيت نسخة العمل وإغلاق فشل التحليل والاختبارات عبر المالكين | [#55](https://github.com/iitij/water/issues/55) |
| OWN-02 | todo | مصفوفة كل إجراءات الويب المطلوبة مقابل الموبايل | [#58](https://github.com/iitij/water/issues/58) |
| OWN-03 | todo | خادم مستمر آمن مستقل عن لابتوب المالك ونسخ واسترجاع | [#60](https://github.com/iitij/water/issues/60) |
| OWN-04 | todo | سياسة صندوق واضحة عند الغياب والإغلاق والانقطاع | [#56](https://github.com/iitij/water/issues/56) |
| OWN-05 | todo | حماية نقل ملكية العداد لحين القرار المحاسبي ثم تنفيذ العقد | [#40](https://github.com/iitij/water/issues/40) |
| OWN-06 | todo | استعادة محلية آمنة وهوية المشروع وحماية العمليات الأحدث | [#60](https://github.com/iitij/water/issues/60) |
| OWN-07 | todo | رئيسية المالك وأسماء وحالات مفهومة وعمر الأرقام | [#58](https://github.com/iitij/water/issues/58) |
| OWN-08 | todo | رحلة توصيل مشترك مع العداد والافتتاحي والتعرفة والرسوم | [#44](https://github.com/iitij/water/issues/44) |
| OWN-09 | todo | رحلة الفوترة والتحصيل واعتماد حساب المحصلين من الهاتف | [#58](https://github.com/iitij/water/issues/58) |
| OWN-10 | todo | تجهيز دخول المالك وربط المشروع وحماية البيانات والجهاز | [#61](https://github.com/iitij/water/issues/61) |
| OWN-11 | todo | حدود أوفلاين صادقة واختبار فقد الرد وإعادة التشغيل | [#37](https://github.com/iitij/water/issues/37) |
| OWN-12 | todo | إكمال وصول المالك لسجل التغييرات والخدمات الإدارية الناقصة | [#58](https://github.com/iitij/water/issues/58) |
| OWN-13 | todo | دورة مالية متكاملة على خادم اختبار وهاتف وطابعة المالك | [#60](https://github.com/iitij/water/issues/60) |
| OWN-14 | todo | قبول صاحب المشروع نفسه من الجوال دون الويب | [#61](https://github.com/iitij/water/issues/61) |
| OWN-15 | todo | إصدار Android إنتاجي وتحديث مجرب وبوابات CI | [#61](https://github.com/iitij/water/issues/61) |
| OWN-16 | todo | تسليم وتشغيل مراقب ودعم واضح | [#61](https://github.com/iitij/water/issues/61) |
| PREPROD-01 | todo | حماية وصول وطباعة الفواتير وسندات القبض | [#19](https://github.com/iitij/water/issues/19) |
| PREPROD-02 | todo | حماية سجلات API وتنقيح الاستجابات من توكنات الدخول | [#20](https://github.com/iitij/water/issues/20) |
| PREPROD-03 | todo | عزل حساب المدير التجريبي وتهيئة Docker عن النشر | [#21](https://github.com/iitij/water/issues/21) |
| PREPROD-04 | todo | تصنيف وتنقيح نسخ SQL وبيانات API الموجودة في Git | [#22](https://github.com/iitij/water/issues/22) |
| PREPROD-05 | todo | ربط توكن الجلسة بإبطال الجهاز ومنع تجاوز X-Device-Id | [#23](https://github.com/iitij/water/issues/23) |
| PREPROD-06 | todo | تفعيل تشفير SQLite المحلي والتحقق من الترحيل في الإنتاج | [#24](https://github.com/iitij/water/issues/24) |
| PREPROD-07 | todo | توحيد حذف وإلغاء العمليات المحاسبية وسجل التدقيق | [#25](https://github.com/iitij/water/issues/25) |
| PREPROD-08 | todo | إضافة CI والتحقق من توقيع Release الإنتاجي | [#26](https://github.com/iitij/water/issues/26) |
| PREPROD-09 | todo | تحسين دقة العمليات المالية بدلاً من float | [#27](https://github.com/iitij/water/issues/27) |
| PREPROD-10 | todo | تقييد معدل طلبات المصادقة وتسجيل الدخول | [#28](https://github.com/iitij/water/issues/28) |
| PREPROD-11 | todo | تقييد وصول غير المخوّلين إلى لوحة Filament | [#29](https://github.com/iitij/water/issues/29) |
| PREPROD-12 | todo | تثبيت HTTPS وسياسة الاتصالات الآمنة لبناء الإنتاج | [#30](https://github.com/iitij/water/issues/30) |
| STRICT-METER-FINAL-AUDIT-20261114-01 | todo | إغلاق نتائج التدقيق النهائي التي تمنع ضمان صحة 100%: تنظيف SharedPreferences لسجلات الأعطال والزيارات، معالجة الدفعات الميدانية للعداد المحذوف دون حجب العناصر الصحيحة، اختبار تزامن MySQL فعلي، اختبار فشل DDL وترحيل قاعدة اختبار، وقبول Android/Windows والصور | [#40](https://github.com/iitij/water/issues/40) |

## دليل العائق الحالي
تشغيل [45486388739](https://github.com/iitij/water/actions/runs/45486388739) و[45486388735](https://github.com/iitij/water/actions/runs/45486388735): فشل قبل تعيين runner، صفر خطوات. السبب الجذري غير مؤكد. لا يُسجّل فشل الاختبارات نفسها قبل تشغيلها. مهمة #55 هي أول عمل، ثم يسجل دليل التشغيل وإصلاح الانحدارات.

مرجع نطاق المحاسبة التفصيلي: `docs/accounting/observation-period-rollout.md`. تاريخ Git وخطة backlog السابقة محفوظان.