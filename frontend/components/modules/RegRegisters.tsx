"use client";

// Registros obligatorios (M-REG, SCR-REG-01/02/03/04; prototipo m-personas.js R.REG): «1. Elige el registro», «2. Elige
// el periodo» y la sede, la validación previa de datos faltantes (RN-REG-02) y la vista previa. «Generar y pedir firma»
// crea una versión con sus datos congelados y huella (RN-REG-03) y pide la firma del responsable y, si corresponde, del
// empleador (RN-REG-05). Debajo, el historial de versiones con su estado de firma, PDF y Excel.

import { useEffect, useMemo, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/records";
import { MedReauth, useMedicalKey } from "./MedicalGate";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";

type Preview = {
  type: string;
  title: string;
  company: { legal_name: string; ruc: string; address: string | null; activity: string | null; workers: number };
  period: { from: string; to: string };
  header: string[];
  rows: unknown[][];
  total: number;
};

const REGISTERS: [string, string][] = [
  ["accidents", "Accidentes de trabajo"],
  ["occupational_diseases", "Enfermedades ocupacionales"],
  ["incidents", "Incidentes peligrosos e incidentes"],
  ["emo", "Exámenes médicos ocupacionales"],
  ["monitoring", "Monitoreo de agentes físicos, químicos, biológicos, psicosociales y disergonómicos"],
  ["inspections", "Inspecciones internas de seguridad y salud en el trabajo"],
  ["statistics", "Estadísticas de seguridad y salud en el trabajo"],
  ["emergency_equipment", "Equipos de seguridad o emergencia"],
  ["training", "Inducción, capacitación, entrenamiento y simulacros de emergencia"],
  ["audits", "Auditorías"],
];

type Missing = { id: string; code: string | null; missing: string[]; link: string };
type VersionRow = { id: string; register_type: string; period_from: string; period_to: string; status: string; data_hash: string; rows_count: number;
  generated_at: string; site: string | null; generated_by: string | null; signed: number; signers: number };
type Panel = { sites: { id: string; name: string }[]; signers: { id: string; full_name: string }[]; members: { id: string; full_name: string }[]; versions: VersionRow[]; medical?: boolean };
const VSTATUS: Record<string, [string, string]> = {
  generated: ["Generado", "bg-bg text-muted"],
  pending_signature: ["Pendiente de firma", "bg-amber-soft text-amber-ink"],
  signed: ["Firmado", "bg-ok-soft text-ok"],
  superseded: ["Reemplazado", "bg-bg text-muted line-through"],
};

const MONTHS = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
const iso = (y: number, m: number, d: number) => `${y}-${String(m).padStart(2, "0")}-${String(d).padStart(2, "0")}`;
const lastDay = (y: number, m: number) => new Date(y, m, 0).getDate();
const dmy = (v: string) => v.split("-").reverse().join("/");

/** Periodos del selector (como el prototipo): mes actual y anterior, trimestre, año en curso y año anterior. */
function periods(): { key: string; label: string; from: string; to: string }[] {
  const now = new Date();
  const y = now.getFullYear();
  const m = now.getMonth() + 1;
  const today = iso(y, m, now.getDate());
  const pm = m === 1 ? 12 : m - 1;
  const py = m === 1 ? y - 1 : y;
  const q = Math.ceil(m / 3);
  const out = [
    { key: "month", label: `${y} – ${MONTHS[m - 1]}`, from: iso(y, m, 1), to: today },
    { key: "prev", label: `${py} – ${MONTHS[pm - 1]}`, from: iso(py, pm, 1), to: iso(py, pm, lastDay(py, pm)) },
    { key: "quarter", label: `${y} – ${["primer", "segundo", "tercer", "cuarto"][q - 1]} trimestre`, from: iso(y, q * 3 - 2, 1), to: today },
    { key: "year", label: `${y} – año completo`, from: iso(y, 1, 1), to: today },
    { key: "last", label: `${y - 1} – año completo`, from: iso(y - 1, 1, 1), to: iso(y - 1, 12, 31) },
  ];
  return out;
}

const cell = (v: unknown) => (v === null || v === undefined || v === "" ? "—" : typeof v === "number" ? v.toLocaleString("es-PE", { maximumFractionDigits: 2 }) : String(v));

export function RegRegisters({ readOnly }: { readOnly: boolean }) {
  const opts = useMemo(periods, []);
  const [type, setType] = useState("accidents");
  const [per, setPer] = useState(opts[3].key);
  const [prev, setPrev] = useState<Preview | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<"" | "pdf" | "xlsx">("");
  const [msg, setMsg] = useState<string | null>(null);
  const [history, setHistory] = useState(0);
  const [panel, setPanel] = useState<Panel | null>(null);
  const [site, setSite] = useState("");
  const [signer, setSigner] = useState("");
  const [employer, setEmployer] = useState("");
  const [missing, setMissing] = useState<Missing[]>([]);
  const [done, setDone] = useState<string | null>(null);
  const medKey = useMedicalKey();
  const [askKey, setAskKey] = useState(false);
  const period = opts.find((o) => o.key === per) ?? opts[3];
  const q = `from=${period.from}&to=${period.to}${site ? `&site_id=${site}` : ""}`;

  useEffect(() => {
    api<{ data: Panel }>("/reg/panel", { withCompany: true })
      .then((r) => {
        setPanel(r.data);
        setSigner((s) => s || (r.data.signers.length === 1 ? r.data.signers[0].id : ""));
      })
      .catch(() => setPanel(null));
  }, [history]);

  useEffect(() => {
    setLoading(true);
    setMsg(null);
    setDone(null);
    api<{ data: Preview }>(`/reg/${type}/preview?${q}&limit=10`, { withCompany: true })
      .then((r) => setPrev(r.data))
      .catch((e: ApiError) => {
        setPrev(null);
        setMsg(e.detail ?? e.title);
      })
      .finally(() => setLoading(false));
    api<{ data: { missing: Missing[] } }>(`/reg/${type}/check?${q}`, { withCompany: true })
      .then((r) => setMissing(r.data.missing))
      .catch(() => setMissing([]));
  }, [type, q]);

  // Genera la versión para firma; si faltan datos, el servidor la bloquea y devuelve los registros por completar.
  const generate = async () => {
    setBusy("pdf");
    setMsg(null);
    setDone(null);
    try {
      const r = await api<{ data: { id: string } }>(`/reg/${type}/generate`, {
        method: "POST",
        withCompany: true,
        body: { from: period.from, to: period.to, site_id: site || null, signer_user_id: signer || null, employer_user_id: employer || null },
      });
      setDone(r.data.id);
      setHistory((h) => h + 1);
    } catch (e) {
      const err = e as ApiError;
      const miss = err.extra?.missing as Missing[] | undefined;
      if (miss) setMissing(miss);
      setMsg(err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title));
    } finally {
      setBusy("");
    }
  };

  // RN-REG-04: versión completa del registro de EMO (con diagnósticos), solo el médico y con su contraseña confirmada.
  const fullEmo = async (key = medKey.key) => {
    if (!key) { setAskKey(true); return; }
    setMsg(null);
    try {
      await downloadFile(`/reg/emo/medical.xlsx?${q}`, `registro-emo-completo-${period.from}-${period.to}.xlsx`, { "X-Medical-Reauth": key.id });
    } catch (e) {
      const err = e as ApiError;
      if (err.type === "reauth_required") { medKey.clear(); setAskKey(true); } else setMsg(err.detail ?? err.title);
    }
  };

  const excel = async () => {
    setBusy("xlsx");
    setMsg(null);
    try {
      await downloadFile(`/reg/${type}.xlsx?${q}`, `registro-${type}-${period.from}-${period.to}.xlsx`);
      setHistory((h) => h + 1);
    } catch (e) {
      setMsg((e as ApiError).detail ?? (e as ApiError).title);
    } finally {
      setBusy("");
    }
  };

  return (
    <div className="space-y-6">
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        <Card>
          <h3 className="mb-2 font-display text-base font-semibold">1. Elige el registro</h3>
          <ul className="space-y-0.5" aria-label="Registro">
            {REGISTERS.map(([v, l], i) => (
              <li key={v}>
                <button
                  type="button"
                  aria-pressed={v === type}
                  onClick={() => setType(v)}
                  className={`flex w-full items-start gap-2 rounded-lg px-2 py-2 text-left text-sm ${v === type ? "bg-brand-soft font-semibold text-brand-strong" : "hover:bg-bg"}`}
                >
                  <span className="mt-0.5 inline-grid h-5 min-w-5 place-items-center rounded border border-line bg-surface px-1 text-[11px] font-semibold text-muted">{i + 1}</span>
                  <span className="flex-1">{l}</span>
                  {(() => {
                    const last = panel?.versions.find((x) => x.register_type === v && x.status !== "superseded");
                    return last ? <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${VSTATUS[last.status]?.[1] ?? ""}`}>{VSTATUS[last.status]?.[0]}</span> : null;
                  })()}
                </button>
              </li>
            ))}
          </ul>
          <h3 className="mb-2 mt-4 font-display text-base font-semibold">2. Elige el periodo</h3>
          <select className="input" aria-label="Periodo" value={per} onChange={(e) => setPer(e.target.value)}>
            {opts.map((o) => (
              <option key={o.key} value={o.key}>{o.label}</option>
            ))}
          </select>
          <p className="mt-1 text-xs text-muted">Del {dmy(period.from)} al {dmy(period.to)}</p>
          {panel && panel.sites.length > 1 && (
            <select className="input mt-2" aria-label="Sede" value={site} onChange={(e) => setSite(e.target.value)}>
              <option value="">Toda la empresa (consolidado)</option>
              {panel.sites.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
            </select>
          )}
          {missing.length > 0 && (
            <div role="alert" className="mt-3 rounded-lg border border-line bg-bad-soft p-3 text-sm">
              <p className="font-semibold text-bad">Faltan datos obligatorios: completa estos registros antes de generar.</p>
              <ul className="mt-1 space-y-1">
                {missing.map((m) => (
                  <li key={m.id}><a href={m.link} className="font-semibold text-brand-strong underline">{m.code ?? "Registro"}</a> · {m.missing.join(", ")}</li>
                ))}
              </ul>
            </div>
          )}
          {!readOnly && (
            <>
              <h3 className="mb-2 mt-4 font-display text-base font-semibold">3. Firmas</h3>
              <label className="block text-xs font-semibold text-muted" htmlFor="reg-signer">Responsable del registro</label>
              <select id="reg-signer" className="input" value={signer} onChange={(e) => setSigner(e.target.value)}>
                <option value="">Elige al responsable…</option>
                {panel?.signers.map((u) => <option key={u.id} value={u.id}>{u.full_name}</option>)}
              </select>
              <label className="mt-2 block text-xs font-semibold text-muted" htmlFor="reg-employer">Empleador (si el formato lo pide)</label>
              <select id="reg-employer" className="input" value={employer} onChange={(e) => setEmployer(e.target.value)}>
                <option value="">Sin firma del empleador</option>
                {panel?.members.filter((u) => u.id !== signer).map((u) => <option key={u.id} value={u.id}>{u.full_name}</option>)}
              </select>
              <div className="mt-3 flex flex-wrap gap-2">
                <Button variant="primary" icon="check" loading={busy === "pdf"} disabled={!!busy || missing.length > 0 || !signer} onClick={generate}>Generar y pedir firma</Button>
                <Button icon="download" loading={busy === "xlsx"} disabled={!!busy} onClick={excel}>Excel sin firma</Button>
                {type === "emo" && panel?.medical && <Button icon="download" onClick={() => fullEmo()}>Versión completa (médico)</Button>}
              </div>
              {askKey && (
                <div className="mt-3">
                  <MedReauth endpoint="/emo/reauth" who="el médico ocupacional" onCancel={() => setAskKey(false)}
                    onOk={(k) => { medKey.setKey(k); setAskKey(false); void fullEmo(k); }} />
                </div>
              )}
            </>
          )}
          {done && (
            <p role="status" className="mt-2 text-sm text-ok">
              Versión generada; se pidió la firma. <a href={`/imprimir/registro/${done}`} target="_blank" rel="noreferrer" className="font-semibold underline">Ver o imprimir</a>
            </p>
          )}
          {msg && <p role="alert" className="mt-2 text-sm text-bad">{msg}</p>}
        </Card>

        <div className="min-w-0 space-y-2">
          <h3 className="font-display text-base font-semibold">Vista previa</h3>
          <div className="overflow-x-auto rounded-xl border border-line bg-surface p-4 text-xs" tabIndex={0} role="region" aria-label="Vista previa del registro">
            {loading && !prev ? (
              <div className="h-48 animate-pulse rounded-lg bg-bg" />
            ) : !prev ? (
              <p className="text-muted">No se pudo armar la vista previa.</p>
            ) : (
              <div className={loading ? "opacity-60" : ""}>
                <p className="mb-2 text-center font-display text-sm font-bold">{prev.title.toUpperCase()}</p>
                <table className="w-full border-collapse">
                  <tbody>
                    <tr>
                      <th className="border border-line bg-bg px-2 py-1 text-left">Razón social</th>
                      <td className="border border-line px-2 py-1">{prev.company.legal_name}</td>
                      <th className="border border-line bg-bg px-2 py-1 text-left">RUC</th>
                      <td className="border border-line px-2 py-1">{prev.company.ruc}</td>
                    </tr>
                    <tr>
                      <th className="border border-line bg-bg px-2 py-1 text-left">Domicilio</th>
                      <td className="border border-line px-2 py-1">{prev.company.address ?? "—"}</td>
                      <th className="border border-line bg-bg px-2 py-1 text-left">N.° trabajadores</th>
                      <td className="border border-line px-2 py-1">{prev.company.workers}</td>
                    </tr>
                    <tr>
                      <th className="border border-line bg-bg px-2 py-1 text-left">Periodo</th>
                      <td className="border border-line px-2 py-1" colSpan={3}>{period.label} ({dmy(prev.period.from)} al {dmy(prev.period.to)})</td>
                    </tr>
                  </tbody>
                </table>
                <table className="mt-2 w-full border-collapse">
                  <thead>
                    <tr>{prev.header.map((h) => <th key={h} className="whitespace-nowrap border border-line bg-bg px-2 py-1 text-left">{h}</th>)}</tr>
                  </thead>
                  <tbody>
                    {prev.rows.length === 0 ? (
                      <tr><td colSpan={prev.header.length} className="border border-line px-2 py-3 text-center text-muted">Sin registros en el periodo.</td></tr>
                    ) : (
                      prev.rows.map((row, i) => (
                        <tr key={i}>{row.map((c, j) => <td key={j} className="max-w-[260px] border border-line px-2 py-1 align-top">{cell(c)}</td>)}</tr>
                      ))
                    )}
                  </tbody>
                </table>
                <p className="mt-2 text-[11px] text-muted">
                  {prev.total > prev.rows.length ? `Se muestran ${prev.rows.length} de ${prev.total} filas. ` : `${prev.total} fila(s). `}
                  Formato referencial R.M. 050-2013-TR. Los registros de salud solo muestran la aptitud.
                </p>
              </div>
            )}
          </div>
        </div>
      </div>

      <div className="space-y-2">
        <h3 className="font-display text-base font-semibold">Historial de versiones</h3>
        <div className="overflow-x-auto rounded-xl border border-line bg-surface" tabIndex={0} role="region" aria-label="Historial de versiones">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line text-xs font-semibold text-muted">
              <tr><th className="px-3 py-2">Registro</th><th className="px-3 py-2">Periodo</th><th className="px-3 py-2">Sede</th><th className="px-3 py-2">Filas</th>
                <th className="px-3 py-2">Estado</th><th className="px-3 py-2">Generado</th><th className="px-3 py-2">Huella</th><th className="px-3 py-2"><span className="sr-only">Descargas</span></th></tr>
            </thead>
            <tbody>
              {!panel ? (
                <tr><td colSpan={8} className="px-3 py-4 text-muted">Cargando…</td></tr>
              ) : panel.versions.length === 0 ? (
                <tr><td colSpan={8} className="px-3 py-4 text-muted">Aún no se generan versiones para firma.</td></tr>
              ) : (
                panel.versions.map((v) => {
                  const [label, cls] = VSTATUS[v.status] ?? [v.status, "bg-bg text-muted"];
                  return (
                    <tr key={v.id} className="border-b border-line last:border-0">
                      <td className="px-3 py-2">{REGISTERS.find(([k]) => k === v.register_type)?.[1] ?? v.register_type}</td>
                      <td className="whitespace-nowrap px-3 py-2">{dmy(v.period_from)} al {dmy(v.period_to)}</td>
                      <td className="px-3 py-2">{v.site ?? "Toda la empresa"}</td>
                      <td className="px-3 py-2">{v.rows_count}</td>
                      <td className="px-3 py-2"><span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${cls}`}>{label}</span>
                        {v.signers > 0 && <span className="block text-xs text-muted">{v.signed} de {v.signers} firma(s)</span>}</td>
                      <td className="px-3 py-2 text-xs">{new Date(v.generated_at).toLocaleString("es-PE")}{v.generated_by ? <span className="block text-muted">{v.generated_by}</span> : null}</td>
                      <td className="px-3 py-2 font-mono text-xs" title={v.data_hash}>{v.data_hash.slice(0, 12)}…</td>
                      <td className="whitespace-nowrap px-3 py-2 text-xs">
                        <a href={`/imprimir/registro/${v.id}`} target="_blank" rel="noreferrer" className="font-semibold text-brand-strong underline">PDF</a>{" · "}
                        <button type="button" className="font-semibold text-brand-strong underline" onClick={() => downloadFile(`/reg/exports/${v.id}.xlsx`, `registro-${v.register_type}-${v.period_from}-${v.period_to}.xlsx`)}>Excel</button>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
        <p className="text-xs text-muted">Cada firmante firma desde sus firmas pendientes del inicio.</p>
      </div>
    </div>
  );
}
