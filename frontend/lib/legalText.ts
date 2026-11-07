// Textos legales: formato simple y seguro (sin HTML) para escribir por secciones.
//  «## 1. Título»   → sección numerada (va al índice)
//  «> texto»        → nota destacada
//  «- texto»        → elemento de lista
//  línea en blanco  → separa párrafos

export type LegalSection = { title: string; body: string };
export type LegalDoc = { intro: string; sections: LegalSection[] };

export function parseLegal(content: string): LegalDoc {
  const parts = content.replace(/\r\n/g, "\n").split(/^## /m);
  const intro = parts.shift()?.trim() ?? "";
  const sections = parts.map((p) => {
    const nl = p.indexOf("\n");
    const rawTitle = (nl === -1 ? p : p.slice(0, nl)).trim();
    return { title: rawTitle.replace(/^\d+\.\s*/, ""), body: nl === -1 ? "" : p.slice(nl + 1).trim() };
  });
  return { intro, sections };
}

export function buildLegal(doc: LegalDoc): string {
  const intro = doc.intro.trim() ? `${doc.intro.trim()}\n\n` : "";
  return (intro + doc.sections.map((s, i) => `## ${i + 1}. ${s.title.trim()}\n\n${s.body.trim()}`).join("\n\n")).trim();
}

export type Block = { kind: "p" | "note" | "list"; text?: string; items?: string[] };

/** Convierte el cuerpo de una sección en bloques para mostrar (párrafos, notas y listas). */
export function blocks(body: string): Block[] {
  return body
    .split(/\n{2,}/)
    .map((chunk) => chunk.trim())
    .filter(Boolean)
    .map((chunk) => {
      const lines = chunk.split("\n");
      if (lines.every((l) => l.trim().startsWith("- "))) return { kind: "list" as const, items: lines.map((l) => l.trim().slice(2)) };
      if (lines.every((l) => l.trim().startsWith(">"))) return { kind: "note" as const, text: lines.map((l) => l.replace(/^>\s?/, "")).join(" ") };
      return { kind: "p" as const, text: lines.join(" ") };
    });
}

export const TEST_MARK = "[Texto de prueba]";

export const slugify = (s: string) =>
  s
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
