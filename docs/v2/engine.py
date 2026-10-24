"""Small RTL/Arabic PDF engine used to render the v2 mobile plan.

Built on fpdf2 with HarfBuzz text shaping (``set_text_shaping(True)``) so Arabic
letters join correctly. Content is described as a list of block tuples; see
``build.py``.
"""

from __future__ import annotations

import re

from fpdf import FPDF
from fpdf.enums import XPos, YPos

# ── Design tokens (identical to the app's DESIGN_SYSTEM.md v2 palette) ───────
ACCENT = (12, 107, 96)
ACCENT_STRONG = (9, 81, 73)
ACCENT_SOFT = (228, 239, 236)
INK = (15, 26, 24)
INK_SOFT = (70, 83, 79)
MUTED = (120, 133, 127)
BORDER = (228, 233, 231)
BORDER_STRONG = (211, 219, 216)
PAPER = (244, 246, 245)
SURFACE_ALT = (237, 241, 239)
PANEL = (15, 26, 24)
SUCCESS = (21, 115, 71)
WARNING = (154, 91, 0)
DANGER = (176, 42, 55)
INFO = (47, 93, 124)
WHITE = (255, 255, 255)

FONT_DIR = "/home/user/water/mobile app/assets/fonts/"
MONO = "/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf"
MONO_BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf"

PAGE_W = 210.0
PAGE_H = 297.0
MARGIN = 18.0
CONTENT_W = PAGE_W - 2 * MARGIN  # 174 mm


class PlanPDF(FPDF):
    """A4 document with an Arabic running header and a numbered footer."""

    def __init__(self, short_title: str):
        super().__init__(orientation="P", unit="mm", format="A4")
        self.short_title = short_title
        self.set_margins(MARGIN, 22, MARGIN)
        self.set_auto_page_break(True, margin=20)
        self.set_text_shaping(True)
        self.add_font("plex", "", FONT_DIR + "IBMPlexSansArabic-Regular.ttf")
        self.add_font("plex", "B", FONT_DIR + "IBMPlexSansArabic-SemiBold.ttf")
        self.add_font("plexb", "", FONT_DIR + "IBMPlexSansArabic-Bold.ttf")
        self.add_font("mono", "", MONO)
        self.add_font("mono", "B", MONO_BOLD)
        self.total_pages = 0
        self.section_label = ""

    # fpdf2 calls these on every page
    def header(self):
        if self.page_no() == 1:
            return
        self.set_font("plex", "", 8)
        self.set_text_color(*MUTED)
        self.set_y(9)
        self.set_x(MARGIN)
        self.set_font("plex", "", 7.6)
        self.cell(40, 5, "Water 2.0", align="L")
        if self.section_label:
            self.set_font("plex", "", 8)
            label = re.sub(r"^\d+\s*·\s*", "", self.section_label)
            self.cell(CONTENT_W - 40, 5, label, align="R")
        self.set_draw_color(*BORDER)
        self.set_line_width(0.25)
        self.line(MARGIN, 16.5, PAGE_W - MARGIN, 16.5)
        self.set_y(22)

    def footer(self):
        if self.page_no() == 1:
            return
        self.set_y(-15)
        self.set_draw_color(*BORDER)
        self.set_line_width(0.25)
        self.line(MARGIN, PAGE_H - 18, PAGE_W - MARGIN, PAGE_H - 18)
        self.set_font("plex", "", 8)
        self.set_text_color(*MUTED)
        self.cell(60, 6, "مشروع المياه · تطبيق الموبايل", align="L")
        self.set_font("plex", "", 8)
        label = f"{self.page_no()} / {self.total_pages}" if self.total_pages else str(self.page_no())
        self.cell(0, 6, label, align="C")
        self.cell(60, 6, "خطة الإصدار 2.0 · أكتوبر 2026", align="R")


LRI, PDI = "\u2066", "\u2069"
_DIGITS = re.compile(r"(?<![\w\u2066])(\d[\d.,:/\-]*\d|\d)(?![\w\u2069])")


def sanitize(text: str) -> str:
    """Strip markdown backticks and isolate latin numbers inside RTL text."""
    text = text.replace("`", "")
    return _DIGITS.sub(lambda m: f"{LRI}{m.group(1)}{PDI}", text)


class Writer:
    """Block-level renderer on top of :class:`PlanPDF`."""

    def __init__(self, pdf: PlanPDF, toc_pages: dict[str, int] | None = None,
                 record: dict[str, int] | None = None):
        self.pdf = pdf
        self.toc_pages = toc_pages or {}
        self.record = record if record is not None else {}
        self.toc: list[tuple[int, str]] = []

    # ── primitives ─────────────────────────────────────────────────────────
    def _space(self, h: float) -> None:
        if self.pdf.get_y() + h > PAGE_H - 22:
            self.pdf.add_page()
        else:
            self.pdf.ln(h)

    def _need(self, mm: float) -> None:
        if self.pdf.get_y() + mm > PAGE_H - 22:
            self.pdf.add_page()

    def _right_cell(self, text: str, h: float, size: float, color, family="plex",
                    style="", width: float | None = None, fill: bool = False,
                    bg=None) -> None:
        pdf = self.pdf
        pdf.set_font(family, style, size)
        pdf.set_text_color(*color)
        if bg is not None:
            pdf.set_fill_color(*bg)
        pdf.multi_cell(width or CONTENT_W, h, sanitize(text), align="R", fill=fill,
                       new_x=XPos.RIGHT, new_y=YPos.NEXT)

    # ── headings ───────────────────────────────────────────────────────────
    def h1(self, title: str, anchor: str | None = None) -> None:
        m = re.match(r"^(\d+)\.\s*(.+)$", title)
        if m:
            title = f"{m.group(1)} · {m.group(2)}"
        key = anchor or title
        self.record[key] = self.pdf.page_no()
        if self.pdf.get_y() > 24:
            self.pdf.add_page()
        self.pdf.section_label = title
        self.pdf.set_y(26)
        self.pdf.set_fill_color(*ACCENT)
        self.pdf.rect(PAGE_W - MARGIN - 1.4, self.pdf.get_y() + 1, 1.4, 9, "F")
        self.pdf.set_x(MARGIN)
        self.pdf.set_font("plexb", "", 18)
        self.pdf.set_text_color(*INK)
        self.pdf.multi_cell(CONTENT_W - 7, 9.6, sanitize(title), align="R",
                            new_x=XPos.RIGHT, new_y=YPos.NEXT)
        self.pdf.set_draw_color(*BORDER_STRONG)
        self.pdf.set_line_width(0.4)
        self.pdf.line(MARGIN, self.pdf.get_y() + 1.5, PAGE_W - MARGIN, self.pdf.get_y() + 1.5)
        self.pdf.ln(6)
        self.toc.append((1, key))

    def h2(self, title: str, anchor: str | None = None) -> None:
        key = anchor or title
        self.record[key] = self.pdf.page_no()
        self._need(24)
        self.pdf.ln(2)
        self.pdf.set_font("plexb", "", 13)
        self.pdf.set_text_color(*ACCENT_STRONG)
        self.pdf.multi_cell(CONTENT_W, 7.4, sanitize(title), align="R",
                            new_x=XPos.RIGHT, new_y=YPos.NEXT)
        self.pdf.ln(1.6)
        self.toc.append((2, key))

    def h3(self, title: str) -> None:
        self._need(18)
        self.pdf.ln(1.2)
        self.pdf.set_font("plex", "B", 10.8)
        self.pdf.set_text_color(*INK)
        self.pdf.multi_cell(CONTENT_W, 6.4, title, align="R",
                            new_x=XPos.RIGHT, new_y=YPos.NEXT)
        self.pdf.ln(0.8)

    # ── body ───────────────────────────────────────────────────────────────
    def p(self, text: str, size: float = 10.2, color=INK, lead: float = 6.0,
          before: float = 0.0) -> None:
        if before:
            self.pdf.ln(before)
        self.pdf.set_font("plex", "", size)
        self.pdf.set_text_color(*color)
        self.pdf.multi_cell(CONTENT_W, lead, sanitize(text), align="R",
                            new_x=XPos.RIGHT, new_y=YPos.NEXT)
        self.pdf.ln(1.6)

    def lead(self, text: str) -> None:
        """Standfirst paragraph under a chapter title."""
        self.pdf.set_font("plex", "", 10.8)
        self.pdf.set_text_color(*INK_SOFT)
        self.pdf.multi_cell(CONTENT_W, 6.4, sanitize(text), align="R",
                            new_x=XPos.RIGHT, new_y=YPos.NEXT)
        self.pdf.ln(4)

    def bullets(self, items: list[str], numbered: bool = False, size: float = 10.0) -> None:
        pdf = self.pdf
        indent = 6.2
        for i, item in enumerate(items, start=1):
            marker = f"{i}." if numbered else "•"
            pdf.set_font("plex", "", size)
            item = sanitize(item)
            lines = pdf.multi_cell(CONTENT_W - indent, 5.9, item, align="R",
                                   dry_run=True, output="LINES")
            height = 5.9 * max(1, len(lines))
            self._need(height + 1.2)
            y = pdf.get_y()
            pdf.set_text_color(*ACCENT)
            pdf.set_font("plex", "B" if numbered else "", size if numbered else size + 1)
            pdf.set_xy(PAGE_W - MARGIN - indent, y)
            pdf.cell(indent, 5.9, marker, align="R")
            pdf.set_xy(MARGIN, y)
            pdf.set_font("plex", "", size)
            pdf.set_text_color(*INK)
            pdf.multi_cell(CONTENT_W - indent, 5.9, item, align="R",
                           new_x=XPos.RIGHT, new_y=YPos.NEXT)
            pdf.ln(0.8)
        pdf.ln(1.6)

    def table(self, headers: list[str], rows: list[list[str]],
              widths: list[float] | None = None, caption: str | None = None,
              size: float = 8.4) -> None:
        """Hand-laid RTL table: first logical column renders rightmost."""
        pdf = self.pdf
        n = len(headers)
        widths = widths or [CONTENT_W / n] * n
        scale = CONTENT_W / sum(widths)
        widths = [w * scale for w in widths]
        line_h = size * 0.62 + 1.0
        pad_x, pad_y = 2.0, 1.9
        if caption:
            self._need(12)
            pdf.set_font("plex", "B", 8.2)
            pdf.set_text_color(*MUTED)
            pdf.cell(0, 4.4, sanitize(caption), align="R",
                     new_x=XPos.RIGHT, new_y=YPos.NEXT)
            pdf.ln(1.0)

        def cell_align(text: str) -> str:
            return "R"

        def measure(text: str, w: float, bold: bool = False) -> int:
            pdf.set_font("plex", "B" if bold else "", size)
            lines = pdf.multi_cell(max(w - 2 * pad_x, 6), line_h, sanitize(text),
                                   align="R", dry_run=True, output="LINES")
            return max(1, len(lines))

        if rows:
            first_h = (max(measure(c, w) for c, w in zip(rows[0], widths))
                       * line_h + 2 * pad_y)
            self._need(first_h + line_h + 2 * pad_y + 2)
        def draw_row(cells: list[str], header: bool, zebra: bool) -> None:
            heights = [measure(c, w, header) for c, w in zip(cells, widths)]
            height = max(heights) * line_h + 2 * pad_y
            if pdf.get_y() + height > PAGE_H - 22:
                pdf.add_page()
                if not header:
                    draw_row(headers, True, False)
            y = pdf.get_y()
            if header:
                pdf.set_fill_color(*ACCENT)
                pdf.rect(MARGIN, y, CONTENT_W, height, "F")
            elif zebra:
                pdf.set_fill_color(*SURFACE_ALT)
                pdf.rect(MARGIN, y, CONTENT_W, height, "F")
            x = PAGE_W - MARGIN
            for text, w in zip(cells, widths):
                x -= w
                pdf.set_xy(x + pad_x, y + pad_y)
                pdf.set_font("plex", "B" if header else "", size)
                pdf.set_text_color(*(WHITE if header else INK))
                pdf.multi_cell(w - 2 * pad_x, line_h, sanitize(text),
                               align=cell_align(text), new_x=XPos.RIGHT,
                               new_y=YPos.NEXT)
            pdf.set_draw_color(*BORDER)
            pdf.set_line_width(0.2)
            pdf.line(MARGIN, y + height, PAGE_W - MARGIN, y + height)
            pdf.set_y(y + height)

        draw_row(list(headers), True, False)
        for i, row in enumerate(rows):
            draw_row([str(c) for c in row], False, i % 2 == 1)
        pdf.set_y(pdf.get_y() + 3.2)

    def callout(self, title: str, body: str, kind: str = "info") -> None:
        pdf = self.pdf
        tones = {
            "info": (INFO, (238, 244, 248)),
            "warning": (WARNING, (250, 243, 232)),
            "danger": (DANGER, (251, 238, 239)),
            "success": (SUCCESS, (234, 245, 239)),
            "accent": (ACCENT, ACCENT_SOFT),
        }
        bar, bg = tones.get(kind, tones["info"])
        pad = 4.0
        title = sanitize(title)
        body = sanitize(body)
        pdf.set_font("plex", "B", 9.8)
        title_lines = pdf.multi_cell(CONTENT_W - 2 * pad - 3, 5.2, title,
                                     dry_run=True, output="LINES")
        pdf.set_font("plex", "", 9.4)
        body_lines = pdf.multi_cell(CONTENT_W - 2 * pad - 3, 5.3, body,
                                    dry_run=True, output="LINES")
        height = 5.2 * len(title_lines) + 5.3 * len(body_lines) + 2 * pad + 2.4
        self._need(height + 2)
        y = pdf.get_y()
        pdf.set_fill_color(*bg)
        pdf.rect(MARGIN, y, CONTENT_W, height, "F")
        pdf.set_fill_color(*bar)
        pdf.rect(PAGE_W - MARGIN - 1.8, y, 1.8, height, "F")
        pdf.set_xy(MARGIN + pad, y + pad)
        pdf.set_font("plex", "B", 9.8)
        pdf.set_text_color(*bar)
        pdf.multi_cell(CONTENT_W - 2 * pad - 3, 5.2, title, align="R",
                       new_x=XPos.RIGHT, new_y=YPos.NEXT)
        pdf.set_x(MARGIN + pad)
        pdf.set_font("plex", "", 9.4)
        pdf.set_text_color(*INK_SOFT)
        pdf.multi_cell(CONTENT_W - 2 * pad - 3, 5.3, body, align="R",
                       new_x=XPos.RIGHT, new_y=YPos.NEXT)
        pdf.set_y(y + height + 3.4)

    def code(self, text: str, caption: str | None = None) -> None:
        pdf = self.pdf
        pdf.set_font("mono", "", 7.4)
        lines = text.strip("\n").split("\n")
        wrapped: list[str] = []
        for line in lines:
            if not line:
                wrapped.append("")
                continue
            while len(line) > 96:
                wrapped.append(line[:96])
                line = "    " + line[96:]
            wrapped.append(line)
        line_h = 3.7
        height = line_h * len(wrapped) + 5
        self._need(min(height, PAGE_H - 60))
        if caption:
            pdf.set_font("plex", "B", 8.4)
            pdf.set_text_color(*MUTED)
            pdf.cell(0, 4.4, caption, align="R", new_x=XPos.RIGHT, new_y=YPos.NEXT)
        start = pdf.get_y()
        pdf.set_fill_color(246, 248, 247)
        pdf.rect(MARGIN, start, CONTENT_W, height, "F")
        pdf.set_fill_color(*BORDER_STRONG)
        pdf.rect(PAGE_W - MARGIN - 1.2, start, 1.2, height, "F")
        pdf.set_xy(MARGIN + 3, start + 2.4)
        pdf.set_font("mono", "", 7.4)
        pdf.set_text_color(*INK_SOFT)
        for line in wrapped:
            pdf.cell(CONTENT_W - 6, line_h, line, align="L",
                     new_x=XPos.RIGHT, new_y=YPos.NEXT)
            pdf.set_x(MARGIN + 3)
        pdf.set_y(start + height + 3)

    def spacer(self, h: float = 4.0) -> None:
        self.pdf.ln(h)

    def page_break(self) -> None:
        self.pdf.add_page()


def _head_style(size: float):
    from fpdf.fonts import FontFace
    return FontFace(family="plex", emphasis="BOLD", size_pt=size,
                    color=WHITE, fill_color=ACCENT)
