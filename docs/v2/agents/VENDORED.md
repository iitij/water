# أدوار ومهارات مستوردة (Vendored)

استُنسخت هذه الملفات من مستودعات مفتوحة لتُستخدم كتعليمات جاهزة للوكلاء، مع الحفاظ
على الترخيص الأصلي. لا تُعدَّل نصوصها الأصلية؛ أي تخصيص لمشروعنا يُكتب في
`.claude/agents/water-*.md` أو في `docs/v2/agents/README.md`.

## 1. أدوار (Subagents)

المصدر: [`wshobson/agents`](https://github.com/wshobson/agents) — رخصة MIT،
حقوق النشر (c) 2024 Seth Hobson. نص الترخيص محفوظ في
`docs/v2/agents/vendor/wshobson-agents/LICENSE`.

| الملف المستورد | يُستخدم عندنا في |
|---|---|
| `backend-architect.md` | A6 (تصميم مسارات وموارد Laravel) |
| `test-automator.md` | A10 (هرم الاختبارات وأتمتة الاختبارات) |
| `security-auditor.md` | A2 (مراجعة الجلسة والصلاحيات ونموذج التهديد) |
| `performance-engineer.md` | A6/A5 (فهارس، N+1، أداء القوائم الكبيرة) |
| `flutter-expert.md` | A4/A8 (Dart/Flutter وبنية الميزات) |
| `ui-ux-designer.md` | A9 (التصميم، RTL، إمكانية الوصول) |
| `database-optimizer.md` | A5 (Drift/SQLite والفهارس) |
| `code-reviewer.md` | A1 (مراجعة قبل الدمج) |
| `devops-troubleshooter.md` | A3 (CI، البناء، التوقيع، البيئة) |
| `context-manager.md` | A1 (إدارة سياق الفريق وتسليم المهام) |

## 2. مهارات (Skills)

المصدر: [`anthropics/skills`](https://github.com/anthropics/skills) — رخص المهارات
محفوظة داخل كل مجلد (`LICENSE.txt`).

| المهارة | الاستخدام عندنا |
|---|---|
| `webapp-testing` | A10: اختبار الواجهات وتشغيل سيناريوهات E2E عند الحاجة |
| `docx` | A1/A9: توليد مستندات Word عربية للتسليم والتدريب |

المصدر الثاني: `wshobson/agents` → `plugins/agent-teams/skills/`:

| المهارة | الاستخدام عندنا |
|---|---|
| `team-composition-patterns` | A1: تكوين الفرق وتوزيع الأدوار |
| `task-coordination-strategies` | A1: تنسيق المهام ومنع التعارض |
| `parallel-feature-development` | A4+A6+A10: العمل المتوازي على ميزة عبر طبقات |

## 3. قاعدة التحديث

- لا يُستورد ملف بلا ترخيص واضح.
- عند تحديث مستودع خارجي: احفظ رقم الالتزام (commit SHA) في هذا الملف.
- المستورد الآن: `wshobson/agents@` و`anthropics/skills@` (نسخة اليوم 2026-10-24،
  بلا تعديل).
