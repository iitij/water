#!/usr/bin/env python3
"""توليد/التحقّق من ملف بصمات SHA-256 لملفات الإصدار.

الاستخدام:
    python3 scripts/make_sha256sums.py dist/
    python3 scripts/make_sha256sums.py --verify dist/
    python3 scripts/make_sha256sums.py --algorithm sha512 dist/
    python3 scripts/make_sha256sums.py --files build/a.aab build/windows.msix

الملف الناتج بصيغة `sha256sum` المعروفة (`<hash>  <name>`) حتى يعمل بـ
`sha256sum -c SHA256SUMS` على لينكس/macOS، وبـ`Get-FileHash` على ويندوز.

لماذا هذا السكربت: ملفات الإصدار تُنقل بين أجهزة وواتساب وبريد. بصمة واحدة
مكتوبة بيد بشر لا تثبت شيئاً؛ البصمة تُولَّد من الملف نفسه ويُتحقّق منها آلياً.
"""

from __future__ import annotations

import argparse
import hashlib
import sys
from pathlib import Path

CHECKSUM_NAME = "SHA256SUMS"

# امتدادات الإصدار التي تُبصم — لا نبصم ملفات مصدرية بالخطأ.
RELEASE_SUFFIXES = {
    ".apk",
    ".aab",
    ".msix",
    ".msixbundle",
    ".appx",
    ".exe",
    ".zip",
    ".dmg",
    ".ipa",
}

CHUNK = 1024 * 1024


def digest(path: Path, algorithm: str) -> str:
    hasher = hashlib.new(algorithm)
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(CHUNK), b""):
            hasher.update(chunk)
    return hasher.hexdigest()


def collect(targets: list[Path]) -> list[Path]:
    files: list[Path] = []
    for target in targets:
        if target.is_dir():
            files.extend(
                sorted(
                    item
                    for item in target.rglob("*")
                    if item.is_file()
                    and item.suffix.lower() in RELEASE_SUFFIXES
                    and item.name != CHECKSUM_NAME
                )
            )
        elif target.is_file():
            files.append(target)
        else:
            print(f"تحذير: المسار غير موجود — {target}", file=sys.stderr)
    return files


def write_sums(files: list[Path], output: Path, algorithm: str) -> int:
    if not files:
        print("لا توجد ملفات إصدار للتوقيع.", file=sys.stderr)
        return 1
    base = output.parent
    lines = []
    for file in files:
        try:
            relative = file.relative_to(base)
        except ValueError:
            relative = Path(file.name)
        lines.append(f"{digest(file, algorithm)}  {relative.as_posix()}")
    output.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"كُتب {output} ({len(lines)} ملفاً، {algorithm}).")
    return 0


def verify(sums_file: Path, algorithm: str) -> int:
    if not sums_file.exists():
        print(f"ملف البصمات غير موجود: {sums_file}", file=sys.stderr)
        return 1
    base = sums_file.parent
    failures = 0
    checked = 0
    for raw in sums_file.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split(None, 1)
        if len(parts) != 2:
            print(f"سطر غير صالح: {raw}", file=sys.stderr)
            failures += 1
            continue
        expected, name = parts[0], parts[1].lstrip("*")
        target = base / name
        checked += 1
        if not target.exists():
            print(f"مفقود: {name}", file=sys.stderr)
            failures += 1
            continue
        actual = digest(target, algorithm)
        if actual != expected:
            print(f"مختلف: {name}\n  متوقع {expected}\n  فعلي  {actual}", file=sys.stderr)
            failures += 1
        else:
            print(f"سليم: {name}")
    print(f"تم فحص {checked} ملفاً، فشل {failures}.")
    return 0 if failures == 0 else 1


def main() -> int:
    parser = argparse.ArgumentParser(description="توليد أو التحقّق من بصمات ملفات الإصدار.")
    parser.add_argument("paths", nargs="*", type=Path, default=[Path("dist")])
    parser.add_argument("--verify", action="store_true", help="تحقّق بدل التوليد")
    parser.add_argument("--algorithm", default="sha256", choices=sorted(hashlib.algorithms_guaranteed))
    parser.add_argument("--files", nargs="*", type=Path, help="ملفات صريحة تُبصم كما هي")
    parser.add_argument("--output", type=Path, help="مسار ملف البصمات (افتراضي: بجوار الملفات)")
    args = parser.parse_args()

    targets = args.files or args.paths or [Path("dist")]
    if args.verify:
        sums = args.output or (targets[0] / CHECKSUM_NAME if targets[0].is_dir() else targets[0])
        return verify(sums, args.algorithm)

    files = collect(targets)
    if args.output:
        output = args.output
    elif len(targets) == 1 and targets[0].is_dir():
        output = targets[0] / CHECKSUM_NAME
    else:
        output = Path(CHECKSUM_NAME)
    return write_sums(files, output, args.algorithm)


if __name__ == "__main__":
    raise SystemExit(main())
