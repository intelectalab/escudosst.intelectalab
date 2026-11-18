"use client";

// Versión generada de un registro obligatorio (M-REG, SCR-REG-04): hoja apaisada en el formato referencial R.M. 050-2013-TR
// con los datos del empleador al corte (RN-REG-08), las filas congeladas al generar, la huella de los datos (RN-REG-03) y
// las firmas obtenidas con FIR (RN-REG-05). Se guarda como PDF desde el diálogo de impresión del navegador.

import { BrandFooter, BrandLogo, cell, fmtD, fmtDT, ReportLoading, useBrand, useReport } from "./FieldReports";

type Sig = { role: string; status: string; signer_name: string; signed_at: string | null; method: string | null; image: string | null };
type Version = {
  id: string;
  register_type: string;
  period_from: string;
  period_to: string;
  status: "generated" | "pending_signature" | "signed" | "superseded";
  format_version: string;
  data_hash: string;
  rows_count: number;
  generated_at: string;
  generated_by: string | null;
  snapshot: {
    title: string;
    site: string | null;
    employer: { legal_name: string; ruc: string; address: string | null; activity: string | null; workers: number; as_of: string };
    header: string[];
    rows: unknown[][];
  };
  signatures: Sig[];
};

const ROLE: Record<string, string> = { responsible: "Responsable del registro", approver: "Empleador" };
const STATUS: Record<Version["status"], string | null> = {
  generated: "GENERADO · SIN FIRMAS",
  pending_signature: "PENDIENTE DE FIRMA",
  signed: null,
  superseded: "VERSIÓN REEMPLAZADA",
};
const val = (v: unknown) => (v === null || v === undefined || v === "" ? "—" : typeof v === "number" ? v.toLocaleString("es-PE", { maximumFractionDigits: 2 }) : String(v));

export function RegisterReport({ id }: { id: string }) {
  const { data: v, error } = useReport<Version>(`/reg/exports/${id}`);
  const brand = useBrand();
  if (!v) return <ReportLoading error={error} />;
  const e = v.snapshot.employer;
  const th = "border border-gray-500 bg-gray-100 px-1.5 py-1 text-left font-semibold";

  return (
    <div className="min-h-screen bg-gray-100 p-4 text-gray-900 print:bg-white print:p-0">
      <style>{`@page { size: A4 landscape; margin: 10mm } @media print { .no-print { display: none } } tr { break-inside: avoid }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-6xl flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow">
        <span className="text-sm text-gray-600">Para el PDF elige «Guardar como PDF» en el diálogo de impresión.</span>
        <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">Imprimir / Guardar PDF</button>
      </div>
      <div className="mx-auto max-w-6xl space-y-3 bg-white p-6 text-[11px] shadow print:max-w-none print:p-0 print:shadow-none">
        <header className="text-center">
          <BrandLogo brand={brand} className="mx-auto h-10 w-auto object-contain" />
          <h1 className="text-sm font-bold uppercase">{v.snapshot.title}</h1>
          {STATUS[v.status] && <p className="text-xs font-bold text-red-700">{STATUS[v.status]}</p>}
        </header>

        <table className="w-full border-collapse">
          <tbody>
            <tr>
              <th className={th}>Razón social o denominación social</th><td className={cell}>{e.legal_name}</td>
              <th className={th}>RUC</th><td className={cell}>{e.ruc}</td>
            </tr>
            <tr>
              <th className={th}>Domicilio</th><td className={cell}>{e.address ?? "—"}</td>
              <th className={th}>Actividad económica</th><td className={cell}>{e.activity ?? "—"}</td>
            </tr>
            <tr>
              <th className={th}>N.° de trabajadores al {fmtD(e.as_of)}</th><td className={cell}>{e.workers}</td>
              <th className={th}>Periodo{v.snapshot.site ? " · Sede" : ""}</th>
              <td className={cell}>{fmtD(v.period_from)} al {fmtD(v.period_to)}{v.snapshot.site ? ` · ${v.snapshot.site}` : " · Toda la empresa"}</td>
            </tr>
          </tbody>
        </table>

        <table className="w-full border-collapse">
          <thead>
            <tr>{v.snapshot.header.map((h) => <th key={h} className={th}>{h}</th>)}</tr>
          </thead>
          <tbody>
            {v.snapshot.rows.length === 0 ? (
              <tr><td className={cell} colSpan={v.snapshot.header.length}>Sin registros en el periodo.</td></tr>
            ) : (
              v.snapshot.rows.map((r, i) => <tr key={i}>{r.map((c, j) => <td key={j} className={cell}>{val(c)}</td>)}</tr>)
            )}
          </tbody>
        </table>

        <div className="grid grid-cols-2 gap-3 pt-2">
          {v.signatures.map((s, i) => (
            <div key={i} className="rounded border border-gray-400 p-2" style={{ breakInside: "avoid" }}>
              <p className="font-semibold">{ROLE[s.role] ?? s.role}</p>
              {s.image ? <img src={s.image} alt={`Firma de ${s.signer_name}`} className="h-12 object-contain" /> : <div className="h-12" />}
              <p className="border-t border-gray-400 pt-1 font-semibold">{s.signer_name}</p>
              <p className="text-gray-600">{s.status === "signed" ? `Firmado el ${fmtDT(s.signed_at)}` : s.status === "declined" ? "Firma rechazada" : "Pendiente de firma"}</p>
            </div>
          ))}
        </div>

        <p className="text-[10px] text-gray-600">
          Formato referencial R.M. 050-2013-TR ({v.format_version}). Generado el {fmtDT(v.generated_at)}{v.generated_by ? ` por ${v.generated_by}` : ""} con {v.rows_count} fila(s).
          Huella de los datos (SHA-256): <span className="break-all font-mono">{v.data_hash}</span>
        </p>
        <BrandFooter brand={brand} />
      </div>
    </div>
  );
}
