"""Redact identifying info from raw screenshots before committing.

Usage:
    cd docs/screenshots
    pip install Pillow pytesseract     # pytesseract is optional but recommended
    # (Install Tesseract binary too — https://github.com/UB-Mannheim/tesseract/wiki on Windows)
    python redact.py

For each *.png in this folder (skip *-redacted.png), produces *-redacted.png:
  - Solid bar over the WordPress admin top bar (site title, username)
  - Solid bar over the sidebar top-left site-logo area
  - Blur over any text matching 'hbs-it-gmbh' (case-insensitive, OCR'd)
  - Blur over any text starting with 'cmcp_' followed by hex (token plaintext)

After verifying the *-redacted.png files look right, rename or git-add them
in place of the originals.
"""

from __future__ import annotations
import os
import re
import sys
from pathlib import Path

try:
    from PIL import Image, ImageDraw, ImageFilter
except ImportError:
    print("ERROR: Pillow required.  pip install Pillow")
    sys.exit(1)

try:
    import pytesseract
    HAS_OCR = True
except ImportError:
    HAS_OCR = False
    print("note: pytesseract not installed — body-text redaction skipped.")
    print("      Install Pillow + pytesseract + the Tesseract binary for full coverage.")

HERE = Path(__file__).parent
ADMIN_BAR_HEIGHT = 32        # WP admin bar runs across the top
SIDEBAR_LOGO_BOX = (0, 32, 130, 92)  # top-left site-logo block inside the dark sidebar
DARK_GRAY = (29, 35, 39)
BLUR_RADIUS = 9
PAD = 4

TOKEN_RE = re.compile(r"^cmcp_[a-f0-9]{8,}$", re.IGNORECASE)
DOMAIN_NEEDLES = ("hbs-it-gmbh",)
USERNAME_NEEDLES = ("it-team-admin",)


def needs_redact(text: str) -> bool:
    t = text.lower().strip()
    if not t:
        return False
    if any(n in t for n in DOMAIN_NEEDLES):
        return True
    if any(n in t for n in USERNAME_NEEDLES):
        return True
    if TOKEN_RE.match(t):
        return True
    return False


def redact_image(src: Path, dst: Path) -> None:
    img = Image.open(src).convert("RGB")
    draw = ImageDraw.Draw(img)
    w, h = img.size

    # Always-mask: WP admin bar (top strip).
    draw.rectangle([0, 0, w, ADMIN_BAR_HEIGHT], fill=DARK_GRAY)

    # Always-mask: top-left site-logo area inside the sidebar.
    draw.rectangle(list(SIDEBAR_LOGO_BOX), fill=DARK_GRAY)

    blurred_count = 0
    if HAS_OCR:
        data = pytesseract.image_to_data(img, output_type=pytesseract.Output.DICT)
        for i, raw in enumerate(data["text"]):
            if not needs_redact(raw):
                continue
            x = int(data["left"][i]) - PAD
            y = int(data["top"][i])  - PAD
            ww = int(data["width"][i]) + PAD * 2
            hh = int(data["height"][i]) + PAD * 2
            x0, y0 = max(0, x), max(0, y)
            x1, y1 = min(w, x + ww), min(h, y + hh)
            if x1 <= x0 or y1 <= y0:
                continue
            region = img.crop((x0, y0, x1, y1))
            region = region.filter(ImageFilter.GaussianBlur(radius=BLUR_RADIUS))
            img.paste(region, (x0, y0))
            blurred_count += 1

    img.save(dst, optimize=True)
    print(f"  {src.name:30s}  -> {dst.name}   (blurred {blurred_count} text regions)")


def main():
    sources = sorted(p for p in HERE.glob("*.png") if not p.stem.endswith("-redacted"))
    if not sources:
        print("No PNG files found in", HERE)
        print("Drop raw screenshots here first (e.g. 01-upload-plugin.png, 06-setup-complete.png).")
        return
    print(f"Processing {len(sources)} screenshot(s)...")
    for src in sources:
        dst = src.with_name(src.stem + "-redacted.png")
        redact_image(src, dst)
    print()
    print("Done.  Review the *-redacted.png files.  If they look good:")
    print("  - delete the originals (which leak the site domain)")
    print("  - rename *-redacted.png to drop the suffix")
    print("  - git add docs/screenshots/*.png")


if __name__ == "__main__":
    main()
