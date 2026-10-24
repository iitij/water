# أدوات CI

| الأداة | الغرض | التشغيل |
|---|---|---|
| `check_secrets.sh` | يفشل إذا وُجد مفتاح/`.env`/توكن متتبَّع | `bash tool/ci/check_secrets.sh` |
| `check_analyze_baseline.sh` | يمنع زيادة تحذيرات `flutter analyze` على خط الأساس | `bash tool/ci/check_analyze_baseline.sh` |

## تسجيل خط الأساس أول مرة

```bash
cd "mobile app"
flutter analyze --no-pub 2>&1 | grep -cE '^\s*(info|warning|error)\s+•'
# ضع الرقم الناتج وحده في tool/ci/analyze_baseline.txt بدل UNMEASURED
```
