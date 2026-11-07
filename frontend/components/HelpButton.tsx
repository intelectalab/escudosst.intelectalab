"use client";

// Ayuda contextual (SCR-WEB-34, RN-WEB-44): botón «Ayuda» en la cabecera de la aplicación. Abre un panel lateral
// (hoja completa en el celular) con los artículos de la pantalla abierta; si no hay, muestra el buscador.
// Solo viaja el código de la pantalla: ningún dato de la empresa ni del usuario.

import { usePathname } from "next/navigation";
import { FormEvent, useEffect, useState } from "react";
import { HelpBody } from "@/components/site/HelpBody";
import { HelpFeedback } from "@/components/site/HelpFeedback";
import { Icon } from "@/components/ui/Icon";
import { api } from "@/lib/api";
import type { HelpArticle, HelpListItem } from "@/lib/help";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

/** Código SCR-* de cada pantalla de la aplicación que ya existe. */
const SCREENS: [RegExp, string][] = [
  [/^\/inicio$/, "SCR-DSH-01"],
  [/^\/cuenta/, "SCR-USR-05"],
];

export function screenCodeFor(path: string): string | null {
  return SCREENS.find(([re]) => re.test(path))?.[1] ?? null;
}

async function publicGet<T>(path: string): Promise<T | null> {
  try {
    const r = await fetch(`${API}/api/v1/public/${path}`, { headers: { Accept: "application/json" } });
    return r.ok ? ((await r.json()) as { data: T }).data : null;
  } catch {
    return null;
  }
}

/** screenCode: si la pantalla quiere fijar su código; si no, sale de la ruta abierta. */
export function HelpButton({ screenCode }: { screenCode?: string } = {}) {
  const pathname = usePathname();
  const code = screenCode ?? screenCodeFor(pathname);
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<HelpListItem[] | null>(null);
  const [q, setQ] = useState("");
  const [searched, setSearched] = useState<string | null>(null);
  const [article, setArticle] = useState<HelpArticle | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!open) return;
    setArticle(null);
    setSearched(null);
    if (!code) {
      setItems([]);
      return;
    }
    setItems(null);
    api<{ data: HelpListItem[] }>(`/me/help?screen_code=${code}`)
      .then((r) => setItems(r.data))
      .catch(() => setItems([]));
  }, [open, code]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);

  async function search(e: FormEvent) {
    e.preventDefault();
    const term = q.trim();
    if (!term) return;
    setLoading(true);
    setArticle(null);
    setItems((await publicGet<HelpListItem[]>(`help/articles?q=${encodeURIComponent(term)}`)) ?? []);
    setSearched(term);
    setLoading(false);
  }

  async function openArticle(slug: string) {
    setLoading(true);
    setArticle(await publicGet<HelpArticle>(`help/articles/${slug}`));
    setLoading(false);
  }

  return (
    <>
      <button onClick={() => setOpen(true)} className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-muted hover:bg-bg hover:text-fg" aria-haspopup="dialog">
        <Icon name="help" size={17} /> <span className="hidden sm:inline">Ayuda</span>
      </button>
      {open && (
        <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-label="Ayuda">
          <button className="absolute inset-0 bg-[#00254d]/30 md:bg-[#00254d]/15" aria-label="Cerrar ayuda" onClick={() => setOpen(false)} />
          <aside className="relative flex h-full w-full flex-col bg-surface shadow-2xl md:w-[420px] md:border-l md:border-line">
            <header className="flex items-center gap-2 border-b border-line bg-gradient-to-r from-brand-soft to-surface px-4 py-3">
              {article ? (
                <button onClick={() => setArticle(null)} className="grid h-9 w-9 place-items-center rounded-lg hover:bg-bg" aria-label="Volver a la lista">
                  <Icon name="back" size={18} />
                </button>
              ) : (
                <span className="grid h-9 w-9 place-items-center rounded-lg bg-brand text-white">
                  <Icon name="help" size={18} />
                </span>
              )}
              <p className="min-w-0 flex-1 truncate font-display font-semibold">{article ? article.title : "Ayuda de esta pantalla"}</p>
              <button onClick={() => setOpen(false)} className="grid h-9 w-9 place-items-center rounded-lg hover:bg-bg" aria-label="Cerrar">
                <Icon name="close" size={18} />
              </button>
            </header>

            <div className="flex-1 space-y-4 overflow-y-auto p-4">
              {article ? (
                <>
                  <HelpBody body={article.body} compact />
                  {article.video_url && (
                    <a href={article.video_url} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand hover:underline">
                      <Icon name="play" size={15} /> Ver el video
                    </a>
                  )}
                  <HelpFeedback slug={article.slug} compact />
                  <a href={`/ayuda/${article.slug}`} target="_blank" rel="noopener" className="block text-sm font-semibold text-brand hover:underline">
                    Abrir en una pestaña nueva →
                  </a>
                </>
              ) : (
                <>
                  <form onSubmit={search} role="search" className="flex gap-2">
                    <label className="relative min-w-0 flex-1">
                      <span className="sr-only">Buscar en la ayuda</span>
                      <Icon name="search" size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
                      <input className="input pl-9" value={q} onChange={(e) => setQ(e.target.value)} maxLength={100} placeholder="Buscar en la ayuda" />
                    </label>
                    <button type="submit" className="rounded-lg bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-strong">
                      Buscar
                    </button>
                  </form>
                  {items === null || loading ? (
                    <div className="h-32 animate-pulse rounded-xl bg-line/40" />
                  ) : items.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-line p-4 text-sm text-muted">
                      {searched ? `No encontramos artículos sobre «${searched}». Prueba con otras palabras.` : "Esta pantalla todavía no tiene guías. Usa el buscador o revisa el centro de ayuda."}
                    </p>
                  ) : (
                    <div>
                      <p className="mb-2 text-xs font-bold uppercase tracking-wide text-muted">{searched ? `Resultados para «${searched}»` : "Guías de esta pantalla"}</p>
                      <ul className="space-y-2">
                        {items.map((a) => (
                          <li key={a.slug}>
                            <button onClick={() => openArticle(a.slug)} className="w-full rounded-xl border border-line bg-white p-3 text-left hover:border-brand/50 hover:shadow-sm">
                              <b className="block text-sm">{a.title}</b>
                              <span className="mt-0.5 line-clamp-2 block text-xs text-muted">{a.excerpt}</span>
                            </button>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                </>
              )}
            </div>

            <footer className="border-t border-line p-4">
              <a href="/ayuda" target="_blank" rel="noopener" className="flex items-center justify-center gap-2 rounded-xl border border-line px-4 py-2.5 text-sm font-semibold hover:bg-bg">
                <Icon name="book" size={16} /> Ir al centro de ayuda
              </a>
            </footer>
          </aside>
        </div>
      )}
    </>
  );
}
