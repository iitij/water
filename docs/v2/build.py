"""Builds ``docs/V2_MOBILE_PLAN_AR.pdf`` from the Arabic content modules."""

from __future__ import annotations

import sys
from pathlib import Path

from fpdf.enums import XPos, YPos

sys.path.insert(0, str(Path(__file__).parent))

from engine import sanitize  # noqa: E402
from engine import (  # noqa: E402
    ACCENT, ACCENT_SOFT, ACCENT_STRONG, BORDER, BORDER_STRONG, CONTENT_W, DANGER,
    INK, INK_SOFT, MARGIN, MUTED, PAGE_H, PAGE_W, PANEL, PAPER, PlanPDF,
    SURFACE_ALT, WHITE, Writer,
)
import content_a  # noqa: E402
import content_b  # noqa: E402
import content_c  # noqa: E402
import content_d  # noqa: E402

TITLE = "خطة الإصدار الثاني"
SUBTITLE = "تطبيق الهاتف Flutter لمنظومة إدارة مشاريع المياه"
SHORT = "Water · خطة الإصدار 2.0"
VERSION = "2.0"
DATE = "24 أكتوبر 2026"


def cover(pdf: PlanPDF) -> None:
    pdf.add_page()
    # Ink panel
    pdf.set_fill_color(*PANEL)
    pdf.rect(0, 0, PAGE_W, 150, "F")
    pdf.set_fill_color(*ACCENT)
    pdf.rect(0, 150, PAGE_W, 3.2, "F")

    # Eyebrow
    pdf.set_xy(PAGE_W - MARGIN - 120, 26)
    pdf.set_font("plex", "B", 9.5)
    pdf.set_text_color(*ACCENT_SOFT)
    pdf.cell(120, 6, "وثيقة تخطيط إنتاجي · نسخة للاعتماد", align="R")

    # Title
    pdf.set_xy(MARGIN, 44)
    pdf.set_font("plexb", "", 34)
    pdf.set_text_color(*WHITE)
    pdf.multi_cell(CONTENT_W, 15, TITLE, align="R",
                   new_x=XPos.RIGHT, new_y=YPos.NEXT)
    pdf.set_x(MARGIN)
    pdf.ln(2)
    pdf.set_font("plex", "", 15)
    pdf.set_text_color(*ACCENT_SOFT)
    pdf.multi_cell(CONTENT_W, 8.4, SUBTITLE, align="R",
                   new_x=XPos.RIGHT, new_y=YPos.NEXT)
    pdf.set_x(MARGIN)
    pdf.ln(4)
    pdf.set_draw_color(*ACCENT)
    pdf.set_line_width(0.6)
    pdf.line(PAGE_W - MARGIN - 46, pdf.get_y() + 3, PAGE_W - MARGIN, pdf.get_y() + 3)

    pdf.set_x(MARGIN)
    pdf.ln(10)
    pdf.set_font("plex", "", 11.5)
    pdf.set_text_color(*WHITE)
    pdf.multi_cell(CONTENT_W, 7, "من تطبيق تجريبي (1.0) إلى منتج إنتاجي موثوق: "
                                 "الأمان، الاعتمادية، الميدان، والقياس.", align="R")

    # Meta sheet
    pdf.set_y(172)
    rows = [
        ("الإصدار المستهدف", "2.0.0 — إصدار إنتاجي أول (Android + Windows، وقرار iOS)"),
        ("الأساس الحالي", "1.0.0+1 · الفرع arena/7f48d908-water"),
        ("تاريخ الوثيقة", DATE),
        ("حالة الوثيقة", "مسودة معتمدة للتنفيذ — تحتاج توقيع مالك المنتج"),
        ("الجمهور", "مالك المنتج، قائد الهندسة، مطورو Flutter، مطور الـ Backend، ضمان الجودة"),
        ("مدة الخطة", "12 أسبوعاً · 6 دورات تطوير (Sprint) كل دورة أسبوعان"),
    ]
    for label, value in rows:
        y = pdf.get_y()
        pdf.set_xy(MARGIN, y)
        pdf.set_text_color(*INK)
        pdf.set_font("plex", "", 9.6)
        pdf.multi_cell(CONTENT_W - 50, 5.4, sanitize(value), align="L",
                       new_x=XPos.RIGHT, new_y=YPos.NEXT)
        end_y = pdf.get_y()
        pdf.set_xy(PAGE_W - MARGIN - 47, y)
        pdf.set_text_color(*MUTED)
        pdf.set_font("plex", "B", 8.8)
        pdf.cell(47, 5.4, label, align="R")
        pdf.set_y(end_y)
        pdf.set_draw_color(*BORDER)
        pdf.set_line_width(0.2)
        pdf.line(MARGIN, pdf.get_y() + 1, PAGE_W - MARGIN, pdf.get_y() + 1)
        pdf.ln(2.6)

    pdf.set_y(PAGE_H - 34)
    pdf.set_fill_color(*PAPER)
    pdf.rect(MARGIN, pdf.get_y(), CONTENT_W, 18, "F")
    pdf.set_xy(MARGIN + 5, pdf.get_y() + 3.4)
    pdf.set_font("plex", "", 8.6)
    pdf.set_text_color(*INK_SOFT)
    pdf.multi_cell(CONTENT_W - 10, 5, sanitize("هذه الخطة مبنية على فحص الكود الفعلي في المستودع "
                                      "بتاريخ 24 أكتوبر 2026: ثلاثمئة واثنا عشر ملف Dart بنحو سبعة "
                                      "وخمسين ألف سطر، ومئة وتسعة وخمسون اختبار وحدة، ومئة وواحد "
                                      "وعشرون مسار API. وكل رقم تقديري موسوم كتقدير."), align="R")


def document_map(pdf: PlanPDF, w: Writer) -> None:
    pdf.add_page()
    pdf.set_y(26)
    pdf.set_font("plexb", "", 17)
    pdf.set_text_color(*INK)
    pdf.cell(0, 9, "كيف تُقرأ هذه الوثيقة", align="R", new_x=XPos.RIGHT, new_y=YPos.NEXT)
    pdf.ln(4)
    w.p("الوثيقة مقسّمة إلى أربعة أبواب وتسلسلها مقصود: الباب الأول يُثبت من أين نبدأ "
        "بالأرقام لا بالانطباعات، والباب الثاني يحدّد ما سيلمسه المستخدم فعلاً، "
        "والباب الثالث يحوّل ذلك إلى قرارات هندسية قابلة للتنفيذ، والباب الرابع "
        "يحوّلها إلى جدول زمني وميزانية ومخاطر وبنود قبول يمكن محاسبتنا عليها.")
    w.table(
        ["الباب", "المحتوى", "القارئ المستهدف", "الفصول"],
        [
            ["الأول", "الحالة الراهنة، الفجوات، أهداف 2.0 ونطاقها", "مالك المنتج · القيادة", "1 – 3"],
            ["الثاني", "تجربة المستخدم والوحدات الوظيفية", "المنتج · التصميم · الميدان", "4 – 6"],
            ["الثالث", "المعمارية، البيانات، الأمن، الخادم، الجودة، الإصدار", "الهندسة", "7 – 12"],
            ["الرابع", "الدورات، الفريق، الميزانية، المخاطر، القبول", "الإدارة · الجميع", "13 – 20"],
            ["الملاحق", "عقود API، مخطط البيانات، القوالب، المسرد", "المطورون", "أ – و"],
        ],
        widths=[16, 62, 58, 24],
        caption="خريطة الوثيقة",
    )
    w.h3("اصطلاحات القراءة")
    w.bullets([
        "«الحالي» تعني ما هو موجود فعلاً في المستودع اليوم وتم التحقق منه بقراءة الملفات، لا ما تصفه الخطة الرئيسية القديمة.",
        "«المستهدف» تعني ما يجب أن يكون موجوداً عند إغلاق الإصدار 2.0.0.",
        "الوسم **[تقدير]** يعني رقماً تقديرياً يعتمد على افتراضات مذكورة في الفصل 17، ويجب إعادة معايرته بعد الدورة الأولى.",
        "الوسم **[قرار]** يعني بنداً يحتاج توقيعك قبل بدء التنفيذ، وهو مجموع في الفصل 20.",
        "أي إشارة إلى ملف مسارها من جذر المستودع إلا إذا ذُكر خلاف ذلك؛ `mobile app/` هو مجلد Flutter.",
    ])

    w.callout(
        "حدود هذه الوثيقة",
        "الخطة لا تدّعي أن التطبيق جاهز للإنتاج اليوم، ولا أن وظائفه مطابقة للوحة الويب بنسبة 100%. "
        "التطابق الحالي مدقّق على مستوى الكود المصدري فقط؛ التشغيل الفعلي على جهاز مقابل خادم Laravel "
        "هو بند إجباري في الدورة الأولى من هذه الخطة.",
        "warning",
    )


def toc(pdf: PlanPDF, entries: list[tuple[int, str]], pages: dict[str, int], w: Writer) -> None:
    pdf.add_page()
    pdf.set_y(26)
    pdf.set_font("plexb", "", 17)
    pdf.set_text_color(*INK)
    pdf.cell(0, 9, "فهرس المحتويات", align="R", new_x=XPos.RIGHT, new_y=YPos.NEXT)
    pdf.ln(5)
    for level, key in entries:
        page = pages.get(key, 0)
        height = 6.0 if level == 1 else 5.0
        if pdf.get_y() > PAGE_H - 26:
            pdf.add_page()
            pdf.set_y(24)
        y = pdf.get_y()
        if level == 1:
            pdf.ln(1.8)
            y = pdf.get_y()
            pdf.set_font("plex", "B", 10.4)
            pdf.set_text_color(*INK)
        else:
            pdf.set_font("plex", "", 9.3)
            pdf.set_text_color(*INK_SOFT)
        pdf.set_xy(MARGIN, y)
        pdf.cell(12, height, str(page) if page else "—", align="L")
        pdf.set_xy(MARGIN + 13, y)
        pdf.cell(CONTENT_W - 13, height, key, align="R")
        if level == 1:
            pdf.set_draw_color(*BORDER)
            pdf.set_line_width(0.2)
            pdf.line(MARGIN, pdf.get_y() + height, PAGE_W - MARGIN, pdf.get_y() + height)
        pdf.set_y(y + height + (0.6 if level == 2 else 0.4))


def build(out_path: str) -> None:
    blocks = (content_a.BLOCKS + content_b.BLOCKS + content_c.BLOCKS
              + content_d.BLOCKS)
    pages: dict[str, int] = {}
    toc_entries: list[tuple[int, str]] = []
    for attempt in range(4):
        pdf = PlanPDF(SHORT)
        pdf.set_title("خطة الإصدار الثاني — تطبيق المياه Flutter")
        pdf.set_author("فريق مشروع المياه")
        pdf.set_subject("خطة إصدار 2.0.0 لتطبيق Flutter")
        pdf.set_keywords("Flutter, Laravel, خطة, إصدار, مياه, أندرويد")
        w = Writer(pdf, toc_pages=pages)
        cover(pdf)
        document_map(pdf, w)
        # placeholder TOC: same amount of lines as the final one
        entries_preview = collect_toc(blocks)
        toc(pdf, entries_preview, pages, w)
        w.toc = []
        render(pdf, w, blocks)
        toc_entries = w.toc
        recorded = dict(w.record)
        if recorded == pages:
            pages = recorded
            break
        pages = recorded

    # Final pass with the settled page numbers.
    pdf = PlanPDF(SHORT)
    w = Writer(pdf, toc_pages=pages)
    cover(pdf)
    document_map(pdf, w)
    toc(pdf, toc_entries, pages, w)
    render(pdf, w, blocks)
    pdf.output(out_path)
    print(f"pages={pdf.page_no()}  bytes={Path(out_path).stat().st_size}")
    for level, key in toc_entries:
        if level == 1:
            print(f"  H1 {pages.get(key, '?'):>3}  {key}")


def collect_toc(blocks) -> list[tuple[int, str]]:
    entries = []
    for block in blocks:
        kind = block[0]
        if kind == "h1":
            entries.append((1, block[1]))
        elif kind == "h2":
            entries.append((2, block[1]))
    return entries


def render(pdf: PlanPDF, w: Writer, blocks) -> None:
    for block in blocks:
        kind = block[0]
        args = block[1:]
        if kind == "h1":
            w.h1(*args)
        elif kind == "h2":
            w.h2(*args)
        elif kind == "h3":
            w.h3(*args)
        elif kind == "p":
            w.p(*args)
        elif kind == "lead":
            w.lead(*args)
        elif kind == "bullets":
            w.bullets(*args)
        elif kind == "numbers":
            w.bullets(*args, numbered=True)
        elif kind == "table":
            w.table(*args)
        elif kind == "callout":
            w.callout(*args)
        elif kind == "code":
            w.code(*args)
        elif kind == "spacer":
            w.spacer(*args)
        elif kind == "pagebreak":
            w.page_break()
        else:  # pragma: no cover
            raise ValueError(f"unknown block {kind}")


if __name__ == "__main__":
    out = sys.argv[1] if len(sys.argv) > 1 else "docs/V2_MOBILE_PLAN_AR.pdf"
    build(out)
