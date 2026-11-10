// Secciones de la portada: textos de fábrica y lo que el dueño cambió en Sitio web › Portada.
// El panel guarda solo lo cambiado (un texto vacío = el de fábrica) y si la sección se muestra.

export type HomeKey = "hero" | "problem" | "pillars" | "modules" | "features" | "how" | "demo" | "plans" | "testimonials" | "reviews" | "faq" | "seals";

export type HomeSection = { key: HomeKey; visible: boolean; eyebrow: string | null; title: string | null; text: string | null };

type Default = { label: string; help: string; eyebrow?: string; title?: string; text?: string; fixed?: boolean };

/** Orden de la portada y textos de fábrica. Sin `title` la sección no tiene textos que editar (solo se muestra u oculta). */
export const HOME_DEFAULTS: Record<HomeKey, Default> = {
  hero: {
    label: "Inicio (lo primero que se ve)",
    help: "Siempre se muestra. Pon entre asteriscos la parte del título que va resaltada: *llega preparado*.",
    eyebrow: "Seguridad y salud en el trabajo · Perú",
    title: "Cumple la Ley 29783 y *llega preparado* a SUNAFIL",
    text: "IPERC, inspecciones, capacitaciones, EPP, accidentes y el expediente para SUNAFIL en un solo lugar. Para empresas peruanas y consultoras SST.",
    fixed: true,
  },
  problem: {
    label: "El problema",
    help: "Las cuatro tarjetas de «antes y después».",
    eyebrow: "El problema",
    title: "La SST no debería vivir en Excel, Word y papel",
    text: "Así se gestiona hoy en muchas empresas. Con EscudoSST cambia así:",
  },
  pillars: {
    label: "El producto (seis frentes)",
    help: "Escribe {modulos} donde quieras que salga el número de módulos.",
    eyebrow: "El producto",
    title: "Todo el ciclo de tu sistema de gestión SST",
    text: "{modulos} módulos agrupados en seis frentes. Contratas el plan que necesitas y creces cuando lo necesites.",
  },
  modules: {
    label: "Pestañas de módulos",
    help: "Al tocar una pestaña cambian la imagen y la lista de lo que hace ese módulo.",
    eyebrow: "Módulos",
    title: "Mira lo que hace cada módulo",
    text: "Toca un módulo para ver qué resuelve en el día a día.",
  },
  features: { label: "Diferenciadores", help: "Modo Inspección SUNAFIL, fotocheck con QR y reporte sin app, con sus imágenes." },
  how: { label: "Cómo funciona", help: "Los cinco pasos para empezar.", eyebrow: "Cómo funciona", title: "De cero a tu SST ordenada, paso a paso" },
  demo: {
    label: "Demostración",
    help: "El texto, los botones de demo y el reproductor de video.",
    eyebrow: "Demostración",
    title: "¿Quieres verlo con un caso como el tuyo?",
    text: "Agenda una demo y te mostramos EscudoSST con ejemplos de tu sector. Si prefieres explorar solo, crea tu cuenta de prueba.",
  },
  plans: {
    label: "Planes",
    help: "Las tarjetas de planes (solo si hay planes visibles).",
    eyebrow: "Planes",
    title: "Un plan para cada etapa de tu empresa",
    text: "Cada plan incluye todo lo del anterior. Los rangos son referenciales.",
  },
  testimonials: { label: "Testimonios", help: "Solo los aprobados o rotulados «Ejemplo».", eyebrow: "Testimonios", title: "Lo que dicen quienes ya ordenaron su SST" },
  reviews: { label: "Reseñas de Google", help: "Solo si hay una copia vigente en Sitio web › Reseñas de Google." },
  faq: {
    label: "Preguntas frecuentes",
    help: "Las seis primeras preguntas publicadas.",
    eyebrow: "Preguntas frecuentes",
    title: "Lo que suelen preguntarnos",
    text: "¿No encuentras tu duda? Escríbenos y te respondemos.",
  },
  seals: { label: "Sellos de confianza", help: "La fila de sellos antes del cierre." },
};

export const HOME_KEYS = Object.keys(HOME_DEFAULTS) as HomeKey[];

/** Sección lista para mostrar: lo guardado encima de lo de fábrica. */
export function homeSection(saved: HomeSection[], key: HomeKey) {
  const d = HOME_DEFAULTS[key];
  const s = saved.find((x) => x.key === key);
  return {
    visible: d.fixed ? true : (s?.visible ?? true),
    eyebrow: s?.eyebrow || d.eyebrow || "",
    title: s?.title || d.title || "",
    text: s?.text || d.text || "",
  };
}

/** Partes del título: las que estaban entre asteriscos van resaltadas. */
export const highlightParts = (title: string) => title.split("*").map((t, i) => ({ t, hl: i % 2 === 1 }));
