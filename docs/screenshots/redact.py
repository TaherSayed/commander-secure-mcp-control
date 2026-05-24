"""Redact identifying info from captured screenshots.

For each raw *.png in this folder (except *-redacted.png), produces a redacted
PNG that hides:
  - The top notification / debug bar Chrome injects
  - The WordPress admin top bar (site title, username)
  - The entire left admin sidebar (other plugin names, branding)
  - Any visible text matching the site domain, the admin username, or a
    cmcp_ token plaintext (via OCR, blurred)

Run:
    cd docs/screenshots
    python redact.py                # processes everything in place
    python redact.py 07-dashboard   # processes one
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
    if os.name == "nt":
        for candidate in (
            os.environ.get("TESSERACT_EXE", ""),
            os.path.expandvars(r"%LOCALAPPDATA%\Programs\Tesseract-OCR\tesseract.exe"),
            r"C:\Program Files\Tesseract-OCR\tesseract.exe",
            r"C:\Program Files (x86)\Tesseract-OCR\tesseract.exe",
        ):
            if candidate and os.path.isfile(candidate):
                pytesseract.pytesseract.tesseract_cmd = candidate
                break
except ImportError:
    HAS_OCR = False
    print("note: pytesseract not installed — body-text redaction skipped.")

HERE = Path(__file__).parent

# Layout constants tuned for 1936×856 captures (Chrome window cropped of its
# own UI). Adjust if your captures are a different size.
NOTIF_BAR_HEIGHT = 26          # Chrome's "MCP debug" notification yellow strip
WP_ADMIN_BAR_HEIGHT = 60       # WordPress admin top bar (incl. icons row)
SIDEBAR_WIDTH = 170            # Left admin sidebar (other plugins, branding)
DARK_BG = (29, 35, 39)         # WP admin dark background colour
ACCENT = (10, 61, 98)          # Commander brand colour
BLUR_RADIUS = 9
PAD = 4

TOKEN_RE = re.compile(r"cmcp_[a-f0-9]{4,}", re.IGNORECASE)
DOMAIN_NEEDLES = ("hbs-it-gmbh",)
USERNAME_NEEDLES = ("it-team-admin",)

# Per-file extra hard-mask rectangles. The token reveal box on the
# "Setup complete" screen is on a dark background that OCR can't read, so
# we mask it geometrically.
PER_FILE_MASKS = {
    "06-setup-complete.png": [
        # token reveal box — dark band with green plaintext
        (200, 430, 1252, 495),
    ],
}


URL_NEEDLES = ("https://", "http://", "://", "hbs", "gmbh", "wp-json", ".well-known")


def needs_redact(text: str) -> bool:
    t = text.lower().strip()
    if not t:
        return False
    if any(n in t for n in DOMAIN_NEEDLES):
        return True
    if any(n in t for n in USERNAME_NEEDLES):
        return True
    if TOKEN_RE.search(t):
        return True
    # Any URL-ish fragment — OCR splits monospace URLs into many pieces.
    if any(n in t for n in URL_NEEDLES):
        return True
    return False


def redact_image(src: Path, dst: Path) -> None:
    img = Image.open(src).convert("RGB")
    w, h = img.size
    draw = ImageDraw.Draw(img)

    # Always-mask: top notification + WP admin bar
    top_band = NOTIF_BAR_HEIGHT + WP_ADMIN_BAR_HEIGHT
    draw.rectangle([0, 0, w, top_band], fill=DARK_BG)

    # Always-mask: entire left admin sidebar
    draw.rectangle([0, top_band, SIDEBAR_WIDTH, h], fill=DARK_BG)

    # Add a small "MCP" marker so the sidebar isn't pure black — visual
    # affordance that something is hidden by design.
    try:
        draw.rectangle(
            [SIDEBAR_WIDTH - 28, top_band + 6, SIDEBAR_WIDTH - 6, top_band + 28],
            fill=ACCENT,
        )
        draw.text((SIDEBAR_WIDTH - 24, top_band + 9), "C", fill=(255, 255, 255))
    except Exception:
        pass  # font load failures are fine

    blurred = 0
    if HAS_OCR:
        # OCR only the right-hand region (content area) to keep it fast and
        # avoid OCR'ing the now-blacked-out sidebar/topbar.
        content_box = (SIDEBAR_WIDTH, top_band, w, h)
        sub = img.crop(content_box)
        # Tesseract sometimes splits URLs across tokens; --psm 11 (sparse text)
        # and -c preserve_interword_spaces=1 help keep them whole.
        ocr_config = "--psm 11 -c preserve_interword_spaces=1"
        data = pytesseract.image_to_data(
            sub, config=ocr_config, output_type=pytesseract.Output.DICT
        )
        # Group adjacent tokens on the same line so a URL split across multiple
        # OCR words still gets blurred as one region.
        lines = {}
        for i, raw in enumerate(data["text"]):
            if not raw.strip():
                continue
            key = (data["block_num"][i], data["par_num"][i],
                   data["line_num"][i])
            lines.setdefault(key, []).append(i)
        for ixs in lines.values():
            joined = " ".join(data["text"][i] for i in ixs)
            if not needs_redact(joined) and not any(needs_redact(data["text"][i]) for i in ixs):
                continue
            x0 = min(int(data["left"][i])  for i in ixs) + content_box[0] - PAD
            y0 = min(int(data["top"][i])   for i in ixs) + content_box[1] - PAD
            x1 = max(int(data["left"][i]) + int(data["width"][i])  for i in ixs) + content_box[0] + PAD
            y1 = max(int(data["top"][i])  + int(data["height"][i]) for i in ixs) + content_box[1] + PAD
            x0, y0 = max(0, x0), max(0, y0)
            x1, y1 = min(w, x1), min(h, y1)
            if x1 <= x0 or y1 <= y0:
                continue
            region = img.crop((x0, y0, x1, y1)).filter(
                ImageFilter.GaussianBlur(radius=BLUR_RADIUS)
            )
            img.paste(region, (x0, y0))
            blurred += 1

    # Apply per-file geometric masks (e.g. token reveal box on file 06).
    extra = PER_FILE_MASKS.get(src.name, [])
    for x0, y0, x1, y1 in extra:
        draw.rectangle([x0, y0, x1, y1], fill=DARK_BG)

    img.save(dst, optimize=True)
    print(f"  {src.name:32s} -> {dst.name}  ({w}x{h}, {blurred} text regions blurred"
          f"{', ' + str(len(extra)) + ' hard-masked rect(s)' if extra else ''})")


def main():
    args = sys.argv[1:]
    if args:
        sources = []
        for a in args:
            p = HERE / (a if a.endswith(".png") else a + ".png")
            if p.exists():
                sources.append(p)
            else:
                print(f"skip (not found): {p.name}")
    else:
        sources = sorted(
            p for p in HERE.glob("*.png")
            if not p.stem.endswith("-redacted")
        )
    if not sources:
        print("No PNG files to process.")
        return
    print(f"Processing {len(sources)} file(s)...")
    for src in sources:
        dst = src.with_name(src.stem + "-redacted.png")
        redact_image(src, dst)
    print()
    print("Done. Review the *-redacted.png files. If they look good:")
    print("  rm *.png-not-redacted")
    print("  rename *-redacted.png to drop the suffix")
    print("  git add -A && git commit -m 'docs: walkthrough screenshots'")


if __name__ == "__main__":
    main()
