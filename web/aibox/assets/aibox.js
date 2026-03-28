/**
 * aibox.js — Level 0 browser detection + wizard form handling
 *
 * What this script collects (client-side only):
 *   - navigator.hardwareConcurrency (logical CPU thread count)
 *   - navigator.deviceMemory (approximate RAM, Chrome/Edge only)
 *   - navigator.userAgent / navigator.platform (OS hint)
 *   - WebGL RENDERER string (GPU hint — unreliable, clearly labelled)
 *
 * Nothing is sent to any server. All data stays in the browser.
 */

(function () {
  "use strict";

  /* ── Version string ────────────────────────────────────────── */
  // Can be overridden at deploy time by injecting a value into the span:
  //   sed -i "s/>unknown</>$(git rev-parse --short HEAD)</g" index.html
  var AIBOX_VERSION = "v0.1.0";

  /* ── Baseline (Level 00.2) ──────────────────────────────────── */
  const BASELINE = {
    cpu:    "Intel Core Ultra 7",
    ram:    32,    // GB
    gpu:    "NVIDIA GeForce RTX 5060 Ti × 2",
    vram:   16,    // GB per card
    disk:   100,   // GB free
    driver: true,
    docker: true,
    ollama: "Windows-native",
  };

  /* ── Thresholds for variance impact (Level 00.4) ───────────── */
  const THRESHOLDS = {
    ram:  { error: 8,  warn: 16, ok: 32 },
    vram: { error: 4,  warn: 8,  ok: 16 },
    disk: { error: 20, warn: 50, ok: 100 },
  };

  /* ── Step management ────────────────────────────────────────── */
  const TOTAL_STEPS = 4;
  let currentStep = 1;
  let detectedValues = {};
  let userValues = {};

  function showStep(n) {
    for (let i = 1; i <= TOTAL_STEPS; i++) {
      const el = document.getElementById("step-" + i);
      if (el) el.classList.toggle("hidden", i !== n);
    }
    updateProgress(n);
    currentStep = n;
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function updateProgress(active) {
    for (let i = 1; i <= TOTAL_STEPS; i++) {
      const dot = document.getElementById("dot-" + i);
      if (!dot) continue;
      dot.classList.remove("active", "done");
      if (i === active) dot.classList.add("active");
      else if (i < active) dot.classList.add("done");
    }
  }

  /* ── Level 0: Browser detection ────────────────────────────── */
  function detectBrowser() {
    const d = {};

    // CPU threads
    d.cpuThreads = navigator.hardwareConcurrency || null;

    // Approximate RAM (deviceMemory is in GiB, Chrome/Edge only)
    d.deviceMemoryGB = navigator.deviceMemory || null;

    // OS hint
    const ua = navigator.userAgent || "";
    const platform = navigator.platform || "";
    if (/Windows/i.test(ua) || /Win/i.test(platform)) {
      d.os = "Windows";
    } else if (/Mac/i.test(ua) || /Mac/i.test(platform)) {
      d.os = "macOS";
    } else if (/Linux/i.test(ua)) {
      d.os = "Linux";
    } else {
      d.os = null;
    }
    d.userAgent = ua;

    // WebGL GPU hint — clearly labelled as unreliable
    d.gpuHint = null;
    d.gpuHintWarning = true;
    try {
      const canvas = document.createElement("canvas");
      const gl =
        canvas.getContext("webgl") ||
        canvas.getContext("experimental-webgl");
      if (gl) {
        const ext = gl.getExtension("WEBGL_debug_renderer_info");
        if (ext) {
          d.gpuHint = gl.getParameter(ext.UNMASKED_RENDERER_WEBGL) || null;
        }
      }
    } catch (_) {
      // silently ignore — WebGL may be blocked
    }

    return d;
  }

  function renderDetected(d) {
    const set = (id, val, fallback) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val !== null && val !== undefined ? val : fallback;
    };

    set("det-os",          d.os,           "Unknown");
    set("det-threads",     d.cpuThreads !== null ? d.cpuThreads + " logical cores" : null, "Unknown");
    set("det-ram",         d.deviceMemoryGB !== null ? "≈ " + d.deviceMemoryGB + " GB" : null, "Unknown (browser limit)");
    set("det-gpu",         d.gpuHint, "Unknown");
    set("det-ua-short",    d.userAgent ? d.userAgent.substring(0, 80) + (d.userAgent.length > 80 ? "…" : "") : "Unknown");

    // Pre-fill form (Level 0: detected values seed editable fields)
    if (d.deviceMemoryGB) {
      const ramField = document.getElementById("f-ram");
      if (ramField && !ramField.value) ramField.value = d.deviceMemoryGB;
    }
  }

  /**
   * Build the variance impact item list.
   *
   * IMPORTANT: `body` strings must not contain raw HTML — they are passed through
   * escapeHtml() before insertion into the DOM. Keep them as plain text only.
   *
   * @param {Object} vals - User-confirmed hardware values
   * @returns {{ items: Array, score: number }}
   */
  function computeImpact(vals) {
    const items = [];
    let score = 0; // 0=unknown, accumulate positives

    // RAM
    const ram = parseFloat(vals.ram);
    if (!isNaN(ram)) {
      if (ram < THRESHOLDS.ram.error) {
        items.push({ level: "error", icon: "✗", title: "RAM critically low (" + ram + " GB)", body: "Local LLMs require at least 8 GB RAM. CPU-only inference of even small models will likely fail or be unusably slow." });
      } else if (ram < THRESHOLDS.ram.warn) {
        items.push({ level: "warn", icon: "⚠", title: "RAM below recommendation (" + ram + " GB / recommended 16+ GB)", body: "8–15 GB may run a 7B-parameter model in CPU-only mode, but expect 2–5 tokens/sec or worse. Larger models will not fit." });
        score += 1;
      } else if (ram < THRESHOLDS.ram.ok) {
        items.push({ level: "ok", icon: "✓", title: "RAM adequate (" + ram + " GB)", body: "16–31 GB supports 7B–13B models in CPU mode and GPU-accelerated inference of 7B–14B models." });
        score += 2;
      } else {
        items.push({ level: "ok", icon: "✓", title: "RAM excellent (" + ram + " GB — matches baseline)", body: "32+ GB comfortably runs 14B–32B quantized models and multi-model scenarios." });
        score += 3;
      }
    } else {
      items.push({ level: "info", icon: "ℹ", title: "RAM not specified", body: "Enter your RAM to see performance estimates." });
    }

    // VRAM
    const vram = parseFloat(vals.vram);
    const gpu = (vals.gpu || "").trim();
    const gpuLower = gpu.toLowerCase();
    const hasNvidia = /nvidia|rtx|gtx|geforce/i.test(gpuLower);
    const hasAmd   = /amd|radeon|rx\s*\d/i.test(gpuLower);
    const hasCpu   = !gpu || gpuLower === "cpu only" || gpuLower === "integrated";

    if (hasCpu || gpuLower === "none") {
      items.push({ level: "warn", icon: "⚠", title: "No dedicated GPU / CPU-only mode", body: "Inference will use CPU only. Expect very slow throughput (1–5 tokens/sec for 7B models). GPU acceleration is strongly recommended for a usable experience." });
    } else if (hasAmd) {
      items.push({ level: "info", icon: "ℹ", title: "AMD GPU detected (" + (gpu || "unspecified") + ")", body: "Ollama supports AMD ROCm on Linux. On Windows, ROCm support is limited; performance and compatibility may vary. NVIDIA is better supported in Ollama for Windows." });
      score += 1;
    } else if (hasNvidia || (!isNaN(vram) && vram > 0)) {
      if (!isNaN(vram)) {
        if (vram < THRESHOLDS.vram.error) {
          items.push({ level: "error", icon: "✗", title: "VRAM very limited (" + vram + " GB)", body: "Less than 4 GB VRAM will not GPU-accelerate most modern LLMs. CPU fallback will be used." });
        } else if (vram < THRESHOLDS.vram.warn) {
          items.push({ level: "warn", icon: "⚠", title: "VRAM below recommendation (" + vram + " GB / recommended 8+ GB)", body: "4–7 GB VRAM can run small quantized models (e.g. Q4 of 7B). Model layers may offload to RAM, slowing inference." });
          score += 1;
        } else if (vram < THRESHOLDS.vram.ok) {
          items.push({ level: "ok", icon: "✓", title: "VRAM good (" + vram + " GB)", body: "8–15 GB VRAM handles 7B–13B models fully on-GPU. Strong GPU performance expected." });
          score += 2;
        } else {
          items.push({ level: "ok", icon: "✓", title: "VRAM excellent (" + vram + " GB — matches baseline)", body: "16+ GB VRAM per card enables 30B+ quantized models and fast multi-layer inference." });
          score += 3;
        }
      }
    } else if (gpu) {
      items.push({ level: "info", icon: "ℹ", title: "GPU entered but type unclear (" + gpu + ")", body: "Fill in VRAM to see impact. If this is an NVIDIA card, make sure drivers are installed for best performance." });
    }

    // Disk
    const disk = parseFloat(vals.disk);
    if (!isNaN(disk)) {
      if (disk < THRESHOLDS.disk.error) {
        items.push({ level: "error", icon: "✗", title: "Disk space critically low (" + disk + " GB free)", body: "Less than 20 GB free will not support downloading model files (7B Q4 ≈ 4–5 GB) plus Docker images (OpenWebUI ≈ 2–3 GB). Free up space first." });
      } else if (disk < THRESHOLDS.disk.warn) {
        items.push({ level: "warn", icon: "⚠", title: "Disk space limited (" + disk + " GB free / recommended 50+ GB)", body: "20–49 GB may fit 1–2 small models and OpenWebUI but leaves little room for additional models or model updates." });
        score += 1;
      } else if (disk < THRESHOLDS.disk.ok) {
        items.push({ level: "ok", icon: "✓", title: "Disk space adequate (" + disk + " GB free)", body: "50–99 GB supports several model variants and Docker images comfortably." });
        score += 2;
      } else {
        items.push({ level: "ok", icon: "✓", title: "Disk space excellent (" + disk + " GB free — matches baseline)", body: "100+ GB free gives room for large models (13B–32B) and multiple model versions." });
        score += 3;
      }
    } else {
      items.push({ level: "info", icon: "ℹ", title: "Disk not specified", body: "Enter free disk space to see storage impact." });
    }

    // NVIDIA driver
    if (vals.driver === "yes") {
      items.push({ level: "ok", icon: "✓", title: "NVIDIA drivers installed", body: "Ollama and CUDA-accelerated inference should work out of the box." });
      score += 1;
    } else if (vals.driver === "no") {
      if (hasNvidia) {
        items.push({ level: "error", icon: "✗", title: "NVIDIA drivers not installed", body: "GPU acceleration is unavailable without NVIDIA drivers. Download from https://www.nvidia.com/drivers and install before running Ollama." });
      } else {
        items.push({ level: "info", icon: "ℹ", title: "NVIDIA drivers not installed", body: "If you have an NVIDIA GPU, install drivers. Otherwise this may not apply." });
      }
    } else if (vals.driver === "unknown") {
      if (hasNvidia) {
        items.push({ level: "warn", icon: "⚠", title: "NVIDIA driver status unknown", body: "Open Device Manager (Win + X → Device Manager) and check Display Adapters to confirm driver status." });
      }
    }

    // Docker
    if (vals.docker === "yes") {
      items.push({ level: "ok", icon: "✓", title: "Docker Desktop installed", body: "OpenWebUI can be launched as a Docker container pointing to your Windows-native Ollama." });
      score += 1;
    } else if (vals.docker === "no") {
      items.push({ level: "warn", icon: "⚠", title: "Docker Desktop not installed", body: "OpenWebUI requires Docker Desktop. Download from https://www.docker.com/products/docker-desktop. Ollama itself runs natively on Windows without Docker." });
    } else {
      items.push({ level: "info", icon: "ℹ", title: "Docker Desktop status unknown", body: "Check whether Docker Desktop is installed if you plan to run OpenWebUI." });
    }

    return { items, score };
  }

  function tierFromScore(score, hasErrors) {
    if (hasErrors) return { cls: "tier-limited", icon: "🚫", name: "Not Ready", desc: "Critical issues must be resolved before running a local LLM." };
    if (score >= 9) return { cls: "tier-great",   icon: "🚀", name: "Excellent — matches or exceeds baseline", desc: "Your setup is well-suited for running large local models with fast GPU inference." };
    if (score >= 6) return { cls: "tier-good",    icon: "✅", name: "Good — capable configuration", desc: "Most 7B–14B models will run well. Some larger models may be slow." };
    if (score >= 3) return { cls: "tier-ok",      icon: "⚠️",  name: "Marginal — limited performance expected", desc: "Small models (7B Q4) should run but will be slow. Upgrade RAM or add a GPU for a better experience." };
    return                  { cls: "tier-limited", icon: "⚠️",  name: "Limited — significant constraints", desc: "Running a local LLM will be challenging. See details below." };
  }

  function modelRec(score, vram, hasNvidia) {
    const v = parseFloat(vram);
    if (hasNvidia && !isNaN(v)) {
      if (v >= 16) return ["llama3.1:8b (fast)", "llama3.1:14b", "mistral:7b", "phi3:14b", "codellama:13b"];
      if (v >= 8)  return ["llama3.1:8b", "mistral:7b", "phi3:mini"];
      if (v >= 4)  return ["phi3:mini (Q4)", "tinyllama (Q4)"];
    }
    if (score >= 4) return ["llama3.1:8b (CPU, slow)", "phi3:mini (CPU)"];
    return ["phi3:mini (CPU, very slow)", "tinyllama (CPU)"];
  }

  function renderImpact(vals) {
    const { items, score } = computeImpact(vals);
    const hasErrors = items.some((i) => i.level === "error");
    const gpuLower = (vals.gpu || "").toLowerCase();
    const hasNvidia = /nvidia|rtx|gtx|geforce/i.test(gpuLower);
    const tier = tierFromScore(score, hasErrors);
    const recs = modelRec(score, vals.vram, hasNvidia);

    // Tier banner
    const tierEl = document.getElementById("impact-tier");
    if (tierEl) {
      tierEl.className = "impact-tier " + tier.cls;
      tierEl.innerHTML = `<span class="tier-icon">${tier.icon}</span>
        <span class="tier-text">
          <span class="tier-name">${tier.name}</span>
          <span class="tier-desc">${tier.desc}</span>
        </span>`;
    }

    // Item list
    const listEl = document.getElementById("impact-items");
    if (listEl) {
      listEl.innerHTML = items
        .map(
          (item) =>
            `<div class="impact-item ${item.level}">
              <span class="ii-icon">${item.icon}</span>
              <span class="ii-text"><strong>${escapeHtml(item.title)}</strong>${escapeHtml(item.body)}</span>
            </div>`
        )
        .join("");
    }

    // Model recommendations
    const modelEl = document.getElementById("model-rec");
    if (modelEl) {
      modelEl.innerHTML = `<strong>Suggested Ollama models for your configuration:</strong>
        <ul>${recs.map((m) => "<li>" + escapeHtml(m) + "</li>").join("")}</ul>`;
    }
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }

  /* ── Collect form values ────────────────────────────────────── */
  function collectFormValues() {
    const g = (id) => {
      const el = document.getElementById(id);
      return el ? el.value : "";
    };
    return {
      ram:    g("f-ram"),
      gpu:    g("f-gpu"),
      vram:   g("f-vram"),
      disk:   g("f-disk"),
      driver: g("f-driver"),
      docker: g("f-docker"),
    };
  }

  /* ── Wire up buttons ────────────────────────────────────────── */
  function wireButtons() {
    const on = (id, fn) => {
      const el = document.getElementById(id);
      if (el) el.addEventListener("click", fn);
    };

    on("btn-start",       () => showStep(2));
    on("btn-det-back",    () => showStep(1));
    on("btn-det-next",    () => showStep(3));
    on("btn-form-back",   () => showStep(2));
    on("btn-form-next", () => {
      userValues = collectFormValues();
      renderImpact(userValues);
      showStep(4);
    });
    on("btn-impact-back", () => showStep(3));
    on("btn-restart",     () => {
      userValues = {};
      const fields = ["f-ram","f-gpu","f-vram","f-disk"];
      fields.forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.value = "";
      });
      ["f-driver","f-docker"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.value = "unknown";
      });
      // Re-seed from detected
      if (detectedValues.deviceMemoryGB) {
        const r = document.getElementById("f-ram");
        if (r) r.value = detectedValues.deviceMemoryGB;
      }
      showStep(1);
    });
  }

  /* ── Init ───────────────────────────────────────────────────── */
  function init() {
    // Populate version string
    var verEl = document.getElementById("aibox-version");
    if (verEl && verEl.textContent === "unknown") {
      verEl.textContent = AIBOX_VERSION;
    }
    detectedValues = detectBrowser();
    renderDetected(detectedValues);
    wireButtons();
    showStep(1);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
