"use client";

// Paneles de Personas:
//  - CAP: verificación pública de la empresa (RN-CAP-12), control de vencimientos con reentrenamiento masivo (SCR-CAP-09) y reporte en Excel.
//  - EMO: vencidos, por vencer y sin EMO por sede y puesto, con órdenes en lote (SCR-EMO-01), sin datos clínicos.
//  - VMD: indicadores agregados sin nombres; los grupos pequeños no se muestran (SCR-VMD-04).
//  - FOT: requisitos del fotocheck por puesto (SCR-FOT-01) e impresión por lote.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Card, Kpi } from "./Summary";

const err = (e: unknown) => { const x = e as ApiError; return x.errors ? Object.values(x.errors).flat()[0] : (x.detail ?? x.title); };
const fmt = (d: string | null) => (d ? d.slice(0, 10).split("-").reverse().join("/") : "—");

type Exp = { id: string; worker_id: string; full_name: string; position: string | null; area: string | null; site: string | null; training_id: string; training: string; expires_on: string; expired: boolean };

export function CapTools({ readOnly }: { readOnly: boolean }) {
  const [ver, setVer] = useState<{ enabled: boolean; trainings: { id: string; title: string }[]; page_text: string; rate_limit: number; page_url: string; can_edit: boolean } | null>(null);
  const [page, setPage] = useState({ page_text: "", rate_limit: "" });
  const [exp, setExp] = useState<{ days: number; rows: Exp[] } | null>(null);
  const [sel, setSel] = useState<Record<string, boolean>>({});
  const [form, setForm] = useState({ training_id: "", starts_at: "", ends_at: "" });
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const load = useCallback(() => {
    api<{ data: NonNullable<typeof ver> }>("/cap/verification", { withCompany: true }).then((r) => { setVer(r.data); setPage({ page_text: r.data.page_text ?? "", rate_limit: String(r.data.rate_limit ?? "") }); }).catch(() => setVer(null));
    api<{ data: { days: number; rows: Exp[] } }>("/cap/expirations", { withCompany: true }).then((r) => setExp(r.data)).catch(() => setExp(null));
  }, []);
  useEffect(() => { load(); }, [load]);
  const toggle = async () => {
    if (!ver) return;
    try { await api("/cap/verification", { method: "PUT", withCompany: true, body: { enabled: !ver.enabled } }); load(); } catch (e) { setMsg({ ok: false, text: err(e) }); }
  };
  const savePage = async () => {
    setMsg(null);
    try {
      await api("/cap/verification", { method: "PUT", withCompany: true, body: { page_text: page.page_text, rate_limit: Number(page.rate_limit) } });
      setMsg({ ok: true, text: "Se guardó la configuración de la página de verificación." });
      load();
    } catch (e) { setMsg({ ok: false, text: err(e) }); }
  };
  const chosen = (exp?.rows ?? []).filter((r) => sel[r.id] && (!form.training_id || r.training_id === form.training_id));
  const retrain = async () => {
    setMsg(null);
    try {
      const r = await api<{ data: { invited: number; errors: string[] } }>("/cap/expirations/retrain", { method: "POST", withCompany: true,
        body: { training_id: form.training_id, worker_ids: chosen.map((x) => x.worker_id), starts_at: new Date(form.starts_at).toISOString(), ends_at: new Date(form.ends_at).toISOString() } });
      setMsg({ ok: true, text: `Sesión programada con ${r.data.invited} trabajador(es) convocado(s).${r.data.errors.length ? " No se pudo convocar a: " + r.data.errors.join("; ") : ""}` });
      setSel({});
    } catch (e) { setMsg({ ok: false, text: err(e) }); }
  };
  const trainings = [...new Map((exp?.rows ?? []).map((r) => [r.training_id, r.training])).entries()];
  return (
    <div className="space-y-4">
      {msg && <p role={msg.ok ? "status" : "alert"} className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
      <Card title="Vencimientos de certificados" action={<div className="flex flex-wrap gap-2"><Button size="sm" icon="download" onClick={() => downloadFile("/cap/reports.xlsx", "reporte-capacitaciones.xlsx")}>Reporte en Excel</Button><Button size="sm" icon="file" onClick={() => window.open("/imprimir/reporte-capacitacion", "_blank")}>Reporte en PDF</Button></div>}>
        {!exp || exp.rows.length === 0 ? <p className="text-sm text-muted">No hay certificados vencidos ni por vencer en los próximos {exp?.days ?? 30} días.</p> : (
          <>
            <table className="w-full text-left text-sm">
              <thead className="border-b border-line text-xs text-muted"><tr><th /><th className="py-1">Trabajador</th><th>Tema</th><th>Puesto · área</th><th>Vence</th></tr></thead>
              <tbody>{exp.rows.map((r) => (
                <tr key={r.id} className="border-b border-line last:border-0">
                  <td><input type="checkbox" aria-label={`Elegir a ${r.full_name}`} checked={!!sel[r.id]} onChange={(e) => setSel({ ...sel, [r.id]: e.target.checked })} /></td>
                  <td className="py-1.5">{r.full_name}</td><td>{r.training}</td><td>{[r.position, r.area].filter(Boolean).join(" · ") || "—"}</td>
                  <td className={r.expired ? "font-semibold text-bad" : "text-amber-ink"}>{fmt(r.expires_on)}{r.expired ? " (vencido)" : ""}</td>
                </tr>
              ))}</tbody>
            </table>
            {!readOnly && (
              <div className="mt-3 grid gap-2 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end">
                <label className="text-xs text-muted">Reentrenar en<select className="input mt-1" value={form.training_id} onChange={(e) => setForm({ ...form, training_id: e.target.value })}>
                  <option value="">Elige el tema…</option>{trainings.map(([id, t]) => <option key={id} value={id}>{t}</option>)}</select></label>
                <label className="text-xs text-muted">Inicio<input type="datetime-local" className="input mt-1" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} /></label>
                <label className="text-xs text-muted">Fin<input type="datetime-local" className="input mt-1" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} /></label>
                <Button variant="primary" disabled={!form.training_id || !form.starts_at || !form.ends_at || chosen.length === 0} onClick={retrain}>Programar reentrenamiento ({chosen.length})</Button>
              </div>
            )}
          </>
        )}
      </Card>
      {ver && (
        <Card title="Verificación pública de certificados">
          <p className="text-sm">{ver.enabled ? "Activada: los certificados de los temas marcados como verificables se pueden consultar con su código o QR." : "Desactivada: nadie fuera de la empresa puede verificar los certificados."}</p>
          <p className="mt-1 text-xs text-muted">Temas verificables: {ver.trainings.length ? ver.trainings.map((t) => t.title).join(", ") : "ninguno (márcalos en cada capacitación)"}.</p>
          {ver.can_edit && !readOnly && <div className="mt-2"><Button size="sm" onClick={toggle}>{ver.enabled ? "Desactivar" : "Activar"} la verificación pública</Button></div>}
          <p className="mt-2 text-xs text-muted">Página pública de la empresa: <a className="text-brand underline" href={ver.page_url} target="_blank" rel="noreferrer">{ver.page_url}</a></p>
          <div className="mt-3 grid gap-2 sm:grid-cols-[1fr_12rem]">
            <label className="text-xs text-muted">Texto de la página
              <textarea className="input mt-1 min-h-20" maxLength={2000} disabled={!ver.can_edit || readOnly} value={page.page_text}
                placeholder="Por ejemplo: «Consulta aquí la validez de los certificados emitidos por nuestra empresa.»" onChange={(e) => setPage({ ...page, page_text: e.target.value })} /></label>
            <label className="text-xs text-muted">Límite de consultas por minuto (por IP)
              <input type="number" min={1} max={600} className="input mt-1" disabled={!ver.can_edit || readOnly} value={page.rate_limit} onChange={(e) => setPage({ ...page, rate_limit: e.target.value })} /></label>
          </div>
          {ver.can_edit && !readOnly && <div className="mt-2"><Button size="sm" disabled={!page.rate_limit} onClick={savePage}>Guardar texto y límite</Button></div>}
        </Card>
      )}
    </div>
  );
}

type Due = { id: string; full_name: string; site: string | null; position: string | null; valid_until: string | null; state: "none" | "expired" | "expiring"; scheduled_at: string | null };
type Grp = { name: string; expired: number; expiring: number; none: number };
const DSTATE: Record<Due["state"], [string, string]> = { none: ["Sin EMO", "text-bad"], expired: ["Vencido", "text-bad"], expiring: ["Por vencer", "text-amber-ink"] };

export function EmoDue({ readOnly }: { readOnly: boolean }) {
  const [d, setD] = useState<{ days: number; workers: Due[]; by_site: Grp[]; by_position: Grp[] } | null>(null);
  const [sel, setSel] = useState<Record<string, boolean>>({});
  const [f, setF] = useState({ exam_type: "periodic", scheduled_at: "", clinic_name: "" });
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const load = useCallback(() => api<{ data: typeof d }>("/emo/due", { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)), []);
  useEffect(() => { void load(); }, [load]);
  if (!d) return null;
  const ids = d.workers.filter((w) => sel[w.id]).map((w) => w.id);
  const bulk = async () => {
    setMsg(null);
    try {
      const r = await api<{ data: { created: number; skipped: string[] } }>("/emo/orders/bulk", { method: "POST", withCompany: true,
        body: { worker_ids: ids, exam_type: f.exam_type, scheduled_at: new Date(f.scheduled_at).toISOString(), clinic_name: f.clinic_name || null } });
      setMsg({ ok: true, text: `Se programaron ${r.data.created} orden(es).${r.data.skipped.length ? " " + r.data.skipped.join("; ") : ""}` });
      setSel({});
      void load();
    } catch (e) { setMsg({ ok: false, text: err(e) }); }
  };
  return (
    <Card title="EMO vencidos, por vencer y sin examen">
      {msg && <p role={msg.ok ? "status" : "alert"} className={`mb-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
      {d.workers.length === 0 ? <p className="text-sm text-muted">Todos los trabajadores activos tienen aptitud vigente por más de {d.days} días.</p> : (
        <>
          <div className="mb-3 grid gap-3 text-sm sm:grid-cols-2">
            {([["Por sede", d.by_site], ["Por puesto", d.by_position]] as const).map(([t, g]) => (
              <div key={t}><p className="mb-1 text-xs font-semibold uppercase text-muted">{t}</p>
                <ul>{g.map((x) => <li key={x.name}>{x.name}: {x.expired} vencidos · {x.expiring} por vencer · {x.none} sin EMO</li>)}</ul></div>
            ))}
          </div>
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line text-xs text-muted"><tr><th /><th className="py-1">Trabajador</th><th>Sede</th><th>Puesto</th><th>Estado</th><th>Cita</th></tr></thead>
            <tbody>{d.workers.map((w) => (
              <tr key={w.id} className="border-b border-line last:border-0">
                <td>{!w.scheduled_at && <input type="checkbox" aria-label={`Elegir a ${w.full_name}`} checked={!!sel[w.id]} onChange={(e) => setSel({ ...sel, [w.id]: e.target.checked })} />}</td>
                <td className="py-1.5">{w.full_name}</td><td>{w.site ?? "—"}</td><td>{w.position ?? "—"}</td>
                <td className={DSTATE[w.state][1]}>{DSTATE[w.state][0]}{w.valid_until ? ` · ${fmt(w.valid_until)}` : ""}</td><td>{w.scheduled_at ? fmt(w.scheduled_at) : "—"}</td>
              </tr>
            ))}</tbody>
          </table>
          {!readOnly && (
            <div className="mt-3 grid gap-2 sm:grid-cols-[auto_auto_1fr_auto] sm:items-end">
              <label className="text-xs text-muted">Tipo<select className="input mt-1" value={f.exam_type} onChange={(e) => setF({ ...f, exam_type: e.target.value })}>
                <option value="periodic">Periódico</option><option value="pre_employment">Preocupacional</option><option value="return_to_work">Reincorporación</option><option value="position_change">Cambio de puesto</option></select></label>
              <label className="text-xs text-muted">Fecha de la cita<input type="datetime-local" className="input mt-1" value={f.scheduled_at} onChange={(e) => setF({ ...f, scheduled_at: e.target.value })} /></label>
              <label className="text-xs text-muted">Clínica<input className="input mt-1" value={f.clinic_name} onChange={(e) => setF({ ...f, clinic_name: e.target.value })} /></label>
              <Button variant="primary" disabled={!ids.length || !f.scheduled_at} onClick={bulk}>Programar ({ids.length})</Button>
            </div>
          )}
        </>
      )}
    </Card>
  );
}

type IGrp = { name: string; cases: number; open: number; overdue: number; high: number };
type Ind = { min_group: number; total: number | null; by_program: { groups: IGrp[]; hidden: number }; by_area: { groups: IGrp[]; hidden: number } };

export function VmdIndicators() {
  const [d, setD] = useState<Ind | null>(null);
  useEffect(() => { api<{ data: Ind }>("/vmd/indicators", { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)); }, []);
  if (!d) return null;
  const table = (t: string, g: { groups: IGrp[]; hidden: number }) => (
    <div><p className="mb-1 text-xs font-semibold uppercase text-muted">{t}</p>
      {g.groups.length === 0 ? <p className="text-sm text-muted">Sin grupos con {d.min_group} o más casos.</p> : (
        <table className="w-full text-left text-sm"><thead className="text-xs text-muted"><tr><th>Grupo</th><th>Casos</th><th>Abiertos</th><th>Control vencido</th><th>Riesgo alto</th></tr></thead>
          <tbody>{g.groups.map((x) => <tr key={x.name}><td>{x.name}</td><td>{x.cases}</td><td>{x.open}</td><td>{x.overdue}</td><td>{x.high}</td></tr>)}</tbody></table>
      )}
      {g.hidden > 0 && <p className="mt-1 text-xs text-muted">{g.hidden} grupo(s) con menos de {d.min_group} casos no se muestran para proteger la identidad.</p>}
    </div>
  );
  return (
    <Card title="Vigilancia médica (indicadores agregados)" action={<div className="flex flex-wrap items-center gap-3"><a href="/imprimir/vigilancia-medica" target="_blank" rel="noreferrer" className="text-sm font-semibold text-brand-strong underline">Informe para imprimir</a><Button size="sm" icon="download" onClick={() => downloadFile("/vmd/report.xlsx", "vigilancia-medica-agregado.xlsx")}>Excel</Button></div>}>
      <div className="mb-3"><Kpi label="Casos en seguimiento" value={d.total != null ? String(d.total) : `Menos de ${d.min_group}`} /></div>
      <div className="grid gap-4 lg:grid-cols-2">{table("Por programa", d.by_program)}{table("Por área", d.by_area)}</div>
      <p className="mt-2 text-xs text-muted">Sin nombres ni datos médicos: solo conteos.</p>
    </Card>
  );
}

type FReq = { type: string; description: string | null; blocking: boolean; grace_days: number | null };
type FMat = { types: { id: string; name: string }[]; type_id: string | null; general: FReq[]; positions: { id: string; name: string; workers: number; requirements: FReq[]; total: number }[] };

export function FotMatrix() {
  const [d, setD] = useState<FMat | null>(null);
  const load = useCallback((t?: string) => api<{ data: FMat }>(`/fot/matrix${t ? `?credential_type_id=${t}` : ""}`, { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)), []);
  useEffect(() => { void load(); }, [load]);
  if (!d || d.types.length === 0) return null;
  const r = (x: FReq) => `${x.description ?? x.type}${x.blocking ? "" : " (no bloquea)"}${x.grace_days ? ` · gracia ${x.grace_days} días` : ""}`;
  return (
    <Card title="Requisitos del fotocheck por puesto" action={d.type_id ? <a href={`/imprimir/fotochecks?type=${d.type_id}`} target="_blank" rel="noreferrer" className="text-sm font-semibold text-brand-strong underline">Imprimir lote</a> : undefined}>
      <select className="input mb-3 !w-auto" value={d.type_id ?? ""} onChange={(e) => void load(e.target.value)} aria-label="Tipo de fotocheck">{d.types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>
      <p className="mb-2 text-sm"><b>Para todos los puestos:</b> {d.general.length ? d.general.map(r).join(" · ") : "—"}</p>
      <table className="w-full text-left text-sm">
        <thead className="border-b border-line text-xs text-muted"><tr><th className="py-1">Puesto</th><th>Trabajadores</th><th>Requisitos propios del puesto</th><th>Total</th></tr></thead>
        <tbody>{d.positions.map((p) => <tr key={p.id} className="border-b border-line last:border-0"><td className="py-1.5">{p.name}</td><td>{p.workers}</td><td>{p.requirements.length ? p.requirements.map(r).join(" · ") : "—"}</td><td>{p.total}</td></tr>)}</tbody>
      </table>
    </Card>
  );
}
