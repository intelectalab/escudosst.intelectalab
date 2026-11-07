import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Sin el botón flotante «N» de las herramientas de Next en desarrollo: tapaba el menú y confundía.
  // Los errores de compilación se siguen mostrando.
  devIndicators: false,
  // Producción en Docker: Next arma una carpeta mínima con su propio servidor (docs/15 §2.2, «output: standalone»).
  output: "standalone",
  // Sin la cabecera «X-Powered-By: Next.js» (no dar pistas de la tecnología).
  poweredByHeader: false,
};

export default nextConfig;
