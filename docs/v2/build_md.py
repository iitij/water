# -*- coding: utf-8 -*-
"""Emits a Markdown edition of the plan (docs/V2_MOBILE_PLAN_AR.md)."""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

import content_a  # noqa: E402
import content_b  # noqa: E402
import content_c  # noqa: E402
import content_d  # noqa: E402

BLOCKS = (content_a.BLOCKS + content_b.BLOCKS + content_c.BLOCKS
          + content_d.BLOCKS)


def table_md(headers, rows):
    out = ["| " + " | ".join(headers) + " |",
           "| " + " | ".join("—" for _ in headers) + " |"]
    for row in rows:
        out.append("| " + " | ".join(str(c) for c in row) + " |")
    return "\n".join(out)


def render() -> str:
    lines: list[str] = []
    for block in BLOCKS:
        kind, args = block[0], block[1:]
        if kind == "h1":
            lines += ["", f"## {args[0]}", ""]
        elif kind == "h2":
            lines += ["", f"### {args[0]}", ""]
        elif kind == "h3":
            lines += ["", f"#### {args[0]}", ""]
        elif kind == "lead":
            lines += ["", f"> {args[0]}", ""]
        elif kind == "p":
            lines += ["", args[0], ""]
        elif kind == "bullets":
            lines += [""] + [f"- {i}" for i in args[0]] + [""]
        elif kind == "numbers":
            lines += [""] + [f"{n}. {i}" for n, i in enumerate(args[0], 1)] + [""]
        elif kind == "table":
            headers, rows = args[0], args[1]
            caption = args[3] if len(args) > 3 else None
            if caption:
                lines += ["", f"**{caption}**", ""]
            lines += ["", table_md(headers, rows), ""]
        elif kind == "callout":
            title, body, _kind = args
            lines += ["", f"> **{title}**", ">", f"> {body}", ""]
        elif kind == "code":
            caption = args[1] if len(args) > 1 else None
            if caption:
                lines += ["", f"**{caption}**", ""]
            lines += ["", "```text", args[0].strip("\n"), "```", ""]
        elif kind == "spacer":
            lines.append("")
        elif kind == "pagebreak":
            lines += ["", "---", ""]
        else:  # pragma: no cover
            raise ValueError(kind)
    return "\n".join(lines)


HEADER = """# خطة الإصدار الثاني (2.0) — تطبيق الهاتف Flutter

**المشروع:** منظومة إدارة مشاريع المياه (`Water Project`) · **الإصدار المستهدف:** 2.0.0
**تاريخ الوثيقة:** 24 أكتوبر 2026 · **الأساس:** الفرع `arena/7f48d908-water`
**النسخة القابلة للطباعة:** [`V2_MOBILE_PLAN_AR.pdf`](V2_MOBILE_PLAN_AR.pdf) · **النسخة القابلة للتحرير:** `V2_MOBILE_PLAN_AR.docx`

> هذا الملف مُولَّد آلياً من `docs/v2/content_*.py`. عدّل المصدر ثم أعد البناء:
> `python docs/v2/build.py docs/V2_MOBILE_PLAN_AR.pdf`

---
"""


def main() -> None:
    out = Path(__file__).resolve().parents[1] / "V2_MOBILE_PLAN_AR.md"
    out.write_text(HEADER + "\n" + render() + "\n", encoding="utf-8")
    text = out.read_text(encoding="utf-8")
    print(f"{out.name}: {len(text.splitlines())} lines, {len(text):,} chars")


if __name__ == "__main__":
    main()
