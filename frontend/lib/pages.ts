// Páginas del sitio por bloques (SCR-WEB-03, 04, 08 y 20). Lo mismo lo usa el sitio (para mostrarlas) y el
// panel (para editarlas y verlas en vista previa).

export type Block =
  | { type: "hero"; eyebrow: string; title: string; text: string }
  | { type: "text"; title: string; body: string }
  | { type: "bullets"; title: string; items: string[] }
  | { type: "norms"; title: string; items: string[] }
  | { type: "modules"; title: string; items: string[] }
  | { type: "video"; title: string; url: string }
  | { type: "faq"; title: string; items: { q: string; a: string }[] }
  | { type: "cta"; title: string; text: string; button: "trial" | "demo" | "consultancy" }
  | { type: "image"; title: string; caption: string; media_id: string; file_id?: string; alt?: string | null; width?: number | null; height?: number | null }
  | { type: "compare"; title: string; manual_label: string; escudo_label: string; items: { task: string; manual: string; escudo: string }[] };

export type BlockType = Block["type"];

export type PublicPage = { slug: string; page_type: string; title: string; summary: string | null; blocks: Block[]; whatsapp_message?: string | null; seo?: { meta_title?: string; meta_description?: string; noindex?: boolean } };

export type PageType = "sector" | "product" | "landing" | "comparison" | "campaign";

export const PAGE_TYPES: Record<PageType, { label: string; plural: string; prefix: string; icon: string }> = {
  sector: { label: "Sector", plural: "Páginas por sector", prefix: "sectores/", icon: "building" },
  product: { label: "Diferenciador", plural: "Diferenciadores", prefix: "soluciones/", icon: "star" },
  landing: { label: "Página", plural: "Otras páginas", prefix: "", icon: "file" },
  comparison: { label: "Comparativa", plural: "Comparativa (EscudoSST vs Excel y papel)", prefix: "", icon: "swap" },
  campaign: { label: "Landing de campaña", plural: "Landings de campaña", prefix: "l/", icon: "tag" },
};

/** Catálogo de bloques del editor: nombre, para qué sirve y cómo empieza uno nuevo. */
export const BLOCK_TYPES: { type: BlockType; label: string; help: string; icon: string; empty: () => Block }[] = [
  { type: "hero", label: "Encabezado", help: "Título grande con una frase.", icon: "star", empty: () => ({ type: "hero", eyebrow: "", title: "", text: "" }) },
  { type: "text", label: "Texto", help: "Un párrafo con título.", icon: "pen", empty: () => ({ type: "text", title: "", body: "" }) },
  { type: "bullets", label: "Lista con viñetas", help: "Beneficios, riesgos o pasos.", icon: "check", empty: () => ({ type: "bullets", title: "", items: [""] }) },
  { type: "norms", label: "Normativa", help: "Normas de referencia (se rotulan «a validar»).", icon: "shield", empty: () => ({ type: "norms", title: "Normativa de referencia", items: [""] }) },
  { type: "modules", label: "Módulos", help: "Tarjetas de módulos del sistema.", icon: "grid", empty: () => ({ type: "modules", title: "Módulos que más te sirven", items: [] }) },
  { type: "video", label: "Video", help: "YouTube o Vimeo. Se carga solo si la persona acepta cookies de marketing.", icon: "play", empty: () => ({ type: "video", title: "", url: "" }) },
  { type: "faq", label: "Preguntas", help: "Preguntas frecuentes propias de esta página.", icon: "search", empty: () => ({ type: "faq", title: "Preguntas frecuentes", items: [{ q: "", a: "" }] }) },
  { type: "image", label: "Imagen", help: "Una foto o captura de la biblioteca de medios.", icon: "eye", empty: () => ({ type: "image", title: "", caption: "", media_id: "" }) },
  {
    type: "compare",
    label: "Tabla comparativa",
    help: "Cómo se hace cada tarea hoy (Excel y papel) frente a EscudoSST. Sin precios ni nombres de otras empresas.",
    icon: "swap",
    empty: () => ({ type: "compare", title: "", manual_label: "Con Excel y papel", escudo_label: "Con EscudoSST", items: [{ task: "", manual: "", escudo: "" }] }),
  },
  { type: "cta", label: "Llamado a la acción", help: "Invitación final con botón.", icon: "chevron", empty: () => ({ type: "cta", title: "", text: "", button: "trial" }) },
];

export const CTA_BUTTONS: Record<"trial" | "demo" | "consultancy", [string, string]> = {
  trial: ["Prueba gratis", "/prueba-gratis"],
  demo: ["Agenda una demo", "/demo"],
  consultancy: ["Agenda una demo para consultoras", "/demo?plan=K"],
};

/** Id del video para incrustarlo (YouTube en modo privacidad o Vimeo). */
export function videoEmbed(url: string): { embed: string; provider: string } | null {
  const yt = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]{6,})/);
  if (yt) return { embed: `https://www.youtube-nocookie.com/embed/${yt[1]}`, provider: "YouTube" };
  const vm = url.match(/vimeo\.com\/(\d+)/);
  if (vm) return { embed: `https://player.vimeo.com/video/${vm[1]}?dnt=1`, provider: "Vimeo" };
  return null;
}
