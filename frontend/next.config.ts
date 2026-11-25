import type { NextConfig } from "next";

// Política de seguridad de contenido (docs/10 §6 «cabeceras de seguridad: CSP estricta»). Lista cerrada de orígenes:
//  - scripts propios y Google Tag Manager / Analytics (solo se cargan con consentimiento analítico); Next.js inserta scripts en
//    línea para hidratar la página, por eso 'unsafe-inline' (sin nonce: el sitio se sirve en caché, ver docs/10 §7);
//  - la API (misma dirección en producción; en desarrollo, localhost:8000) y Google Analytics para las conexiones;
//  - mapas de OpenStreetMap y videos de Vimeo y YouTube (sin cookies) como marcos; PDF e imágenes propios como blob:;
//  - nada de objetos ni plugins, base y formularios solo propios, y la aplicación no se puede incrustar en otro sitio.
// Mientras no se verifique en el navegador con todas las pantallas, se publica como «Content-Security-Policy-Report-Only»
// (avisa en la consola, no bloquea). CSP_ENFORCE=1 (al compilar o arrancar Next) la vuelve obligatoria.
const dev = process.env.NODE_ENV !== "production";
const api = (() => {
  try {
    return new URL(process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000").origin;
  } catch {
    return "";
  }
})();
const ga = "https://www.googletagmanager.com https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com";

const csp = [
  "default-src 'self'",
  `script-src 'self' 'unsafe-inline'${dev ? " 'unsafe-eval'" : ""} https://www.googletagmanager.com`,
  "style-src 'self' 'unsafe-inline'",
  `img-src 'self' data: blob: https:${api ? ` ${api}` : ""}`,
  "font-src 'self' data:",
  `connect-src 'self'${api ? ` ${api}` : ""} ${ga}${dev ? " ws: wss:" : ""}`,
  `media-src 'self' blob:${api ? ` ${api}` : ""} https:`,
  "frame-src 'self' blob: https://www.openstreetmap.org https://player.vimeo.com https://www.youtube-nocookie.com https://www.youtube.com",
  "worker-src 'self' blob:",
  "manifest-src 'self'",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'self'",
  ...(dev ? [] : ["upgrade-insecure-requests"]),
].join("; ");

const nextConfig: NextConfig = {
  // Sin el botón flotante «N» de las herramientas de Next en desarrollo: tapaba el menú y confundía.
  // Los errores de compilación se siguen mostrando.
  devIndicators: false,
  // Producción en Docker: Next arma una carpeta mínima con su propio servidor (docs/15 §2.2, «output: standalone»).
  output: "standalone",
  // Sin la cabecera «X-Powered-By: Next.js» (no dar pistas de la tecnología).
  poweredByHeader: false,
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [{ key: process.env.CSP_ENFORCE === "1" ? "Content-Security-Policy" : "Content-Security-Policy-Report-Only", value: csp }],
      },
    ];
  },
};

export default nextConfig;
