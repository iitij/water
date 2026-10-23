#!/usr/bin/env python3
"""Derive the in-app brand mark and the Android launch screen from the icon kit.

The launcher icons, adaptive layers, themed monochrome layer and Windows/Linux
icons are generated from the SVG sources in ``mobile app/assets/icons`` by
``mobile app/tool/generate_icons.mjs`` — that kit stays the single source of
truth. This script only covers the two pieces the icon kit does not produce:

* ``mobile app/assets/branding/app_mark.png`` — the squircle mark drawn by
  ``AppBrandMark`` on the splash, login and navigation rail.
* The Android launch-screen background colours (light ``#F2F7FA`` / dark
  ``#05131B``), so the system splash matches the Flutter splash instead of
  flashing white.

Run from the repository root:

    python3 scripts/generate_brand_assets.py
"""

from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
MOBILE = ROOT / "mobile app"

# Icon kit master: already the compact (mark-first) variant, which stays legible
# at the 44-96 px sizes the in-app mark is drawn at.
MASTER = MOBILE / "assets" / "icons" / "app_icon_compact_1024.png"
MARK = MOBILE / "assets" / "branding" / "app_mark.png"

ANDROID_RES = MOBILE / "android" / "app" / "src" / "main" / "res"
LAUNCH_LIGHT = "#F2F7FA"  # AppColors light background
LAUNCH_DARK = "#05131B"  # AppColors dark background

MARK_SIZE = 512
MARK_CORNER_RATIO = 0.235  # squircle radius, matches AppBrandMark(size * .26)


def write_mark(master: Image.Image) -> None:
    """Round the master art into a squircle with a transparent margin."""
    art = master.convert("RGB").resize((MARK_SIZE, MARK_SIZE), Image.LANCZOS)
    mask = Image.new("L", (MARK_SIZE * 4, MARK_SIZE * 4), 0)
    ImageDraw.Draw(mask).rounded_rectangle(
        (0, 0, MARK_SIZE * 4 - 1, MARK_SIZE * 4 - 1),
        radius=int(MARK_SIZE * 4 * MARK_CORNER_RATIO),
        fill=255,
    )
    rounded = Image.new("RGBA", (MARK_SIZE, MARK_SIZE), (0, 0, 0, 0))
    rounded.paste(art, (0, 0), mask.resize((MARK_SIZE, MARK_SIZE), Image.LANCZOS))
    MARK.parent.mkdir(parents=True, exist_ok=True)
    rounded.save(MARK, optimize=True)


def write_splash() -> None:
    """Brand the Android window background behind the Flutter splash."""
    for folder, color in (("values", LAUNCH_LIGHT), ("values-night", LAUNCH_DARK)):
        target = ANDROID_RES / folder
        target.mkdir(parents=True, exist_ok=True)
        (target / "colors.xml").write_text(
            '<?xml version="1.0" encoding="utf-8"?>\n'
            "<resources>\n"
            f'    <color name="launch_background_color">{color}</color>\n'
            "</resources>\n",
            encoding="utf-8",
        )

    splash = (
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<layer-list xmlns:android="http://schemas.android.com/apk/res/android">\n'
        '    <item android:drawable="@color/launch_background_color" />\n'
        "    <item>\n"
        "        <bitmap\n"
        '            android:gravity="center"\n'
        '            android:src="@mipmap/ic_launcher_foreground" />\n'
        "    </item>\n"
        "</layer-list>\n"
    )
    for folder in ("drawable", "drawable-v21"):
        (ANDROID_RES / folder).mkdir(parents=True, exist_ok=True)
        (ANDROID_RES / folder / "launch_background.xml").write_text(splash, encoding="utf-8")


def main() -> None:
    if not MASTER.exists():
        raise SystemExit(f"icon kit master missing: {MASTER}")
    write_mark(Image.open(MASTER))
    write_splash()
    print(f"brand mark -> {MARK.relative_to(ROOT)}")
    print(f"launch screen -> {ANDROID_RES.relative_to(ROOT)}/values{{,-night}}/colors.xml")


if __name__ == "__main__":
    main()
