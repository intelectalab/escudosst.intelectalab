"use client";

// SCR-SUS-06 / SCR-SUS-11 · Módulos opcionales por empresa (docs/03 §7.1, RN-SUS-07/08, M-AUL RN-AUL-20/21): interruptores del aula virtual
// (AUL) y de la venta de cursos (VTA) de la empresa activa. Vive en «Mi suscripción» para que se pueda volver a activar el aula aunque esté
// desactivada (con el aula apagada, /m/aul no está disponible). La venta necesita el aula activa; el aula no se apaga mientras la venta esté activa.
// Solo lo ve quien administra la empresa (ten.companies.update): para el resto la consulta falla y no se muestra nada.

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { errorText } from "./Wizard";

type Flag = { mode: string; company_enabled: boolean; in_plan: boolean };
type State = { aul: Flag; vta: Flag };

const CASES: { label: string; aul: boolean; vta: boolean; help: string }[] = [
  { label: "No usa aula", aul: false, vta: false, help: "Capacita solo de forma presencial." },
  { label: "Capacita solo a su personal", aul: true, vta: false, help: "Cursos del aula para trabajadores propios y de contratistas." },
  { label: "Vende cursos", aul: true, vta: true, help: "Además: catálogo público, pedidos, cupos para empresas y participantes externos." },
];

export function CompanyModuleSwitches({ title, withCases = false }: { title?: string; withCases?: boolean }) {
  const [st, setSt] = useState<State | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    api<{ data: State }>("/aul/settings", { withCompany: true }).then((r) => setSt(r.data)).catch(() => setSt(null));
  }, []);
  if (!st || (!st.aul.in_plan && !st.vta.in_plan && !withCases)) return null;
  const send = (body: { aul?: boolean; vta?: boolean }) => {
    setMsg(null);
    setBusy(true);
    return api<{ data: State }>("/aul/settings", { method: "PUT", withCompany: true, body })
      .then((r) => { setSt(r.data); setMsg({ ok: true, text: "Cambio guardado. El menú se actualiza al volver a cargar la página." }); })
      .catch((e) => setMsg({ ok: false, text: errorText(e) }))
      .finally(() => setBusy(false));
  };
  // Un caso guiado se aplica en el orden que permite la regla: primero se apaga la venta, después el aula (y al revés al encender).
  const apply = async (aul: boolean, vta: boolean) => {
    if (!vta && st.vta.company_enabled) await send({ vta: false });
    await send(vta ? { aul, vta } : { aul });
  };
  const on = (f: Flag) => f.company_enabled && f.in_plan;
  const row = (label: string, f: Flag, key: "aul" | "vta", help: string) => (
    <label className="flex items-start justify-between gap-3 py-2">
      <span><b className="block text-fg">{label}</b><span className="text-xs">{f.in_plan ? help : "No está incluido en el plan de la empresa."}</span></span>
      <input type="checkbox" className="mt-1 h-5 w-5" disabled={!f.in_plan || busy} checked={on(f)} onChange={(e) => send({ [key]: e.target.checked })} />
    </label>
  );
  return (
    <section className="space-y-1 rounded-xl border border-line bg-surface px-4 py-2 text-sm text-muted">
      {title && <p className="pt-1 font-semibold text-fg">{title}</p>}
      {withCases && st.aul.in_plan && (
        <div className="flex flex-wrap gap-2 py-2">
          {CASES.filter((c) => !c.vta || st.vta.in_plan).map((c) => {
            const current = on(st.aul) === c.aul && on(st.vta) === c.vta;
            return (
              <button key={c.label} type="button" disabled={busy || current} title={c.help} onClick={() => apply(c.aul, c.vta)}
                className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${current ? "border-brand bg-brand-soft text-brand" : "border-line text-fg hover:border-brand"}`}>
                {c.label}{current ? " · actual" : ""}
              </button>
            );
          })}
        </div>
      )}
      <div className="divide-y divide-line">
        {row("Aula virtual activa", st.aul, "aul", "Si la desactivas, los cursos quedan en solo lectura y los certificados emitidos siguen verificables.")}
        {row("Venta de cursos activa", st.vta, "vta", "Necesita el aula virtual activa.")}
      </div>
      {msg && <p role="status" className={`py-2 ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </section>
  );
}
