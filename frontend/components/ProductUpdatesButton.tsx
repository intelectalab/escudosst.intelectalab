"use client";

// «Novedades» dentro de la aplicación (SCR-WEB-76): lo nuevo de EscudoSST publicado con «mostrar en la app».
// El contador de no leídas se guarda en el navegador (fecha de la última vez que se abrió el panel).

import { useEffect, useRef, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { api } from "@/lib/api";

type Update = { slug: string; title: string; category: "new" | "improvement" | "fix"; excerpt: string; published_at: string };
const KEY = "escudo.updates.seen";
const CAT: Record<Update["category"], [string, string]> = { new: ["Nuevo", "bg-brand-soft text-brand-strong"], improvement: ["Mejora", "bg-ok-soft text-ok"], fix: ["Corrección", "bg-amber-soft text-amber-ink"] };

export function ProductUpdatesButton() {
  const [items, setItems] = useState<Update[]>([]);
  const [seen, setSeen] = useState(0);
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setSeen(Number(localStorage.getItem(KEY) ?? 0));
    api<{ data: Update[] }>("/me/product-updates")
      .then((r) => setItems(r.data))
      .catch(() => undefined);
  }, []);
  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => !box.current?.contains(e.target as Node) && setOpen(false);
    const esc = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", close);
    document.addEventListener("keydown", esc);
    return () => {
      document.removeEventListener("mousedown", close);
      document.removeEventListener("keydown", esc);
    };
  }, [open]);

  const unread = items.filter((u) => new Date(u.published_at).getTime() > seen).length;

  function toggle() {
    if (!open) {
      const now = Date.now();
      localStorage.setItem(KEY, String(now));
      setTimeout(() => setSeen(now), 1500);   // se ve un momento cuáles eran nuevas
    }
    setOpen(!open);
  }

  return (
    <div ref={box} className="relative">
      <button type="button" onClick={toggle} aria-expanded={open} className="relative inline-flex h-10 items-center gap-1.5 rounded-lg border border-line px-3 text-sm font-semibold hover:bg-bg" title="Novedades del producto">
        <Icon name="sparkle" size={17} /> <span className="hidden lg:inline">Novedades</span>
        <span className="sr-only">Novedades{unread ? ` (${unread} sin leer)` : ""}</span>
        {unread > 0 && <span className="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-bad px-1 text-[10px] font-bold text-white">{unread}</span>}
      </button>
      {open && (
        <div className="absolute right-0 z-40 mt-2 w-[min(360px,90vw)] overflow-hidden rounded-2xl border border-line bg-surface shadow-xl">
          <p className="border-b border-line px-4 py-3 font-display font-semibold">Novedades <span className="block text-xs font-normal text-muted">Lo nuevo en EscudoSST</span></p>
          {items.length === 0 && <p className="px-4 py-6 text-sm text-muted">Aún no hay novedades publicadas.</p>}
          <ul className="max-h-[60vh] divide-y divide-line overflow-y-auto">
            {items.map((u) => {
              const isNew = new Date(u.published_at).getTime() > seen;
              return (
                <li key={u.slug} className={`px-4 py-3 ${isNew ? "bg-brand-soft/40" : ""}`}>
                  <span className="flex items-center gap-2 text-xs">
                    <span className={`rounded-full px-2 py-0.5 font-semibold ${CAT[u.category][1]}`}>{CAT[u.category][0]}</span>
                    <span className="text-muted">{new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeZone: "America/Lima" }).format(new Date(u.published_at))}</span>
                  </span>
                  <p className="mt-1 text-sm font-semibold">{u.title}</p>
                  <p className="mt-0.5 text-xs text-muted">{u.excerpt}</p>
                </li>
              );
            })}
          </ul>
        </div>
      )}
    </div>
  );
}
