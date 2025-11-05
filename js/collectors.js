import { hashObject } from "./utils";

/* Canvas */
export async function getCanvasHash() {
  const canvas = document.createElement("canvas");
  const ctx = canvas.getContext("2d");
  ctx.textBaseline = "top";
  ctx.font = "16px 'Arial'";
  ctx.fillStyle = "#f60";
  ctx.fillRect(125, 1, 62, 20);
  ctx.fillStyle = "#069";
  ctx.fillText("fingerprint", 2, 15);
  ctx.fillStyle = "rgba(102, 204, 0, 0.7)";
  ctx.fillText("fingerprint", 4, 17);
  const data = canvas.toDataURL();
  return await hashObject(data);
}

/* WebGL */
export function getWebGLInfo() {
  const canvas = document.createElement("canvas");
  const gl =
    canvas.getContext("webgl") || canvas.getContext("experimental-webgl");
  if (!gl) return {};

  const dbg = gl.getExtension("WEBGL_debug_renderer_info");
  return {
    vendor: gl.getParameter(gl.VENDOR),
    renderer: gl.getParameter(gl.RENDERER),
    version: gl.getParameter(gl.VERSION),
    shadingLang: gl.getParameter(gl.SHADING_LANGUAGE_VERSION),
    unmaskedVendor: dbg ? gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL) : null,
    unmaskedRenderer: dbg ? gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) : null,
    extensions: gl.getSupportedExtensions(),
  };
}

/* Audio */
export async function getAudioFingerprint() {
  try {
    const ctx = new OfflineAudioContext(1, 44100, 44100);
    const osc = new OscillatorNode(ctx, {
      type: "triangle",
      frequency: 1000,
    });
    const gain = new GainNode(ctx, { gain: 0.5 });
    osc.connect(gain).connect(ctx.destination);
    osc.start(0);
    osc.stop(0.1);
    const buf = await ctx.startRendering();
    const data = buf.getChannelData(0);
    const samples = [];
    for (let i = 0; i < data.length; i += 100) {
      samples.push(Math.round(data[i] * 1000));
    }
    return await hashObject(samples);
  } catch (e) {
    return "unavailable";
  }
}

/* Fonts / Font metrics */
export function detectFonts() {
  const baseFonts = ["monospace", "sans-serif", "serif"];
  const testFonts = [
    "Arial",
    "Helvetica",
    "Times New Roman",
    "Courier New",
    "Georgia",
    "Trebuchet MS",
    "Verdana",
    "Roboto",
    "Inter",
    "Ubuntu",
    "SF Pro Text",
    "Segoe UI",
    "Calibri",
  ];

  const span = document.createElement("span");
  span.textContent = "mmmmmmmmmmlli";
  span.style.fontSize = "72px";
  span.style.display = "inline-block";
  document.body.appendChild(span);

  const baseDims = {};
  for (const b of baseFonts) {
    span.style.fontFamily = b;
    baseDims[b] = [span.offsetWidth, span.offsetHeight];
  }

  const present = [];
  for (const f of testFonts) {
    for (const b of baseFonts) {
      span.style.fontFamily = `'${f}',${b}`;
      const dims = [span.offsetWidth, span.offsetHeight];
      if (dims[0] !== baseDims[b][0] || dims[1] !== baseDims[b][1]) {
        present.push(f);
        break;
      }
    }
  }
  span.remove();
  return present.sort();
}

export function getFontMetrics() {
  const fonts = detectFonts();
  const metrics = {};
  for (const f of fonts.slice(0, 5)) {
    const span = document.createElement("span");
    span.textContent = "mmmmmmmmmmlli";
    span.style.fontFamily = f;
    span.style.fontSize = "72px";
    span.style.display = "inline-block";
    document.body.appendChild(span);
    metrics[f] = [
      Math.round(span.offsetWidth / 10) * 5,
      Math.round(span.offsetHeight / 10) * 5,
    ];
    span.remove();
  }
  return { fonts, metrics };
}

export async function getBasicInfo() {
  const n = navigator,
    s = screen,
    w = window;

  let ch = {};
  try {
    if (n.userAgentData && n.userAgentData.getHighEntropyValues) {
      ch = await n.userAgentData.getHighEntropyValues([
        "platform",
        "platformVersion",
        "model",
        "uaFullVersion",
        "architecture",
        "bitness",
        "fullVersionList",
        
      ]);
    }
  } catch (e) {
    /* ignore */
  }

  const { quota } = await navigator.storage.estimate();



  return {
    tz: Intl.DateTimeFormat().resolvedOptions().timeZone || null,
    lang: n.language || (Array.isArray(n.languages) ? n.languages[0] : null),
    // languages: n.languages || null,
    vender: n.vendor || null,
    cpuClass: n.cpuClass || null,
    cookieEnabled: n.cookieEnabled || null,

    // Note: plugins and mimeTypes are increasingly restricted in modern browsers
    plugins: Array.from(n.plugins || []).map((p) => ({
      name: p.name,
      description: p.description,
      filename: p.filename,
      version: p.version,
    })),
    mimeTypes: Array.from(n.mimeTypes || []).map((m) => ({
      type: m.type ?? null,
      description: m.description ?? null,
      suffixes: m.suffixes ?? null,
    })),

    // Persistent storage mechanisms
    localStorage: (() => {
      try {
        return !!w.localStorage;
      } catch {
        return null;
      }
    })(),
    sessionStorage: (() => {
      try {
        return !!w.sessionStorage;
      } catch {
        return null;
      }
    })(),
    indexedDb: (() => {
      try {
        return !!w.indexedDB;
      } catch {
        return null;
      }
    })(),

    // Coarse, cross-browser-ish features
    hwc: n.hardwareConcurrency ?? null,
    mem: n.deviceMemory ?? null,
    screen: {
      w: (s && s.width) || null,
      h: (s && s.height) || null,
      dpr: w.devicePixelRatio || 1,
      cd: (s && s.colorDepth) || null,
    },

    // Prefer UA-CH; fall back to UA family
    ch: {
      platform: ch.platform || null,
      platformVersion: ch.platformVersion || null,
      model: ch.model || null,
      uaFullVersion: ch.uaFullVersion || null,
      architecture: ch.architecture || null,
      bitness: ch.bitness || null,
    },
    ua: n.userAgent || null, // last-resort fallback for parsing server-side (browser/OS family)
    version: "fp-1", // bump if you change feature mix so you can keep IDs stable per version
    storageQuota: quota || null,

    // Optional: more volatile features that may help distinguish browsers on same device
    // /*
    touch: "maxTouchPoints" in n ? n.maxTouchPoints : null,
    colorGamut: "colorGamut" in s ? s.colorGamut : null,
    pixelDepth: s && "pixelDepth" in s ? s.pixelDepth : null,
    orientation:
      w.screen.orientation && "type" in w.screen.orientation
        ? w.screen.orientation.type
        : null,
  };
}
