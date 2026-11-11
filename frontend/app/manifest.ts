import type { MetadataRoute } from "next";

// App instalable (PWA, docs/16 §1): se agrega a la pantalla de inicio del celular sin tiendas de aplicaciones.
export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "EscudoSST",
    short_name: "EscudoSST",
    description: "Gestión de Seguridad y Salud en el Trabajo, también en campo sin señal",
    lang: "es-PE",
    start_url: "/inicio",
    scope: "/",
    display: "standalone",
    orientation: "portrait",
    background_color: "#ffffff",
    theme_color: "#0d3b6e",
    icons: [
      { src: "/icons/escudo.svg", sizes: "any", type: "image/svg+xml", purpose: "any" },
      { src: "/icons/escudo.svg", sizes: "any", type: "image/svg+xml", purpose: "maskable" },
      { src: "/favicon.ico", sizes: "48x48", type: "image/x-icon" },
    ],
  };
}
