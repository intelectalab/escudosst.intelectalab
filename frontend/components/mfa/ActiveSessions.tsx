"use client";

// Sesiones activas de mi cuenta (M-USR SCR-USR-05): en qué equipos está abierta, cuándo se usó y cerrar las de otros equipos.

import { useCallback, useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";

type Session = { id: string; device: string; started_at: string; last_used_at: string | null; expires_at: string | null; current: boolean };

const when = (d: string | null) => (d ? new Date(d).toLocaleString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—");

export function ActiveSessions() {
  const [list, setList] = useState<Session[] | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const load = useCallback(() => {
    api<{ data: Session[] }>("/auth/sessions").then((r) => setList(r.data)).catch(() => setList([]));
  }, []);
  useEffect(() => { load(); }, [load]);

  const close = async (path: string, method: "DELETE" | "POST") => {
    setMsg(null);
    try {
      const r = await api<{ data: { closed: number } }>(path, { method });
      setMsg({ ok: true, text: r.data.closed === 1 ? "Se cerró la sesión de ese equipo." : `Se cerraron ${r.data.closed} sesiones.` });
      load();
    } catch (e) {
      const err = e as ApiError;
      setMsg({ ok: false, text: err.detail ?? err.title });
    }
  };

  const others = (list ?? []).filter((s) => !s.current);
  return (
    <div className="space-y-3">
      {!list ? <div className="h-16 animate-pulse rounded-lg bg-bg" /> : (
        <ul className="divide-y divide-line rounded-lg border border-line text-sm">
          {list.map((s) => (
            <li key={s.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
              <span className="min-w-0 flex-1">
                <b>{s.device}</b>{s.current && <span className="ml-2 rounded bg-ok-soft px-1.5 py-0.5 text-xs font-semibold text-ok">Este equipo</span>}
                <span className="block text-xs text-muted">Inició {when(s.started_at)} · último uso {when(s.last_used_at)}</span>
              </span>
              {!s.current && (
                <button type="button" onClick={() => close(`/auth/sessions/${s.id}`, "DELETE")} className="rounded-lg border border-line px-3 py-1 text-xs font-semibold hover:border-bad hover:text-bad">
                  Cerrar
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
      {others.length > 1 && (
        <button type="button" onClick={() => close("/auth/sessions/close-others", "POST")} className="text-sm font-semibold text-bad underline">
          Cerrar todas las demás sesiones
        </button>
      )}
      {msg && <p role={msg.ok ? "status" : "alert"} className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </div>
  );
}
