"use client";

// Nuevo ATS (M-ATS SCR-ATS-02): a un ATS en borrador se le copian los pasos desde un PETS vigente, desde un ATS anterior o desde
// filas del IPERC vigente del área (peligros, riesgos y controles sugeridos, RN-ATS-08).

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";

type FormOpt = { id: string; code: string | null; task: string; status: string };
type Sources = {
  pets: { id: string; code: string | null; title: string; version_number: number; steps: number }[];
  ats: { id: string; code: string | null; task: string; performed_on: string | null; steps: number }[];
  iperc: { id: string; task: string | null; activity: string | null; hazard: string; risk: string; risk_level: string | null; matrix: string | null; controls: string | null }[];
};
type Tab = "pets" | "ats" | "iperc";
const TABS: [Tab, string][] = [["pets", "Desde un PETS"], ["ats", "Desde un ATS anterior"], ["iperc", "Peligros del IPERC"]];

export function AtsCopy({ onSaved }: { onSaved: () => void }) {
  const [forms, setForms] = useState<FormOpt[]>([]);
  const [form, setForm] = useState("");
  const [src, setSrc] = useState<Sources | null>(null);
  const [tab, setTab] = useState<Tab>("pets");
  const [pick, setPick] = useState("");
  const [rows, setRows] = useState<Set<string>>(new Set());
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [v, setV] = useState(0);

  useEffect(() => {
    api<{ data: FormOpt[] }>("/records/ATS/ats_forms?per_page=100", { withCompany: true })
      .then((r) => setForms((r.data ?? []).filter((f) => f.status === "draft"))).catch(() => setForms([]));
  }, [v]);
  useEffect(() => {
    setSrc(null);
    setPick("");
    setRows(new Set());
    if (!form) return;
    api<{ data: Sources }>(`/ats/forms/${form}/sources`, { withCompany: true }).then((r) => setSrc(r.data)).catch((e: ApiError) => setMsg({ ok: false, text: e.detail ?? e.title }));
  }, [form]);

  const copy = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const r = await api<{ data: { added: number } }>(`/ats/forms/${form}/copy`, { method: "POST", withCompany: true,
        body: tab === "iperc" ? { source: "iperc", iperc_row_ids: [...rows] } : { source: tab, source_id: pick } });
      setMsg({ ok: true, text: `Se agregaron ${r.data.added} paso(s) al ATS. Revísalos en su ficha antes de firmarlo.` });
      setPick("");
      setRows(new Set());
      setV((x) => x + 1);
      onSaved();
    } catch (e) {
      const err = e as ApiError;
      setMsg({ ok: false, text: err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title) });
    } finally {
      setBusy(false);
    }
  };
  const can = tab === "iperc" ? rows.size > 0 : !!pick;

  return (
    <Card title="Copiar pasos a un ATS en borrador">
      <select className="input max-w-xl" aria-label="ATS en borrador" value={form} onChange={(e) => setForm(e.target.value)}>
        <option value="">Elige el ATS en borrador…</option>
        {forms.map((f) => <option key={f.id} value={f.id}>{[f.code, f.task].filter(Boolean).join(" · ")}</option>)}
      </select>
      {forms.length === 0 && <p className="mt-1 text-xs text-muted">No hay ATS en borrador. Crea uno con «Nuevo» en la lista.</p>}
      {src && (
        <div className="mt-3 space-y-3">
          <div role="tablist" className="flex flex-wrap gap-2">
            {TABS.map(([k, l]) => (
              <button key={k} role="tab" aria-selected={tab === k} type="button" onClick={() => { setTab(k); setPick(""); }}
                className={`rounded-full px-3 py-1 text-sm font-semibold ${tab === k ? "bg-brand-soft text-brand-strong" : "bg-bg text-muted"}`}>{l}</button>
            ))}
          </div>
          {tab === "pets" && (src.pets.length === 0 ? <p className="text-sm text-muted">No hay PETS vigentes con pasos.</p> : (
            <select className="input" aria-label="PETS" value={pick} onChange={(e) => setPick(e.target.value)}>
              <option value="">Elige el PETS…</option>
              {src.pets.map((p) => <option key={p.id} value={p.id}>{[p.code, p.title].filter(Boolean).join(" · ")} (v{p.version_number}, {p.steps} pasos)</option>)}
            </select>
          ))}
          {tab === "ats" && (src.ats.length === 0 ? <p className="text-sm text-muted">No hay ATS anteriores con pasos.</p> : (
            <select className="input" aria-label="ATS anterior" value={pick} onChange={(e) => setPick(e.target.value)}>
              <option value="">Elige el ATS…</option>
              {src.ats.map((a) => <option key={a.id} value={a.id}>{[a.code, a.task].filter(Boolean).join(" · ")}{a.performed_on ? ` (${a.performed_on.split("-").reverse().join("/")})` : ""} · {a.steps} pasos</option>)}
            </select>
          ))}
          {tab === "iperc" && (src.iperc.length === 0 ? <p className="text-sm text-muted">No hay filas del IPERC vigente para el área del ATS.</p> : (
            <ul className="max-h-72 space-y-1 overflow-y-auto rounded-lg border border-line p-2 text-sm">
              {src.iperc.map((r) => (
                <li key={r.id}>
                  <label className="flex items-start gap-2">
                    <input type="checkbox" className="mt-1" checked={rows.has(r.id)} onChange={() => setRows((s) => { const n = new Set(s); if (n.has(r.id)) n.delete(r.id); else n.add(r.id); return n; })} />
                    <span><b>{r.task ?? r.activity}</b> — {r.hazard} → {r.risk}
                      <span className="block text-xs text-muted">{[r.matrix, r.controls ? `Controles: ${r.controls}` : null].filter(Boolean).join(" · ")}</span></span>
                  </label>
                </li>
              ))}
            </ul>
          ))}
          <Button variant="primary" icon="file" loading={busy} disabled={busy || !can} onClick={copy}>Copiar pasos</Button>
        </div>
      )}
      {msg && <p role={msg.ok ? "status" : "alert"} className={`mt-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </Card>
  );
}
