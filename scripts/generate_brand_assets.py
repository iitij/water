#!/usr/bin/env python3
"""Generate platform launcher icons and splash art from one master PNG.

Source of truth: ``mobile app/assets/branding/app_icon_full.png`` (1024x1024,
full-bleed gradient with the water-drop mark). Run from the repository root:

    python3 scripts/generate_brand_assets.py

Writes Android mipmaps (legacy + adaptive foreground/background round icons),
the Android splash drawables/colors and the Windows ``app_icon.ico``.
"""

from __future__ import annotations

import struct
from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
MOBILE = ROOT / "mobile app"
MASTER = MOBILE / "assets" / "branding" / "app_icon_full.png"

ANDROID_RES = MOBILE / "android" / "app" / "src" / "main" / "res"
WINDOWS_RES = MOBILE / "windows" / "runner" / "resources"

# Legacy launcher icon sizes per density bucket.
LEGACY_SIZES = {
    "mipmap-mdpi": 48,
    "mipmap-hdpi": 72,
    "mipmap-xhdpi": 96,
    "mipmap-xxhdpi": 144,
    "mipmap-xxxhdpi": 192,
}

# Adaptive icons are drawn on a 108dp canvas; only the inner 72dp is guaranteed
# visible, so the artwork is scaled into that safe zone.
ADAPTIVE_SIZES = {
    "mipmap-mdpi": 108,
    "mipmap-hdpi": 162,
    "mipmap-xhdpi": 216,
    "mipmap-xxhdpi": 324,
    "mipmap-xxxhdpi": 432,
}
SAFE_ZONE = 0.62  # fraction of the canvas occupied by the mark

LAUNCH_LIGHT = (0xF2, 0xF7, 0xFA)  # AppColors light background
LAUNCH_DARK = (0x05, 0x13, 0x1B)  # AppColors dark background

ICO_SIZES = [16, 24, 32, 48, 64, 128, 256]


def build_mark(master: Image.Image) -> Image.Image:
    """Round the master art into a squircle mark with a transparent margin."""
    size = master.width
    inset = int(size * 0.055)
    art = master.convert("RGB").resize((size - 2 * inset, size - 2 * inset), Image.LANCZOS)
    canvas = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    mask = Image.new("L", art.size, 0)
    ImageDraw.Draw(mask).rounded_rectangle(
        (0, 0, art.width - 1, art.height - 1),
        radius=int(art.width * 0.235),
        fill=255,
    )
    canvas.paste(art, (inset, inset), mask)
    return canvas


def write_legacy(master: Image.Image) -> None:
    art = master.convert("RGB")
    for bucket, px in LEGACY_SIZES.items():
        folder = ANDROID_RES / bucket
        folder.mkdir(parents=True, exist_ok=True)
        art.resize((px, px), Image.LANCZOS).save(folder / "ic_launcher.png", optimize=True)
        # Round icons: circular mask over the same art.
        rounded = Image.new("RGBA", (px, px), (0, 0, 0, 0))
        mask = Image.new("L", (px * 4, px * 4), 0)
        ImageDraw.Draw(mask).ellipse((0, 0, px * 4 - 1, px * 4 - 1), fill=255)
        rounded.paste(art.resize((px, px), Image.LANCZOS), (0, 0), mask.resize((px, px), Image.LANCZOS))
        rounded.save(folder / "ic_launcher_round.png", optimize=True)


def write_adaptive(master: Image.Image) -> None:
    background = master.convert("RGB")
    foreground_source = build_mark(master)
    for bucket, px in ADAPTIVE_SIZES.items():
        folder = ANDROID_RES / bucket
        folder.mkdir(parents=True, exist_ok=True)
        background.resize((px, px), Image.LANCZOS).save(
            folder / "ic_launcher_background.png", optimize=True
        )
        inner = max(1, int(px * SAFE_ZONE))
        mark = foreground_source.resize((inner, inner), Image.LANCZOS)
        layer = Image.new("RGBA", (px, px), (0, 0, 0, 0))
        offset = (px - inner) // 2
        layer.paste(mark, (offset, offset), mark)
        layer.save(folder / "ic_launcher_foreground.png", optimize=True)

    anydpi = ANDROID_RES / "mipmap-anydpi-v26"
    anydpi.mkdir(parents=True, exist_ok=True)
    for name in ("ic_launcher.xml", "ic_launcher_round.xml"):
        (anydpi / name).write_text(
            '<?xml version="1.0" encoding="utf-8"?>\n'
            '<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">\n'
            '    <background android:drawable="@mipmap/ic_launcher_background" />\n'
            '    <foreground android:drawable="@mipmap/ic_launcher_foreground" />\n'
            "</adaptive-icon>\n",
            encoding="utf-8",
        )


def write_splash() -> None:
    for folder in ("values", "values-night"):
        target = ANDROID_RES / folder
        target.mkdir(parents=True, exist_ok=True)
    (ANDROID_RES / "values" / "colors.xml").write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n'
        '    <color name="launch_background_color">#F2F7FA</color>\n'
        "</resources>\n",
        encoding="utf-8",
    )
    (ANDROID_RES / "values-night" / "colors.xml").write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n'
        '    <color name="launch_background_color">#05131B</color>\n'
        "</resources>\n",
        encoding="utf-8",
    )
    splash = (
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<layer-list xmlns:android="http://schemas.android.com/apk/res/android">\n'
        '    <item android:drawable="@color/launch_background_color" />\n'
        "    <item>\n"
        '        <bitmap\n'
        '            android:gravity="center"\n'
        '            android:src="@mipmap/ic_launcher_foreground" />\n'
        "    </item>\n"
        "</layer-list>\n"
    )
    for folder in ("drawable", "drawable-v21"):
        (ANDROID_RES / folder).mkdir(parents=True, exist_ok=True)
        (ANDROID_RES / folder / "launch_background.xml").write_text(splash, encoding="utf-8")


def write_windows_ico(master: Image.Image) -> None:
    """Write a multi-resolution ICO by hand (BMP entries, bottom-up rows)."""
    WINDOWS_RES.mkdir(parents=True, exist_ok=True)
    frames = [master.convert("RGBA").resize((px, px), Image.LANCZOS) for px in ICO_SIZES]

    header = struct.pack("<HHH", 0, 1, len(frames))
    entries, blobs = b"", b""
    offset = 6 + 16 * len(frames)
    for frame in frames:
        px = frame.width
        mask_row = ((px + 31) // 32) * 4
        mask = b"\x00" * (mask_row * px)
        bg = frame.tobytes("raw", "BGRA")
        rows = b"".join(bg[y * px * 4 : (y + 1) * px * 4] for y in range(px - 1, -1, -1))
        header_bytes = struct.pack(
            "<IiiHHIIiiII", 40, px, px * 2, 1, 32, 0, len(rows) + len(mask), 0, 0, 0, 0
        )
        blob = header_bytes + rows + mask
        entries += struct.pack("<BBBBHHII", px % 256, px % 256, 0, 0, 1, 32, len(blob), offset)
        blobs += blob
        offset += len(blob)
    (WINDOWS_RES / "app_icon.ico").write_bytes(header + entries + blobs)


def main() -> None:
    if not MASTER.exists():
        raise SystemExit(f"master artwork missing: {MASTER}")
    master = Image.open(MASTER)
    write_legacy(master)
    write_adaptive(master)
    write_splash()
    write_windows_ico(master)
    print("brand assets generated from", MASTER)


if __name__ == "__main__":
    main()
