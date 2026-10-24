# -*- coding: utf-8 -*-
"""Emits an editable Word edition (docs/V2_MOBILE_PLAN_AR.docx).

Every paragraph is right-aligned, marked RTL (w:bidi / w:rtl), tables are
right-to-left (w:bidiVisual) and use the same accent colour as the PDF.
"""

from __future__ import annotations

import sys
from pathlib import Path

from docx import Document
from docx.enum.section import WD_SECTION
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor

sys.path.insert(0, str(Path(__file__).parent))

import content_a  # noqa: E402
import content_b  # noqa: E402
import content_c  # noqa: E402
import content_d  # noqa: E402

BLOCKS = (content_a.BLOCKS + content_b.BLOCKS + content_c.BLOCKS
          + content_d.BLOCKS)

ACCENT = RGBColor(0x0C, 0x6B, 0x60)
INK = RGBColor(0x0F, 0x1A, 0x18)
MUTED = RGBColor(0x78, 0x85, 0x7F)
FONT = "IBM Plex Sans Arabic"
MONO = "Consolas"


def para(doc, text="", size=10.5, bold=False, color=INK, align=WD_ALIGN_PARAGRAPH.RIGHT,
         space_after=4, space_before=0, font=FONT, style=None):
    p = doc.add_paragraph(style=style)
    p.alignment = align
    pf = p.paragraph_format
    pf.space_after = Pt(space_after)
    pf.space_before = Pt(space_before)
    pf.line_spacing = 1.25
    ppr = p._p.get_or_add_pPr()
    bidi = OxmlElement("w:bidi")
    ppr.append(bidi)
    if text:
        run = p.add_run(text)
        run.bold = bold
        run.font.size = Pt(size)
        run.font.color.rgb = color
        run.font.name = font
        rpr = run._element.get_or_add_rPr()
        cs = OxmlElement("w:rFonts")
        cs.set(qn("w:cs"), font)
        cs.set(qn("w:ascii"), font)
        cs.set(qn("w:hAnsi"), font)
        rpr.insert(0, cs)
        sz = OxmlElement("w:szCs")
        sz.set(qn("w:val"), str(int(size * 2)))
        rpr.append(sz)
        rtl = OxmlElement("w:rtl")
        rtl.set(qn("w:val"), "1")
        rpr.append(rtl)
    return p


def shade(cell, hex_color: str) -> None:
    tcpr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), hex_color)
    tcpr.append(shd)


def add_table(doc, headers, rows, caption=None, widths=None):
    if caption:
        para(doc, caption, size=8.5, bold=True, color=MUTED, space_before=6, space_after=2)
    table = doc.add_table(rows=1, cols=len(headers))
    table.style = "Table Grid"
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    tblpr = table._tbl.tblPr
    bidi = OxmlElement("w:bidiVisual")
    tblpr.append(bidi)
    hdr = table.rows[0].cells
    for i, head in enumerate(headers):
        hdr[i].text = ""
        p = hdr[i].paragraphs[0]
        p.alignment = WD_ALIGN_PARAGRAPH.RIGHT
        run = p.add_run(head)
        run.bold = True
        run.font.size = Pt(8.5)
        run.font.color.rgb = RGBColor(0xFF, 0xFF, 0xFF)
        run.font.name = FONT
        rpr = run._element.get_or_add_rPr()
        rtl = OxmlElement("w:rtl")
        rtl.set(qn("w:val"), "1")
        rpr.append(rtl)
        shade(hdr[i], "0C6B60")
    for row_idx, row in enumerate(rows):
        cells = table.add_row().cells
        for i, value in enumerate(row):
            cells[i].text = ""
            p = cells[i].paragraphs[0]
            p.alignment = WD_ALIGN_PARAGRAPH.RIGHT
            run = p.add_run(str(value))
            run.font.size = Pt(8.5)
            run.font.color.rgb = INK
            run.font.name = FONT
            rpr = run._element.get_or_add_rPr()
            rtl = OxmlElement("w:rtl")
            rpr.append(rtl)
            if row_idx % 2 == 1:
                shade(cells[i], "EDF1EF")
    para(doc, "", size=4, space_after=2)


def add_code(doc, text, caption=None):
    if caption:
        para(doc, caption, size=8.5, bold=True, color=MUTED, space_before=6, space_after=2)
    table = doc.add_table(rows=1, cols=1)
    table.style = "Table Grid"
    cell = table.rows[0].cells[0]
    shade(cell, "F6F8F7")
    cell.text = ""
    first = True
    for line in text.strip("\n").split("\n"):
        p = cell.paragraphs[0] if first else cell.add_paragraph()
        first = False
        p.alignment = WD_ALIGN_PARAGRAPH.LEFT
        p.paragraph_format.space_after = Pt(0)
        p.paragraph_format.line_spacing = 1.0
        run = p.add_run(line if line else " ")
        run.font.size = Pt(7.5)
        run.font.name = MONO
        run.font.color.rgb = RGBColor(0x46, 0x53, 0x4F)
    para(doc, "", size=4, space_after=2)


def add_callout(doc, title, body, kind):
    colors = {"info": "EEF4F8", "warning": "FAF3E8", "danger": "FBEFF0",
              "success": "EAF5EF", "accent": "E4EFEC"}
    table = doc.add_table(rows=1, cols=1)
    table.style = "Table Grid"
    cell = table.rows[0].cells[0]
    shade(cell, colors.get(kind, "EEF4F8"))
    cell.text = ""
    p = cell.paragraphs[0]
    p.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    run = p.add_run(title)
    run.bold = True
    run.font.size = Pt(9.5)
    run.font.color.rgb = ACCENT
    run.font.name = FONT
    rpr = run._element.get_or_add_rPr()
    rpr.append(OxmlElement("w:rtl"))
    p2 = cell.add_paragraph()
    p2.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    run2 = p2.add_run(body)
    run2.font.size = Pt(9)
    run2.font.color.rgb = RGBColor(0x46, 0x53, 0x4F)
    run2.font.name = FONT
    run2._element.get_or_add_rPr().append(OxmlElement("w:rtl"))
    para(doc, "", size=4, space_after=2)


def build(out_path: Path) -> None:
    doc = Document()
    # Page setup: A4, mirrored margins, RTL section
    section = doc.sections[0]
    section.page_width = Cm(21)
    section.page_height = Cm(29.7)
    section.left_margin = Cm(2.0)
    section.right_margin = Cm(2.0)
    section.top_margin = Cm(2.2)
    section.bottom_margin = Cm(2.0)
    sect_pr = section._sectPr
    bidi = OxmlElement("w:bidi")
    sect_pr.append(bidi)

    style = doc.styles["Normal"]
    style.font.name = FONT
    style.font.size = Pt(10.5)

    # ── cover ─────────────────────────────────────────────────────────────
    para(doc, "خطة الإصدار الثاني", size=30, bold=True, space_before=90, space_after=6)
    para(doc, "تطبيق الهاتف Flutter لمنظومة إدارة مشاريع المياه", size=15,
         color=ACCENT, space_after=24)
    for label, value in [
        ("الإصدار المستهدف", "2.0.0 — إصدار إنتاجي أول"),
        ("الأساس الحالي", "1.0.0+1 · الفرع arena/7f48d908-water"),
        ("تاريخ الوثيقة", "24 أكتوبر 2026"),
        ("حالة الوثيقة", "مسودة معتمدة للتنفيذ — تحتاج توقيع مالك المنتج"),
        ("مدة الخطة", "12 أسبوعاً · 6 دورات تطوير"),
    ]:
        para(doc, f"{label}: {value}", size=10, color=MUTED, space_after=3)
    doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)

    # ── body ──────────────────────────────────────────────────────────────
    for block in BLOCKS:
        kind, args = block[0], block[1:]
        if kind == "h1":
            doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)
            para(doc, args[0], size=19, bold=True, space_before=6, space_after=8)
        elif kind == "h2":
            para(doc, args[0], size=13.5, bold=True, color=ACCENT,
                 space_before=12, space_after=4)
        elif kind == "h3":
            para(doc, args[0], size=11.5, bold=True, space_before=8, space_after=3)
        elif kind == "lead":
            para(doc, args[0], size=11, color=RGBColor(0x46, 0x53, 0x4F),
                 space_after=8)
        elif kind == "p":
            para(doc, args[0], size=10.5, space_after=6)
        elif kind == "bullets":
            for item in args[0]:
                para(doc, f"• {item}", size=10, space_after=3)
        elif kind == "numbers":
            for n, item in enumerate(args[0], 1):
                para(doc, f"{n}. {item}", size=10, space_after=3)
        elif kind == "table":
            headers, rows = args[0], args[1]
            caption = args[3] if len(args) > 3 else None
            add_table(doc, headers, rows, caption)
        elif kind == "callout":
            add_callout(doc, args[0], args[1], args[2])
        elif kind == "code":
            caption = args[1] if len(args) > 1 else None
            add_code(doc, args[0], caption)
        elif kind == "spacer":
            para(doc, "", size=4, space_after=2)
        elif kind == "pagebreak":
            doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)

    doc.core_properties.title = "خطة الإصدار الثاني — تطبيق المياه Flutter"
    doc.core_properties.subject = "خطة إصدار 2.0.0"
    doc.core_properties.author = "فريق مشروع المياه"
    doc.save(out_path)
    print(f"{out_path.name}: {out_path.stat().st_size:,} bytes")


if __name__ == "__main__":
    out = Path(__file__).resolve().parents[1] / "V2_MOBILE_PLAN_AR.docx"
    build(out)
