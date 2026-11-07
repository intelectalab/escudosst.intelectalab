// Marca del sitio: el logo que subió el dueño en Sitio web › Marca (si no hay, el escudo de EscudoSST)
// SIEMPRE junto al nombre comercial (se cambia en Sitio web › Marca, campo «Nombre comercial»).
// `light` = sobre fondo oscuro (pie): usa el «logo para fondo oscuro»; si no lo subieron, el normal sobre una placa blanca.

import { Brand } from "@/components/Brand";

export function SiteLogo({ src, darkSrc = null, alt, light = false }: { src: string | null; darkSrc?: string | null; alt: string; light?: boolean }) {
  const file = light ? (darkSrc ?? src) : src;
  if (!file) return <Brand light={light} name={alt} />;
  const plate = light && !darkSrc;
  return (
    <span className="flex items-center gap-2.5">
      <span className={`grid h-9 shrink-0 place-items-center lg:h-10 ${plate ? "rounded-lg bg-white px-1.5" : ""}`}>
        {/* eslint-disable-next-line @next/next/no-img-element -- archivo servido por la API con caché larga; tamaño variable */}
        <img src={file} alt="" className="h-8 w-auto max-w-[120px] object-contain lg:h-9" />
      </span>
      <span className={`font-display text-xl font-semibold ${light ? "text-white" : "text-[var(--site-navy-title)]"}`}>{alt}</span>
    </span>
  );
}
