"use client";

// Campana de avisos de la empresa activa (GET /me/notifications). Hoy: avisos de la suscripción
// (por vencer, pago pendiente, solo lectura, pago registrado). Se actualiza cada 2 minutos.

import { useCallback, useEffect, useRef, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { api } from "@/lib/api";
import { formatDate } from "@/lib/accounts";

type Notice = { id: string; event_code: string; title: string; body: string | null; priority: string; read_at: string | null; created_at: string };

const PRIORITY_DOT: Record<string, string> = { critical: "bg-bad", high: "bg-bad", normal: "bg-amber-ink", low: "bg-muted" };

export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<Notice[]>([]);
  const [unread, setUnread] = useState(0);
  const ref = useRef<HTMLDivElement>(null);

  const load = useCallback(() => {
    api<{ data: Notice[]; unread: number }>("/me/notifications", { withCompany: true })
      .then((r) => {
        setItems(r.data);
        setUnread(r.unread);
      })
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    load();
    const t = setInterval(load, 120_000);
    return () => clearInterval(t);
  }, [load]);

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false);
    const esc = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", close);
    document.addEventListener("keydown", esc);
    return () => {
      document.removeEventListener("mousedown", close);
      document.removeEventListener("keydown", esc);
    };
  }, [open]);

  async function markRead(n: Notice) {
    if (n.read_at) return;
    await api(`/me/notifications/${n.id}/read`, { method: "POST", withCompany: true }).catch(() => undefined);
    load();
  }

  async function markAll() {
    await api("/me/notifications/read-all", { method: "POST", withCompany: true }).catch(() => undefined);
    load();
  }

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-label={unread ? `Avisos: ${unread} sin leer` : "Avisos"}
        aria-expanded={open}
        className="relative grid h-10 w-10 place-items-center rounded-xl border border-line bg-surface hover:bg-bg"
      >
        <Icon name="bell" size={19} />
        {unread > 0 && (
          <span className="absolute right-0.5 top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-bad px-1 text-[10px] ring-2 ring-surface font-bold text-white">{unread > 9 ? "9+" : unread}</span>
        )}
      </button>

      {open && (
        <div className="fixed inset-x-3 top-16 z-50 overflow-hidden rounded-2xl border border-line bg-surface shadow-2xl sm:absolute sm:inset-x-auto sm:right-0 sm:top-12 sm:w-96">
          <div className="flex items-center justify-between border-b border-line px-4 py-3">
            <p className="font-display font-semibold">Avisos</p>
            {unread > 0 && (
              <button type="button" onClick={markAll} className="text-sm font-semibold text-brand hover:underline">
                Marcar todos como leídos
              </button>
            )}
          </div>
          {items.length === 0 ? (
            <p className="flex flex-col items-center gap-2 px-4 py-10 text-center text-sm text-muted">
              <Icon name="bell" size={24} />
              No tienes avisos.
            </p>
          ) : (
            <ul className="max-h-[60vh] divide-y divide-line overflow-y-auto">
              {items.map((n) => (
                <li key={n.id}>
                  <button type="button" onClick={() => markRead(n)} className={`flex w-full gap-3 px-4 py-3 text-left hover:bg-bg ${n.read_at ? "" : "bg-brand-soft/30"}`}>
                    <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${n.read_at ? "bg-transparent" : (PRIORITY_DOT[n.priority] ?? "bg-brand")}`} />
                    <span className="min-w-0">
                      <span className={`block text-sm ${n.read_at ? "font-medium" : "font-semibold"}`}>{n.title}</span>
                      {n.body && <span className="mt-0.5 block text-xs leading-relaxed text-muted">{n.body}</span>}
                      <span className="mt-1 block text-[11px] text-muted">{formatDate(n.created_at, true)}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
