# Screenshots

This folder holds the install / setup walkthrough screenshots referenced from `docs/SETUP.md`.

## Filenames the SETUP.md expects

| Name | What it shows |
|---|---|
| `01-upload-plugin.png` | Plugins → Add New → Upload Plugin screen |
| `02-install-success.png` | "Plugin successfully installed" + Activate button |
| `03-activation-banner.png` | Plugins page, green "activated" notice + blue setup banner |
| `04-wizard-top.png` | Wizard, steps 1 & 2 visible |
| `05-wizard-bottom.png` | Wizard, step 3 + Finish + "What Commander does" |
| `06-setup-complete.png` | "Setup complete" + token + Connect cards |
| `07-dashboard.png` | Commander dashboard |
| `08-tokens-page.png` | Tokens page (with bot user banner) |
| `09-settings.png` | Settings page |
| `10-oauth-clients.png` | OAuth Clients page |
| `11-audit-log.png` | Audit Log page |

## Workflow

1. Drop raw PNGs in this folder using the names above.
2. Install Pillow (and optionally Tesseract OCR for body-text redaction):

   ```bash
   pip install Pillow pytesseract
   ```

   On Windows install the Tesseract binary from
   <https://github.com/UB-Mannheim/tesseract/wiki>.

3. Run the redactor:

   ```bash
   python redact.py
   ```

   It emits `*-redacted.png` next to each source.

4. Review. If acceptable, drop the unredacted originals, rename
   `*-redacted.png` → `*.png`, commit.

## What the redactor masks

- WordPress **admin top bar** (always — site title + username are exposed there)
- Sidebar **top-left site logo** (always)
- Any **text matching `hbs-it-gmbh`** (OCR'd, blurred)
- Any **text matching `it-team-admin`** (OCR'd, blurred)
- Any **text matching `^cmcp_[a-f0-9]{8,}$`** — covers the leaked token plaintext on screenshot 06
