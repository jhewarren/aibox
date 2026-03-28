# aibox web wizard — deployment notes

This directory (`web/aibox/`) contains the **aibox Local LLM Setup Wizard** — a
non-invasive, browser-only assessment tool for Windows PCs running (or planning to
run) [Ollama](https://ollama.com) + [OpenWebUI](https://github.com/open-webui/open-webui).

## Deploying to a WordPress host (e.g. nt3l.com/aibox)

No WordPress plugin is required. The wizard is plain HTML + CSS + JavaScript and
works in any subdirectory of a web server.

### Steps

1. **Copy this directory** to your web host's public root:

   ```sh
   # Example: upload via SFTP / cPanel File Manager to
   #   public_html/aibox/
   #
   # Resulting URL: https://nt3l.com/aibox/
   ```

   The directory structure that must be on the server:

   ```
   public_html/aibox/
   ├── index.html
   └── assets/
       ├── aibox.css
       └── aibox.js
   ```

2. **Navigate to** `https://nt3l.com/aibox/` in a browser — the wizard loads immediately.

3. **No build step**, no `composer install`, no database, no WordPress APIs.

### Keeping it up to date

Because the wizard source lives in the public
[jhewarren/aibox](https://github.com/jhewarren/aibox) GitHub repository, you can
update it by pulling the latest `web/aibox/` directory contents and copying them to
the host:

```sh
# local update workflow (example)
git pull origin main
rsync -av web/aibox/ user@your-host:public_html/aibox/
```

### Optional: version string

`index.html` displays the version string from `<span id="aibox-version">unknown</span>`.
If you want to show a commit SHA, inject it at deploy time:

```sh
# inject commit SHA at deploy time (Linux)
SHA=$(git rev-parse --short HEAD)
sed -i "s/>unknown</>$SHA</g" web/aibox/index.html

# macOS requires an empty-string backup argument:
# sed -i '' "s/>unknown</>$SHA</g" web/aibox/index.html
```

This is entirely optional; the wizard works fine without it.

---

## What browser data is collected

| Data | Collected? | Notes |
|------|-----------|-------|
| `navigator.hardwareConcurrency` | Client-side only | Logical CPU thread count; never sent anywhere |
| `navigator.deviceMemory` | Client-side only | Approximate RAM (Chrome/Edge only); never sent anywhere |
| `navigator.userAgent` / `navigator.platform` | Client-side only | OS hint; never sent anywhere |
| WebGL RENDERER string | Client-side only | GPU hint; labelled unreliable; never sent anywhere |

## What is NOT collected

- No server-side logging of any user data
- No cookies set
- No registration or account required
- No analytics or tracking scripts
- No form values are transmitted; all processing happens in JavaScript in your browser

---

## Browser support

| Browser | Level 0 detection | Wizard UX |
|---------|------------------|-----------|
| Chrome / Edge (latest) | Full (`deviceMemory` + WebGL) | ✅ |
| Firefox (latest) | Partial (no `deviceMemory`) | ✅ |
| Safari (latest) | Partial (no `deviceMemory`) | ✅ |

---

## Directory structure

```
web/aibox/
├── index.html          # Single-page wizard (4 steps)
├── README.md           # This file
└── assets/
    ├── aibox.css       # Wizard stylesheet (scoped to #aibox-wizard)
    └── aibox.js        # Level 0 detection + variance impact engine
```

---

## Licence

MIT — see repository root for full licence text.
