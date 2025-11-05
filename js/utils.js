export async function hashObject(obj) {
  const enc = new TextEncoder();
  const json = JSON.stringify(obj, Object.keys(obj).sort());
  const hash = await crypto.subtle.digest("SHA-256", enc.encode(json));
  return Array.from(new Uint8Array(hash))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

// ---------- Helpers ----------
export function roundTo(num, decimals = 1) {
  const factor = Math.pow(10, decimals);
  return Math.round(num * factor) / factor;
}

export function simpleHash(str) {
  let hash = 0;
  for (let i = 0; i < str.length; i++) {
    hash = (hash << 5) - hash + str.charCodeAt(i);
    hash |= 0; // 32-bit
  }
  return Math.abs(hash).toString(36);
}

export function removeNulls(obj) {
  return Object.fromEntries(
    Object.entries(obj).filter(
      ([, v]) => v !== null && v !== undefined && v !== ""
    )
  );
}
