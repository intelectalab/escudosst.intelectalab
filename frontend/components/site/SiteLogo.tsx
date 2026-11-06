// Logo del sitio: el que subió el dueño en Sitio web › Marca (si existe) o el de EscudoSST por defecto.
// `light` = sobre fondo oscuro (pie): usa el «logo para fondo oscuro» si lo subieron.

import { Brand } from "@/components/Brand";

export function SiteLogo({ src, alt, light = false }: { src: string | null; alt: string; light?: boolean }) {
  if (!src) return <Brand light={light} />;
  // eslint-disable-next-line @next/next/no-img-element -- archivo servido por la API con caché larga; tamaño variable
  return <img src={src} alt={alt} className="h-9 w-auto max-w-[200px] object-contain lg:h-10" />;
}
