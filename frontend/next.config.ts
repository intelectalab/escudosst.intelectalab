import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Sin el botón flotante «N» de las herramientas de Next en desarrollo: tapaba el menú y confundía.
  // Los errores de compilación se siguen mostrando.
  devIndicators: false,
};

export default nextConfig;
