import { roundTo, simpleHash, removeNulls } from "./utils.js";

// normalize.js
export function normalizeFingerprint(data = {}) {
  const {
    tz,
    lang,
    languages,
    hwc,
    mem,
    vendor,
    colorGamut,
    pixelDepth,
    screen,
    webgl,
    fonts,
    plugins,
    canvas,
    audio,
  } = data;

  // 🧩 Normalize screen info (bucket sizes to avoid false splits)
  const normalizedScreen = screen
    ? {
        w: Math.round(screen.w / 100) * 100, // bucket to 100px
        h: Math.round(screen.h / 100) * 100,
        dpr: roundTo(screen.dpr ?? 1, 1),
        cd: screen.cd ? Math.min(30, screen.cd) : 24, // 24 vs 30 bucket
      }
    : null;

  // 🧩 Normalize color gamut / pixel depth
  const normalizedColorDepth = pixelDepth > 28 ? 30 : 24;
  const normalizedColorGamut = colorGamut ?? "unknown";

  // 🧩 Normalize fonts: use count + hash of names
  const normalizedFonts = (() => {
    if (!fonts) return null;
    const names = Array.isArray(fonts.fonts)
      ? fonts.fonts.map((f) => f.toLowerCase()).sort()
      : [];
    return {
      count: names.length,
      hash: simpleHash(names.join(",")),
    };
  })();

  // 🧩 Normalize plugins (order-insensitive)
  const normalizedPlugins = (() => {
    if (!Array.isArray(plugins)) return null;
    const names = plugins.map((p) => p.name || "").sort();
    return {
      count: names.length,
      hash: simpleHash(names.join(",")),
    };
  })();

  // 🧩 Normalize WebGL
  const normalizedWebGL = webgl
    ? {
        vendor: webgl.vendor?.toLowerCase() || "unknown",
        renderer: webgl.renderer?.toLowerCase() || "unknown",
      }
    : null;

  // 🧩 Normalize memory/hardware concurrency (bucket)
  const normalizedMem = mem ? Math.ceil(mem / 2) * 2 : null; // bucket in 2GB
  const normalizedHwc = hwc ? Math.ceil(hwc / 2) * 2 : null; // bucket threads

  // 🧩 Final object
  return removeNulls({
    tz: tz?.toLowerCase(),
    lang: lang?.toLowerCase(),
    languages: languages,
    vendor: vendor?.toLowerCase(),
    colorDepth: normalizedColorDepth,
    colorGamut: normalizedColorGamut,
    screen: normalizedScreen,
    webgl: normalizedWebGL,
    fonts: normalizedFonts,
    plugins: normalizedPlugins,
    mem: normalizedMem,
    hwc: normalizedHwc,
    canvas,
    audio,
  });
}
