#!/usr/bin/env bash
# خط أساس تحذيرات التحليل (خطة 2.0 — S1، بطاقة S1-08).
#
# السلوك:
#  - إذا كان baseline.txt يحتوي UNMEASURED: تُطبع النتيجة ويُمرَّر البناء مع تحذير،
#    حتى يُسجّل الفريق أول قياس حقيقي (لا نخترع رقماً لا نعرفه).
#  - بعد تسجيل الرقم: يفشل البناء عند أي زيادة.
set -euo pipefail

cd "$(dirname "$0")/../../mobile app"

baseline_file="../tool/ci/analyze_baseline.txt"
baseline=$(head -n1 "$baseline_file" 2>/dev/null | tr -d '[:space:]' || echo "UNMEASURED")

output=$(flutter analyze --no-pub 2>&1 || true)
count=$(printf '%s\n' "$output" | grep -cE '^\s*(info|warning|error)\s+•' || true)

printf 'تحذيرات/ملاحظات التحليل: %s\n' "$count"
printf '%s\n' "$output" | tail -n 20

if [ "$baseline" = "UNMEASURED" ] || [ -z "$baseline" ]; then
  printf '⚠ خط الأساس غير مسجّل بعد. سجّل الرقم الحالي في %s ثم فعّل البوابة.\n' "$baseline_file"
  exit 0
fi

if [ "$count" -gt "$baseline" ]; then
  printf '✗ زيادة في التحذيرات: %s > %s\n' "$count" "$baseline"
  exit 1
fi

printf '✓ لا زيادة على خط الأساس (%s).\n' "$baseline"
