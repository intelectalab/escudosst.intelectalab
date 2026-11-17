"use client";

// Política SST (M-POL SCR-POL-01/02): versión vigente con firma y fechas, % de trabajadores que firmaron el cargo, «revisión
// pendiente» (RN-POL-08), PDF y afiche con QR; y en las versiones en trámite, la lista de principios del art. 23 que debe estar
// completa para enviarla a revisión o aprobarla (RN-POL-01). Solo gerencia aprueba (RN-POL-02).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Card, Kpi } from "./Summary";

type Pol = { id: string; version_number: number; title: string; status: string; effective_on: string | null; review_due_on: string | null; approved_at: string | null;
  approved_by: string | null; principles_checklist: string[]; review_pending?: boolean };
type Data = { current: Pol | null; acknowledgements: { active: number; signed: number; pct: number | null } | null; drafts: Pol[]; principles: string[]; amber_pct: number; can_approve: boolean };
const STATUS: Record<string, string> = { draft: "Borrador", in_review: "En revisión", approved: "Aprobada", current: "Vigente", review_required: "Requiere revisión", obsolete: "Obsoleta" };
const fmt = (d: string | null) => (d ? d.slice(0, 10).split("-").reverse().join("/") : "—");

export function PolOverview({ readOnly }: { readOnly: boolean }) {
  const [d, setD] = useState<Data | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const load = useCallback(() => api<{ data: Data }>("/pol/overview", { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)), []);
  useEffect(() => { void load(); }, [load]);

  const toggle = async (p: Pol, principle: string) => {
    setMsg(null);
    const next = p.principles_checklist.includes(principle) ? p.principles_checklist.filter((x) => x !== principle) : [...p.principles_checklist, principle];
    try {
      await api(`/records/POL/sst_policies/${p.id}`, { method: "PATCH", withCompany: true, body: { principles_checklist: next } });
      void load();
    } catch (e) {
      const err = e as ApiError;
      setMsg({ ok: false, text: err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title) });
    }
  };

  if (!d) return null;
  const c = d.current;
  return (
    <div className="space-y-4">
      <Card title="Política SST vigente">
        {!c ? <p className="text-sm text-muted">Aún no hay una política vigente.</p> : (
          <div className="space-y-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <span><b>{c.title}</b> <span className="text-sm text-muted">· versión {c.version_number}</span>
                <span className="block text-sm text-muted">Vigente desde {fmt(c.effective_on)} · Aprobada por {c.approved_by ?? "—"} el {fmt(c.approved_at)} · Próxima revisión {fmt(c.review_due_on)}</span></span>
              {c.review_pending && <span className="rounded-full bg-amber-soft px-2.5 py-0.5 text-xs font-semibold text-amber-ink">Revisión pendiente</span>}
            </div>
            {d.acknowledgements && (
              <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                <Kpi label="Trabajadores que firmaron el cargo" value={d.acknowledgements.pct == null ? "—" : `${d.acknowledgements.pct}%`} sub={`${d.acknowledgements.signed} de ${d.acknowledgements.active} activos`} />
              </div>
            )}
            <div className="flex flex-wrap gap-2">
              <a href={`/imprimir/politica/${c.id}`} target="_blank" rel="noreferrer" className="inline-flex h-9 items-center rounded-lg border border-line px-3 text-sm font-semibold hover:border-brand">Política en PDF</a>
              <a href={`/imprimir/afiche-politica/${c.id}`} target="_blank" rel="noreferrer" className="inline-flex h-9 items-center rounded-lg border border-line px-3 text-sm font-semibold hover:border-brand">Afiche con QR</a>
            </div>
          </div>
        )}
      </Card>
      {d.drafts.length > 0 && (
        <Card title="Versiones en trámite: principios del art. 23">
          <p className="mb-2 text-sm text-muted">Para enviar la versión a revisión o aprobarla deben estar marcados todos los principios. {d.can_approve ? "Tu rol puede aprobar la política." : "La aprueba la gerencia."}</p>
          {d.drafts.map((p) => (
            <div key={p.id} className="mb-3 rounded-lg border border-line p-3">
              <p className="mb-2 text-sm"><b>{p.title}</b> · versión {p.version_number} · {STATUS[p.status] ?? p.status}
                <span className="ml-2 text-xs text-muted">{p.principles_checklist.filter((x) => d.principles.includes(x)).length} de {d.principles.length} principios</span></p>
              <ul className="space-y-1">
                {d.principles.map((pr) => (
                  <li key={pr}><label className="flex items-start gap-2 text-sm">
                    <input type="checkbox" className="mt-1" checked={p.principles_checklist.includes(pr)} disabled={readOnly || p.status === "approved"} onChange={() => toggle(p, pr)} /> {pr}
                  </label></li>
                ))}
              </ul>
              <a href={`/imprimir/politica/${p.id}`} target="_blank" rel="noreferrer" className="mt-2 inline-block text-xs font-semibold text-brand-strong underline">Vista previa del PDF</a>
            </div>
          ))}
          {msg && <p role="alert" className="text-sm text-bad">{msg.text}</p>}
        </Card>
      )}
      <AmberSetting initial={d.amber_pct} readOnly={readOnly} />
    </div>
  );
}

/** RN-POL-07: umbral ámbar de las metas de la empresa (con historial en sus parámetros). Las metas ligadas a un indicador toman su valor de él. */
function AmberSetting({ initial, readOnly }: { initial: number; readOnly: boolean }) {
  const [v, setV] = useState(String(initial));
  const [saved, setSaved] = useState(initial);
  const [msg, setMsg] = useState<string | null>(null);
  const save = () => api<{ data: { objective_amber_pct: number } }>("/pol/settings", { method: "PUT", withCompany: true, body: { objective_amber_pct: Number(v) } })
    .then((r) => { setSaved(r.data.objective_amber_pct); setMsg("Guardado."); }).catch((e: ApiError) => setMsg(e.errors ? Object.values(e.errors).flat()[0] : e.detail ?? e.title));
  return (
    <div className="flex flex-wrap items-center gap-2 text-xs text-muted">
      <span>Metas: en ámbar desde el {saved} % de avance; por debajo, en rojo. Las metas de un objetivo ligado a un indicador toman su valor del indicador.</span>
      {!readOnly && (
        <span className="flex items-center gap-1">
          <input className="input !h-8 !w-16" inputMode="numeric" value={v} onChange={(e) => setV(e.target.value)} aria-label="Umbral ámbar en %" />%
          <button type="button" className="rounded border border-line px-2 py-1 font-semibold" onClick={save}>Cambiar</button>
          {msg && <span>{msg}</span>}
        </span>
      )}
    </div>
  );
}
