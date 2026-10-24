#!/usr/bin/env bash
# فحص الأسرار قبل الدمج (خطة 2.0 — الفصل 12.3، بطاقة S1-08).
# يفشل إذا وُجد مفتاح توقيع أو .env أو توكن مكتوب داخل ملفات المستودع.
set -euo pipefail

fail=0

say() { printf '%s\n' "$*"; }

# 1) ملفات لا يجوز تتبّعها إطلاقاً
for pattern in '*.jks' '*.keystore' 'android/key.properties' '**/.env' '.env' '*.pem' '*.p12'; do
  found=$(git ls-files -- "$pattern" 2>/dev/null || true)
  if [ -n "$found" ]; then
    say "✗ ملف سرّي متتبَّع في Git: $pattern"
    say "$found"
    fail=1
  fi
done

# 2) أنماط توكنات شائعة داخل الملفات المتتبَّعة
scan=$(git ls-files -- '*.dart' '*.php' '*.yaml' '*.yml' '*.gradle' '*.kts' '*.json' \
  | grep -vE '(^|/)docs/' \
  | xargs -r grep -nE '(AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{36}|sk-[A-Za-z0-9]{20,}|-----BEGIN (RSA |EC )?PRIVATE KEY-----)' 2>/dev/null || true)
if [ -n "$scan" ]; then
  say "✗ نمط سرّ محتمل في الملفات المتتبَّعة:"
  say "$scan"
  fail=1
fi

# 3) كلمات مرور مثبتة داخل الكود (استثناء ملفات الاختبار والـ seeders)
scan_pw=$(git ls-files -- '*.dart' '*.php' \
  | grep -vE '(^|/)(test|tests)/|Seeder|\.g\.dart' \
  | xargs -r grep -nE "(password|secret|token)\s*[:=]\s*['\"][^'\"]{12,}['\"]" 2>/dev/null || true)
if [ -n "$scan_pw" ]; then
  say "✗ كلمة مرور/سرّ مكتوب داخل الكود:"
  say "$scan_pw"
  fail=1
fi

if [ "$fail" -eq 0 ]; then
  say "✓ لا أسرار متتبَّعة ولا أنماط مفاتيح في الكود."
fi
exit "$fail"
