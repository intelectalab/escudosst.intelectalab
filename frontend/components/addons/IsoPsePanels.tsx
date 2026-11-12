"use client";

// ISO: evaluar cada cláusula con los datos reales de los módulos. PSE: aplicar el paquete del sector a la empresa.

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { api, ApiError } from "@/lib/api";

// RN-ISO-01/03: cobertura derivada por cláusula.
const COVERAGE: Record<string, [string, string]> = {
  covered: ["Cubierta", "bg-ok-soft text-ok"], review: ["Por revisar", "bg-amber-soft text-amber-ink"], none: ["Sin evidencia", "bg-bad-soft text-bad"], not_applicable: ["No aplica", "bg-bg text-muted"],
};
const ISO_ST: Record<string, [string, string]> = {
  implemented: ["Implementado", "bg-ok-soft text-ok"], verified: ["Verificado", "bg-ok-soft text-ok"], in_progress: ["En curso", "bg-amber-soft text-amber-ink"],
  not_started: ["Sin evidencia", "bg-bad-soft text-bad"], not_applicable: ["No aplica", "bg-bg text-muted"],
};

export function IsoAssess({ onDone }: { onDone: () => void }) {
  const [std, setStd] = useState("ISO45001");
  const [res, setRes] = useState<{ standard: string; implemented: number; total: number; review?: number; clauses: { clause: string; title: string; status: string; coverage?: string; evidence: string }[] } | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const run = () => {
    setBusy(true);
    setMsg(null);
    api<{ data: NonNullable<typeof res> }>("/iso/assess", { method: "POST", withCompany: true, body: { standard: std } })
      .then((r) => { setRes(r.data); onDone(); })
      .catch((e: ApiError) => setMsg(e.detail ?? e.title))
      .finally(() => setBusy(false));
  };
  return (
    <section className="space-y-3 rounded-xl border border-line p-4 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <span className="flex-1">Revisa cada cláusula con lo que ya registraste en EscudoSST (política, IPERC, capacitaciones, auditorías, acciones…).</span>
        <select className="input !w-40" value={std} onChange={(e) => setStd(e.target.value)} aria-label="Norma">
          <option value="ISO45001">ISO 45001</option>
          <option value="ISO9001">ISO 9001</option>
          <option value="ISO14001">ISO 14001</option>
        </select>
        <Button variant="primary" loading={busy} onClick={run}>Evaluar con mis datos</Button>
      </div>
      {msg && <p className="text-bad">{msg}</p>}
      {res && (
        <>
          <p className="font-semibold">{res.standard}: {res.implemented} de {res.total} cláusulas cubiertas{res.review ? ` · ${res.review} por revisar` : ""}.</p>
          <ul className="space-y-1">
            {res.clauses.map((c) => {
              const [l, cls] = (c.coverage ? COVERAGE[c.coverage] : undefined) ?? ISO_ST[c.status] ?? [c.status, "bg-bg"];
              return (
                <li key={c.clause} className="flex flex-wrap items-start justify-between gap-2 rounded-lg border border-line px-3 py-2">
                  <span><b>{c.clause}. {c.title}</b><span className="block text-xs text-muted">{c.evidence}</span></span>
                  <span className={`rounded-md px-2 py-0.5 text-xs font-semibold ${cls}`}>{l}</span>
                </li>
              );
            })}
          </ul>
        </>
      )}
    </section>
  );
}

type Pack = { id: string; code: string; name: string; sector: string | null; description: string | null; items: number; applied_at: string | null };

export function PseApply({ onDone }: { onDone: () => void }) {
  const [packs, setPacks] = useState<Pack[] | null>(null);
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const load = () => api<{ data: Pack[] }>("/pse/packs", { withCompany: true }).then((r) => setPacks(r.data)).catch(() => setPacks([]));
  useEffect(() => { load(); }, []);
  const apply = (p: Pack) =>
    api<{ data: { applied: { type: string; name: string }[] } }>(`/pse/packs/${p.id}/apply`, { method: "POST", withCompany: true })
      .then((r) => { setMsg({ text: `${p.name}: se agregaron ${r.data.applied.length} elementos (capacitaciones del sector, plantillas, peligros y permisos).`, ok: true }); load(); onDone(); })
      .catch((e: ApiError) => setMsg({ text: e.detail ?? e.title, ok: false }));
  if (!packs) return null;
  return (
    <section className="space-y-2 rounded-xl border border-line p-4 text-sm">
      <p>Elige el paquete de tu sector: agrega sus capacitaciones típicas y activa sus plantillas de inspección, peligros y tipos de permiso.</p>
      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {packs.map((p) => (
          <div key={p.id} className="flex flex-col justify-between gap-2 rounded-lg border border-line p-3">
            <div><b>{p.name}</b><span className="block text-xs text-muted">{p.items} elementos{p.description ? ` · ${p.description}` : ""}</span></div>
            {p.applied_at ? <span className="text-xs font-semibold text-ok">Aplicado</span> : <Button size="sm" variant="secondary" onClick={() => apply(p)}>Aplicar</Button>}
          </div>
        ))}
      </div>
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </section>
  );
}
