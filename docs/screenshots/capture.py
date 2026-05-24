"""Capture WP-admin pages from the Chrome MCP-driven tab via PIL.ImageGrab.

For each page in PAGES:
  1. Find the foreground Google Chrome window (Chrome MCP navigates it).
  2. Grab its bounding box, crop off Chrome's chrome bars at the top.
  3. Save to <name>.png in this folder.

Run only AFTER the corresponding browser_batch navigate has settled.

This script doesn't drive Chrome itself — the calling Claude session does that
via Chrome MCP and runs this script between navigations.
"""
from __future__ import annotations
import os
import sys
import time
from pathlib import Path
from PIL import ImageGrab
import pygetwindow as gw

HERE = Path(__file__).parent

# Crop off Chrome's tab bar + URL bar + bookmarks bar from the top of the
# captured Chrome window. Tune if your Chrome shows more / fewer top bars.
# 32 (Windows title bar) + 41 (tabs) + 39 (URL bar) + 32 (bookmarks bar) ≈ 144 px.
CHROME_CHROME_TOP = 144

# Also trim the Windows taskbar at the very bottom of the screen if it
# happens to be visible inside the captured window (rare but possible).
WINDOWS_TASKBAR_BOTTOM = 48


def grab_active_wp_window(out_path: Path) -> None:
    """Grab the Google Chrome window currently showing a WordPress admin page."""
    candidates = [
        w for w in gw.getAllWindows()
        if w.title and "Chrome" in w.title and "WordPress" in w.title
    ]
    if not candidates:
        raise RuntimeError(
            "No Google Chrome window with a WordPress page title found. "
            "Drive Chrome MCP to navigate first."
        )
    w = candidates[0]
    try:
        w.activate()
    except Exception:
        pass
    time.sleep(0.4)
    bbox = (w.left, w.top, w.left + w.width, w.top + w.height)
    img = ImageGrab.grab(bbox=bbox, all_screens=True)

    # Crop Chrome's chrome (top) + Windows taskbar (bottom, if visible).
    iw, ih = img.size
    top = min(CHROME_CHROME_TOP, ih - 200)
    bottom = max(top + 100, ih - WINDOWS_TASKBAR_BOTTOM)
    img = img.crop((0, top, iw, bottom))

    img.save(out_path, optimize=True)
    print(f"  captured  {out_path.name:32s}  {img.size}")


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("usage: python capture.py <basename-without-ext>")
        sys.exit(1)
    name = sys.argv[1]
    if not name.endswith(".png"):
        name += ".png"
    grab_active_wp_window(HERE / name)
