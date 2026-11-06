"use client";

// Campana de avisos del panel de la plataforma: lo pendiente (reclamos por vencer, solicitudes nuevas, casos por
// aprobar…). La API los calcula al momento; «leído» se guarda en este navegador (hora de la última revisión).

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { api } from "@/lib/api";

export type Notice = { key: string; group: string; icon: string; tone: "bad" | "amber" | "brand"; count: number; at: string | null; title: string; detail: string; href: string };

const SEEN_KEY = "escudo.notices.seen";
const TONE = { bad: "bg-bad-soft text-bad", amber: "bg-amber-soft text-amber-ink", brand: "bg-brand-soft text-brand-strong" };

export const seenAt = () => (typeof window === "undefined" ? 0 : Number(window.localStorage.getItem(SEEN_KEY) ?? 0));
export const markSeen = () => window.localStorage.setItem(SEEN_KEY, String(Date.now()));
export const isNew = (n: Notice, since: number) => !!n.at && new Date(n.at).getTime() > since;

/** Lee los avisos y los vuelve a pedir cada minuto. */
export function useNotices() {
  const [items, setItems] = useState<Notice[] | null>(null);
  const load = useCallback(() => {
    api<{ data: Notice[] }>("/internal/notifications")
      .then((r) => setItems(r.data))
      .catch(() => setItems((x) => x ?? []));
  }, []);
  useEffect(() => {
    load();
    const t = window.setInterval(load, 60_000);
    return () => window.clearInterval(t);
  }, [load]);
  return { items, reload: load };
}

export function NoticeRow({ n, fresh, onClick }: { n: Notice; fresh: boolean; onClick?: () => void }) {
  return (
    <Link href={n.href} onClick={onClick} className="flex items-start gap-3 rounded-xl px-3 py-2.5 transition-colors hover:bg-bg">
      <span className={`grid h-9 w-9 shrink-0 place-items-center rounded-xl ${TONE[n.tone]}`}>
        <Icon name={n.icon} size={16} />
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-2">
          <b className="text-sm">{n.title}</b>
          {fresh && <span className="h-2 w-2 shrink-0 rounded-full bg-brand" aria-label="Nuevo" />}
        </span>
        <span className="block text-xs text-muted">{n.detail}</span>
        {n.at && <span className="mt-0.5 block text-[11px] text-muted/80">Último: {ago(n.at)}</span>}
      </span>
    </Link>
  );
}

export function ago(at: string): string {
  const m = Math.round((Date.now() - new Date(at).getTime()) / 60000);
  if (m < 1) return "ahora";
  if (m < 60) return `hace ${m} min`;
  const h = Math.round(m / 60);
  if (h < 24) return `hace ${h} h`;
  const d = Math.round(h / 24);
  return d === 1 ? "ayer" : d < 30 ? `hace ${d} días` : new Date(at).toLocaleDateString("es-PE");
}

export function NotificationsBell() {
  const { items, reload } = useNotices();
  const [open, setOpen] = useState(false);
  const [since, setSince] = useState(0);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => setSince(seenAt()), []);
  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => !ref.current?.contains(e.target as Node) && setOpen(false);
    const esc = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", close);
    document.addEventListener("keydown", esc);
    return () => {
      document.removeEventListener("mousedown", close);
      document.removeEventListener("keydown", esc);
    };
  }, [open]);

  const list = items ?? [];
  const unread = list.filter((n) => isNew(n, since)).length;
  const total = list.reduce((a, n) => a + n.count, 0);

  function readAll() {
    markSeen();
    setSince(Date.now());
  }

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => {
          setOpen((o) => !o);
          if (!open) reload();
        }}
        aria-expanded={open}
        aria-label={unread ? `Avisos: ${unread} nuevos` : "Avisos"}
        className="relative grid h-10 w-10 place-items-center rounded-xl text-muted transition-colors hover:bg-bg hover:text-fg"
      >
        <Icon name="bell" size={20} />
        {total > 0 && (
          <span className={`absolute -right-0.5 -top-0.5 grid h-5 min-w-5 place-items-center rounded-full px-1 text-[11px] font-bold text-white ring-2 ring-surface ${unread ? "bg-bad" : "bg-muted"}`}>
            {total > 99 ? "99+" : total}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 top-12 z-50 w-[min(24rem,calc(100vw-1.5rem))] overflow-hidden rounded-2xl border border-line bg-surface shadow-xl">
          <div className="flex items-center justify-between border-b border-line px-4 py-3">
            <b className="font-display">Avisos</b>
            {unread > 0 && (
              <button type="button" onClick={readAll} className="text-xs font-semibold text-brand hover:underline">
                Marcar todo como leído
              </button>
            )}
          </div>
          <div className="max-h-[60vh] overflow-y-auto p-2">
            {items === null ? (
              <p className="p-4 text-sm text-muted">Cargando…</p>
            ) : list.length === 0 ? (
              <div className="p-6 text-center">
                <span className="mx-auto grid h-11 w-11 place-items-center rounded-full bg-ok-soft text-ok">
                  <Icon name="check" size={20} />
                </span>
                <p className="mt-2 text-sm font-semibold">Todo al día</p>
                <p className="text-xs text-muted">No hay nada pendiente.</p>
              </div>
            ) : (
              list.map((n) => <NoticeRow key={n.key} n={n} fresh={isNew(n, since)} onClick={() => setOpen(false)} />)
            )}
          </div>
          <Link href="/plataforma/avisos" onClick={() => setOpen(false)} className="block border-t border-line px-4 py-2.5 text-center text-sm font-semibold text-brand hover:bg-bg">
            Ver todos los avisos
          </Link>
        </div>
      )}
    </div>
  );
}
