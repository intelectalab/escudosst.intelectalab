"use client";

// Panel › Consultas de empresas: lo que los usuarios escriben desde Ayuda › «Escribir al equipo». Soporte responde
// (la respuesta se ve en la aplicación de la empresa, en «Mis consultas») y cierra la consulta.
// Si para resolverla hay que mirar la empresa por dentro, se pide un acceso de soporte (lo autoriza la empresa).

import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Ticket = {
  id: string; code: string; topic: string; subject: string; message: string; page: string | null; status: "open" | "answered" | "closed";
  reply: string | null; replied_at: string | null; created_at: string; company: string; user_name: string; user_email: string; replied_by_name: string | null;
};
const TOPIC: Record<string, string> = { question: "Duda", problem: "Algo no funciona", suggestion: "Sugerencia", billing: "Plan o facturación" };
const ST: Record<Ticket["status"], ["amber" | "ok" | "na", string]> = { open: ["amber", "Por responder"], answered: ["ok", "Respondida"], closed: ["na", "Cerrada"] };
const fmt = (d: string) => new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(d));

export default function ConsultasPage() {
  const [status, setStatus] = useState<"" | Ticket["status"]>("open");
  const [rows, setRows] = useState<Ticket[] | null>(null);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [sel, setSel] = useState<Ticket | null>(null);
  const [reply, setReply] = useState("");
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    setRows(null);
    api<{ data: Ticket[]; counts: Record<string, number> }>(`/internal/support-tickets${status ? `?status=${status}` : ""}`).then((r) => {
      setRows(r.data);
      setCounts(r.counts ?? {});
    });
  }, [status]);
  useEffect(load, [load]);

  async function send(close: boolean) {
    if (!sel) return;
    try {
      await api(`/internal/support-tickets/${sel.id}/reply`, { method: "POST", body: { reply, close } });
      setToast({ text: close ? "Respuesta enviada y consulta cerrada." : "Respuesta enviada.", tone: "ok" });
      setSel(null);
      setReply("");
      load();
    } catch (e) {
      const err = e as ApiError;
      setToast({ text: Object.values(err.errors ?? {})[0]?.[0] ?? err.detail ?? "No se pudo responder.", tone: "bad" });
    }
  }

  return (
    <div className="w-full space-y-5">
      <PageHeader crumbs={[["Panel de la plataforma"], ["Consultas de empresas"]]} icon="chat" title="Consultas de empresas"
        description="Lo que los usuarios escriben desde Ayuda › «Escribir al equipo». La respuesta les llega en su aplicación, en «Mis consultas»." />

      <div className="flex flex-wrap gap-2" role="group" aria-label="Filtrar por estado">
        {([["open", "Por responder"], ["answered", "Respondidas"], ["closed", "Cerradas"], ["", "Todas"]] as const).map(([v, l]) => (
          <button key={v} onClick={() => setStatus(v)} aria-pressed={status === v}
            className={`rounded-lg border px-3 py-1.5 text-sm font-semibold ${status === v ? "border-brand bg-brand-soft text-brand-strong" : "border-line bg-surface hover:bg-bg"}`}>
            {l}{v && counts[v] ? ` (${counts[v]})` : ""}
          </button>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div className="overflow-hidden rounded-xl border border-line bg-surface">
          {rows === null ? <div className="h-40 animate-pulse bg-line/30" /> : rows.length === 0 ? (
            <p className="p-6 text-sm text-muted">No hay consultas con ese estado.</p>
          ) : (
            <ul className="divide-y divide-line">
              {rows.map((t) => (
                <li key={t.id}>
                  <button onClick={() => { setSel(t); setReply(t.reply ?? ""); }} className={`w-full space-y-1 px-4 py-3 text-left hover:bg-bg ${sel?.id === t.id ? "bg-brand-soft/50" : ""}`}>
                    <span className="flex items-center gap-2 text-xs text-muted">
                      <b>{t.code}</b> · {t.company} · {TOPIC[t.topic] ?? t.topic}
                      <span className="ml-auto"><Chip tone={ST[t.status][0]}>{ST[t.status][1]}</Chip></span>
                    </span>
                    <span className="block text-sm font-semibold">{t.subject}</span>
                    <span className="block text-xs text-muted">{t.user_name} · {fmt(t.created_at)}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="rounded-xl border border-line bg-surface p-4">
          {!sel ? <p className="text-sm text-muted">Elige una consulta para leerla y responder.</p> : (
            <div className="space-y-3">
              <p className="text-xs text-muted">{sel.code} · {sel.company} · {sel.user_name} ({sel.user_email}){sel.page ? ` · desde ${sel.page}` : ""}</p>
              <h2 className="font-display text-lg font-semibold">{sel.subject}</h2>
              <p className="whitespace-pre-line text-sm">{sel.message}</p>
              {sel.status === "closed" ? (
                sel.reply && <div className="rounded-lg bg-bg p-3 text-sm"><b className="block text-xs text-muted">Respuesta{sel.replied_by_name ? ` de ${sel.replied_by_name}` : ""}</b>{sel.reply}</div>
              ) : (
                <>
                  <label className="block space-y-1 text-sm font-semibold">Respuesta
                    <textarea className="input min-h-[8rem] py-2 font-normal" value={reply} onChange={(e) => setReply(e.target.value)} maxLength={4000} />
                  </label>
                  <div className="flex flex-wrap gap-2">
                    <Button variant="primary" onClick={() => send(false)} disabled={reply.trim().length < 2}>Responder</Button>
                    <Button onClick={() => send(true)} disabled={reply.trim().length < 2}>Responder y cerrar</Button>
                  </div>
                  <p className="text-xs text-muted">Si necesitas mirar la empresa por dentro, pide un acceso de soporte: lo autoriza su administrador.</p>
                </>
              )}
            </div>
          )}
        </div>
      </div>
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}
