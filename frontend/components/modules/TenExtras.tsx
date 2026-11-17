"use client";

// Empresas, sedes y áreas (M-TEN): propuesta del modelo SST con confirmación (RN-TEN-03), empresas de la cuenta (SCR-TEN-01),
// estructura en Excel y organigrama SST en PDF.

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { ReportButton } from "./CtrExtras";

type Model = { model: string | null; workers: number; threshold: number; suggested: string };
type Co = { id: string; ruc: string; legal_name: string; sst_model: string | null; status: string; plan: string | null; workers: number };
const errText = (e: unknown) => (e instanceof ApiError ? Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title : "No se pudo completar.");
const MODEL: Record<string, string> = { committee: "Comité de SST", supervisor: "Supervisor de SST" };

export function TenPanel({ readOnly, isAccountAdmin }: { readOnly: boolean; isAccountAdmin: boolean }) {
  const [m, setM] = useState<Model | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  useEffect(() => { api<{ data: Model }>("/ten/sst-model", { withCompany: true }).then((r) => setM(r.data)).catch(() => setM(null)); }, []);
  const confirm = () => {
    if (!m || !window.confirm(`¿Cambiar el modelo a ${MODEL[m.suggested]}? Queda registrado a tu nombre.`)) return;
    api<{ data: Model }>("/ten/sst-model", { method: "POST", withCompany: true, body: { model: m.suggested, reason: `Trabajadores activos: ${m.workers}` } })
      .then((r) => { setM(r.data); setMsg({ ok: true, text: "Modelo SST actualizado." }); }).catch((e) => setMsg({ ok: false, text: errText(e) }));
  };
  const today = new Date().toISOString().slice(0, 10);
  return (
    <div className="space-y-3">
      <section className="space-y-2 rounded-xl border border-line bg-surface p-4 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="font-semibold">Modelo SST: {m?.model ? MODEL[m.model] : "—"}</p>
          <span className="flex flex-wrap gap-2">
            <ReportButton path="/ten/structure.xlsx" file={`estructura-${today}.xlsx`} label="Estructura en Excel" />
            <a href="/imprimir/organigrama-sst" target="_blank" rel="noreferrer" className="rounded-lg border border-line bg-surface px-3 py-1.5 text-sm font-semibold hover:border-brand">Organigrama SST en PDF</a>
          </span>
        </div>
        {m && <p className="text-muted">{m.workers} trabajador(es) activo(s). Desde {m.threshold} corresponde Comité de SST; con menos, Supervisor de SST.</p>}
        {m && m.model !== m.suggested && (
          <div className="flex flex-wrap items-center gap-2 rounded-lg bg-amber-soft px-3 py-2 text-amber-ink">
            <span>Por el número de trabajadores corresponde <b>{MODEL[m.suggested]}</b>. El cambio no es automático: confírmalo.</span>
            {!readOnly && <button type="button" className="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white" onClick={confirm}>Confirmar el cambio</button>}
          </div>
        )}
        {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
      </section>
      {isAccountAdmin && <AccountCompanies readOnly={readOnly} />}
    </div>
  );
}

function AccountCompanies({ readOnly }: { readOnly: boolean }) {
  const [rows, setRows] = useState<Co[] | null>(null);
  const [meta, setMeta] = useState<{ limit: number | null; active: number }>({ limit: null, active: 0 });
  const [f, setF] = useState({ ruc: "", legal_name: "", trade_name: "", headcount: "" });
  const [open, setOpen] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const load = () => api<{ data: Co[]; limit: number | null; active: number }>("/ten/account-companies", { withCompany: true })
    .then((r) => { setRows(r.data); setMeta({ limit: r.limit, active: r.active }); }).catch(() => setRows(null));
  useEffect(() => { void load(); }, []);
  if (!rows) return null;
  const create = () => api("/ten/account-companies", { method: "POST", withCompany: true, body: { ...f, headcount: Number(f.headcount) } })
    .then(() => { setOpen(false); setF({ ruc: "", legal_name: "", trade_name: "", headcount: "" }); setMsg({ ok: true, text: "Empresa creada. Entra a ella desde el selector de empresa." }); void load(); })
    .catch((e) => setMsg({ ok: false, text: errText(e) }));
  return (
    <section className="space-y-2 rounded-xl border border-line bg-surface p-4 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-semibold">Empresas de la cuenta {meta.limit ? `(${meta.active} de ${meta.limit} que permite el plan)` : ""}</p>
        {!readOnly && <button type="button" className="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white" onClick={() => setOpen(!open)}>Nueva empresa</button>}
      </div>
      {open && (
        <div className="flex flex-wrap items-end gap-2 rounded-lg bg-bg p-3">
          {(["ruc", "legal_name", "trade_name", "headcount"] as const).map((k) => (
            <label key={k} className="text-xs">{{ ruc: "RUC", legal_name: "Razón social", trade_name: "Nombre comercial", headcount: "Trabajadores" }[k]}
              <input className="input mt-1 !w-48" value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })} /></label>
          ))}
          <button type="button" className="rounded-lg bg-brand px-3 py-2 font-semibold text-white" onClick={create}>Crear</button>
        </div>
      )}
      <table className="w-full text-left">
        <thead className="text-xs text-muted"><tr><th className="py-1">RUC</th><th>Razón social</th><th>Trabajadores</th><th>Modelo SST</th><th>Plan</th><th>Estado</th></tr></thead>
        <tbody>{rows.map((c) => (
          <tr key={c.id} className="border-t border-line"><td className="py-1.5 font-mono">{c.ruc}</td><td>{c.legal_name}</td><td>{c.workers}</td><td>{c.sst_model ? MODEL[c.sst_model] : "—"}</td><td>{c.plan ?? "—"}</td><td>{c.status === "active" ? "Activa" : c.status}</td></tr>
        ))}</tbody>
      </table>
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </section>
  );
}
