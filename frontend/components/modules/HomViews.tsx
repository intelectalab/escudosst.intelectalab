"use client";

// Homologación de proveedores (M-HOM): calificación de cada contratista en la lista de contratistas (A aprobado, B con condiciones,
// C no aprobado, con su vigencia) y, en el portal del contratista, la autoevaluación por criterio con su evidencia.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { uploadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";

type Hom = { rating: string | null; total_score: number | null; valid_until: string | null; status: string };
type Ctr = { id: string; ruc: string; legal_name: string; status: string; homologation: Hom | null };
const LETTER: Record<string, [string, string, string]> = { approved: ["A", "Aprobado", "bg-ok-soft text-ok"], conditionally_approved: ["B", "Aprobado con condiciones", "bg-amber-soft text-amber-ink"], rejected: ["C", "No aprobado", "bg-bad-soft text-bad"] };
const fmt = (d: string | null) => (d ? d.slice(0, 10).split("-").reverse().join("/") : "—");

export function HomRatings() {
  const [rows, setRows] = useState<Ctr[] | null>(null);
  useEffect(() => { api<{ data: Ctr[] }>("/ctr/invitations", { withCompany: true }).then((r) => setRows(r.data)).catch(() => setRows(null)); }, []);
  if (!rows || rows.length === 0) return null;
  return (
    <Card title="Homologación de contratistas">
      <table className="w-full text-left text-sm">
        <thead className="border-b border-line text-xs text-muted"><tr><th className="py-1">Contratista</th><th>RUC</th><th>Calificación</th><th>Puntaje</th><th>Vigente hasta</th></tr></thead>
        <tbody>{rows.map((c) => {
          const h = c.homologation;
          const l = h?.rating ? LETTER[h.rating] : null;
          const expired = h?.status === "expired" || (h?.valid_until && h.valid_until < new Date().toISOString().slice(0, 10));
          return (
            <tr key={c.id} className="border-b border-line last:border-0">
              <td className="py-1.5">{c.legal_name}</td><td>{c.ruc}</td>
              <td>{l ? <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${l[2]}`} title={l[1]}>{l[0]} · {l[1]}</span> : <span className="text-xs text-muted">Sin homologar</span>}
                {expired && <span className="ml-1 text-xs text-bad">(vencida)</span>}</td>
              <td>{h?.total_score ?? "—"}</td><td>{fmt(h?.valid_until ?? null)}</td>
            </tr>
          );
        })}</tbody>
      </table>
    </Card>
  );
}

type Ev = { id: string; status: string; template: string; criteria: { name: string; weight: number }[]; scores: Record<string, { score?: number; comment?: string; evidence_file_id?: string } | number>;
  total_score: number | null; rating: string | null; valid_until: string | null; passing_score: number | null };
const STATUS: Record<string, string> = { draft: "Por responder", submitted: "Enviada a SST", completed: "Validada por SST", approved: "Aprobada", rejected: "No aprobada", expired: "Vencida" };

export function HomSelfAssessment() {
  const [rows, setRows] = useState<Ev[] | null>(null);
  const [ans, setAns] = useState<Record<string, Record<string, { score: string; comment: string; file: string; fileName: string }>>>({});
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const load = useCallback(() => api<{ data: Ev[] }>("/ctr/portal/homologations", { withCompany: true }).then((r) => setRows(r.data)).catch(() => setRows(null)), []);
  useEffect(() => { void load(); }, [load]);
  if (!rows || rows.length === 0) return null;
  const set = (ev: string, c: string, patch: Partial<{ score: string; comment: string; file: string; fileName: string }>) =>
    setAns((a) => ({ ...a, [ev]: { ...(a[ev] ?? {}), [c]: Object.assign({ score: "", comment: "", file: "", fileName: "" }, a[ev]?.[c], patch) } }));
  const send = async (e: Ev) => {
    setMsg(null);
    try {
      const scores = Object.fromEntries(e.criteria.map((c) => { const a = ans[e.id]?.[c.name]; return [c.name, { score: Number(a?.score), comment: a?.comment || null, evidence_file_id: a?.file || null }]; }));
      await api(`/ctr/portal/homologations/${e.id}`, { method: "POST", withCompany: true, body: { scores } });
      setMsg({ ok: true, text: "Autoevaluación enviada. El área de SST la revisará." });
      void load();
    } catch (err) { const x = err as ApiError; setMsg({ ok: false, text: x.errors ? Object.values(x.errors).flat()[0] : (x.detail ?? x.title) }); }
  };
  return (
    <Card title="Homologación (autoevaluación)">
      {msg && <p role={msg.ok ? "status" : "alert"} className={`mb-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
      <ul className="space-y-3">
        {rows.map((e) => (
          <li key={e.id} className="rounded-lg border border-line p-3 text-sm">
            <p className="font-semibold">{e.template} · {STATUS[e.status] ?? e.status}{e.rating && LETTER[e.rating] ? ` · Calificación ${LETTER[e.rating][0]}` : ""}{e.valid_until ? ` · vigente hasta ${fmt(e.valid_until)}` : ""}</p>
            {e.status === "draft" && (
              <div className="mt-2 space-y-2">
                {e.criteria.map((c) => {
                  const a = ans[e.id]?.[c.name];
                  return (
                    <div key={c.name} className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_6rem_minmax(0,1fr)_auto] sm:items-center">
                      <span>{c.name} <span className="text-xs text-muted">(peso {c.weight})</span></span>
                      <input type="number" min={0} className="input" aria-label={`Puntaje ${c.name}`} value={a?.score ?? ""} onChange={(ev) => set(e.id, c.name, { score: ev.target.value })} />
                      <input className="input" placeholder="Comentario" value={a?.comment ?? ""} onChange={(ev) => set(e.id, c.name, { comment: ev.target.value })} />
                      <label className="cursor-pointer text-xs font-semibold text-brand-strong underline">{a?.fileName || "Evidencia"}
                        <input type="file" className="hidden" onChange={async (ev) => { const f = ev.target.files?.[0]; if (!f) return; const up = await uploadFile(f, "HOM"); set(e.id, c.name, { file: up.id, fileName: up.original_name }); }} /></label>
                    </div>
                  );
                })}
                <Button variant="primary" disabled={e.criteria.some((c) => !ans[e.id]?.[c.name]?.score)} onClick={() => send(e)}>Enviar autoevaluación</Button>
              </div>
            )}
          </li>
        ))}
      </ul>
    </Card>
  );
}
