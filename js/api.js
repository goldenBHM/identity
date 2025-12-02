export async function sendFingerprint(data) {
  const res = await fetch("https://identity.brightoffers.io/identify", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(data),
  });

  if (!res.ok) throw new Error(`API error: ${res.status}`);
  return await res.json();
}
