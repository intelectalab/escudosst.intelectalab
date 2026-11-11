"use client";

// Registros obligatorios (M-REG, SCR-REG-01/02/04; prototipo m-personas.js R.REG): «1. Elige el registro», «2. Elige
// el periodo», descarga en PDF (impresión) o Excel, y la vista previa con la cabecera de la empresa y las primeras filas
// del mismo armado que el Excel. Debajo, el historial de descargas (register_exports).

import { useEffect, useMemo, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/records";
import { ModuleRecords } from "@/components/records/ModuleRecords";
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
const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]!);

export function RegRegisters({ readOnly }: { readOnly: boolean }) {
  const opts = useMemo(periods, []);
  const [type, setType] = useState("accidents");
  const [per, setPer] = useState(opts[3].key);
  const [prev, setPrev] = useState<Preview | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<"" | "pdf" | "xlsx">("");
  const [msg, setMsg] = useState<string | null>(null);
  const [history, setHistory] = useState(0);
  const period = opts.find((o) => o.key === per) ?? opts[3];

  useEffect(() => {
    setLoading(true);
    setMsg(null);
    api<{ data: Preview }>(`/reg/${type}/preview?from=${period.from}&to=${period.to}&limit=10`, { withCompany: true })
      .then((r) => setPrev(r.data))
      .catch((e: ApiError) => {
        setPrev(null);
        setMsg(e.detail ?? e.title);
      })
      .finally(() => setLoading(false));
  }, [type, period.from, period.to]);

  const excel = async () => {
    setBusy("xlsx");
    setMsg(null);
    try {
      await downloadFile(`/reg/${type}.xlsx?from=${period.from}&to=${period.to}`, `registro-${type}-${period.from}-${period.to}.xlsx`);
      setHistory((h) => h + 1);
    } catch (e) {
      setMsg((e as ApiError).detail ?? (e as ApiError).title);
    } finally {
      setBusy("");
    }
  };

  // PDF: hoja completa en el formato referencial, lista para «Guardar como PDF» desde el diálogo de impresión.
  const pdf = async () => {
    setBusy("pdf");
    setMsg(null);
    const w = window.open("", "_blank");
    try {
      const r = (await api<{ data: Preview }>(`/reg/${type}/preview?from=${period.from}&to=${period.to}&limit=500`, { withCompany: true })).data;
      if (!w) throw new Error("Permite las ventanas emergentes para imprimir el registro.");
      const head = `<tr>${r.header.map((h) => `<th>${esc(h)}</th>`).join("")}</tr>`;
      const body = r.rows.length
        ? r.rows.map((row) => `<tr>${row.map((c) => `<td>${esc(cell(c))}</td>`).join("")}</tr>`).join("")
        : `<tr><td colspan="${r.header.length}">Sin registros en el periodo.</td></tr>`;
      w.document.write(`<!doctype html><html lang="es"><head><meta charset="utf-8"><title>${esc(r.title)}</title><style>
        body{font-family:Arial,sans-serif;font-size:10px;margin:16px;color:#111}h1{font-size:13px;text-align:center;margin:0 0 8px}
        table{border-collapse:collapse;width:100%;margin-top:8px}th,td{border:1px solid #555;padding:3px 4px;text-align:left;vertical-align:top}th{background:#eee}
        @page{size:A4 landscape;margin:10mm}</style></head><body>
        <h1>${esc(r.title.toUpperCase())}</h1>
        <table><tr><th>Razón social</th><td>${esc(r.company.legal_name)}</td><th>RUC</th><td>${esc(r.company.ruc)}</td></tr>
        <tr><th>Domicilio</th><td>${esc(r.company.address ?? "—")}</td><th>N.° trabajadores</th><td>${r.company.workers}</td></tr>
        <tr><th>Periodo</th><td colspan="3">${dmy(r.period.from)} al ${dmy(r.period.to)}</td></tr></table>
        <table>${head}${body}</table>
        <table><tr><th>Responsable del registro</th><td style="height:28px"></td><th>Firma</th><td></td></tr></table>
        <p>Formato referencial R.M. 050-2013-TR. ${r.total > r.rows.length ? `Se muestran ${r.rows.length} de ${r.total} filas; descarga el Excel para el registro completo.` : ""}</p>
        </body></html>`);
      w.document.close();
      w.focus();
      w.print();
    } catch (e) {
      w?.close();
      setMsg((e as ApiError).detail ?? (e as Error).message ?? (e as ApiError).title);
    } finally {
      setBusy("");
    }
  };

  return (
    <div className="space-y-6">
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        <Card>
          <h3 className="mb-2 font-display text-base font-semibold">1. Elige el registro</h3>
          <ul className="space-y-0.5" role="listbox" aria-label="Registro">
            {REGISTERS.map(([v, l], i) => (
              <li key={v}>
                <button
                  type="button"
                  role="option"
                  aria-selected={v === type}
                  onClick={() => setType(v)}
                  className={`flex w-full items-start gap-2 rounded-lg px-2 py-2 text-left text-sm ${v === type ? "bg-brand-soft font-semibold text-brand-strong" : "hover:bg-bg"}`}
                >
                  <span className="mt-0.5 inline-grid h-5 min-w-5 place-items-center rounded border border-line bg-surface px-1 text-[11px] font-semibold text-muted">{i + 1}</span>
                  <span>{l}</span>
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
          {!readOnly && (
            <div className="mt-3 flex flex-wrap gap-2">
              <Button variant="primary" icon="download" loading={busy === "pdf"} disabled={!!busy} onClick={pdf}>PDF</Button>
              <Button icon="download" loading={busy === "xlsx"} disabled={!!busy} onClick={excel}>Excel</Button>
            </div>
          )}
          {msg && <p role="alert" className="mt-2 text-sm text-bad">{msg}</p>}
        </Card>

        <div className="min-w-0 space-y-2">
          <h3 className="font-display text-base font-semibold">Vista previa</h3>
          <div className="overflow-x-auto rounded-xl border border-line bg-surface p-4 text-xs">
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
        <h3 className="font-display text-base font-semibold">Historial</h3>
        <ModuleRecords key={history} code="REG" readOnly={readOnly} />
      </div>
    </div>
  );
}
