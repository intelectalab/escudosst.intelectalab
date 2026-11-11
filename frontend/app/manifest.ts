import type { MetadataRoute } from "next";

// App instalable (PWA, docs/16 §1): se agrega a la pantalla de inicio del celular sin tiendas de aplicaciones.
export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "EscudoSST",
    short_name: "EscudoSST",
    description: "Gestión de Seguridad y Salud en el Trabajo, también en campo sin señal",
    lang: "es-PE",
    // El trabajador entra a /inicio y la app lo lleva a su inicio (/mi); los accesos directos abren la app del trabajador.
    start_url: "/inicio",
    scope: "/",
    display: "standalone",
    orientation: "portrait",
    background_color: "#ffffff",
    theme_color: "#0d3b6e",
    shortcuts: [
      { name: "Mi fotocheck", short_name: "Fotocheck", url: "/mi/fotocheck" },
      { name: "Reportar acto o condición", short_name: "Reportar", url: "/mi/reportar" },
      { name: "Mis pendientes", short_name: "Pendientes", url: "/mi/pendientes" },
    ],
    icons: [
      { src: "/icons/escudo-192.png", sizes: "192x192", type: "image/png", purpose: "any" },
      { src: "/icons/escudo-512.png", sizes: "512x512", type: "image/png", purpose: "any" },
      { src: "/icons/escudo.svg", sizes: "any", type: "image/svg+xml", purpose: "any" },
      { src: "/icons/escudo.svg", sizes: "any", type: "image/svg+xml", purpose: "maskable" },
      { src: "/favicon.ico", sizes: "48x48", type: "image/x-icon" },
    ],
  };
}
