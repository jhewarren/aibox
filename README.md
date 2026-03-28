# aibox

A simple tool to facilitate the implementation of a standalone AI solution on a local machine.

## Web Wizard (Levels 00 & 0)

The `web/aibox/` directory contains a **browser-instantiated, non-invasive setup wizard**
for Windows PCs. It requires no plugins, no database, and no server-side logic — drop it
into any web directory and it works.

**Live (maintainer's deployment):** [https://nt3l.com/aibox](https://nt3l.com/aibox) — update this URL for your own deployment
**Source:** [web/aibox/](web/aibox/)

### What the wizard does

| Stage | Description |
|-------|-------------|
| **Level 00 — Baseline** | Displays the ideal target configuration (CPU, RAM, VRAM, disk, drivers, Ollama, OpenWebUI) and explains why each component matters. |
| **Level 00 — Variance** | Lets you manually enter or confirm your actual hardware values. |
| **Level 00 — Impact** | Explains how your configuration compares to the baseline and what performance to expect. |
| **Level 0 — Detection** | Uses browser APIs (`hardwareConcurrency`, `deviceMemory`, WebGL) to pre-fill the form with best-effort detected values. All detection is client-side only. |

### What the wizard does NOT do

- Does not install software or make system changes
- Does not collect, store, or transmit any user data
- Does not require login or registration
- Does not use WordPress plugins or APIs

### Data collection transparency

All processing happens in the browser (JavaScript). The following browser APIs are
read client-side and are **never sent to any server**:

- `navigator.hardwareConcurrency` — logical CPU thread count
- `navigator.deviceMemory` — approximate RAM (Chrome/Edge only)
- `navigator.userAgent` / `navigator.platform` — OS detection hint
- WebGL RENDERER string — GPU hint (labelled unreliable)

No cookies are set. No analytics are loaded.

### Deployment

1. Copy the contents of `web/aibox/` to your host's `/aibox/` directory:

   ```sh
   # Example: SFTP to public_html/aibox/
   rsync -av web/aibox/ user@your-host:public_html/aibox/
   ```

2. Visit `https://your-host/aibox/` — no further setup needed.

See [`web/aibox/README.md`](web/aibox/README.md) for full deployment notes.

---

## Planned stages (not yet implemented)

- **Level 1** — Read-only PowerShell audit script (`aibox-audit.ps1`) to collect exact hardware info
- **Level 2** — Local config registration (paste `aibox-profile.json` for customised scripts)
- **Level 3** — Prerequisite PowerShell scripts (Docker Desktop, WSL2, drivers)
- **Level 4** — Ollama (Windows-native) + OpenWebUI Docker install scripts
