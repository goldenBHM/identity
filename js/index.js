import { sendFingerprint } from "./api";
import {
  getAudioFingerprint,
  getBasicInfo,
  getCanvasHash,
  getFontMetrics,
  getWebGLInfo,
} from "./collectors";

export async function identify(data) {
  const [canvas, audio] = await Promise.all([
    getCanvasHash(),
    getAudioFingerprint(),
  ]);

  const webgl = getWebGLInfo();
  const fonts = getFontMetrics();

  const payload = {
    ...(await getBasicInfo()),
    canvas,
    audio,
    webgl,
    fonts,
    prepopData: data ?? null,
  };

  return await sendFingerprint(payload);
}

export async function info() {
  const [canvas, audio] = await Promise.all([
    getCanvasHash(),
    getAudioFingerprint(),
  ]);

  const webgl = getWebGLInfo();
  const fonts = getFontMetrics();

  const payload = {
    ...(await getBasicInfo()),
    canvas,
    audio,
    webgl,
    fonts
  };

  return payload;
}
