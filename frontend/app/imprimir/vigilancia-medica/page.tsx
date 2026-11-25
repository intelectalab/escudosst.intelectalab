"use client";

// Informe agregado y anónimo de vigilancia médica (M-VMD SCR-VMD-04 y §11, para el comité y la revisión por la dirección): solo conteos
// por programa y área (casos, abiertos, cerrados, control vencido, riesgo alto), sin nombres ni datos médicos, y sin los grupos con menos
// casos que el grupo mínimo. También en Excel (/vmd/report.xlsx).

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { BrandFooter, BrandLogo, useBrand } from "@/components/modules/FieldReports";

type G = { name: string; cases: number; open: number; closed: number; overdue: number; high: number };
type Ind = { min_group: number; total: number | null; by_program: { groups: G[]; hidden: number }; by_area: { groups: G[]; hidden: number }; company?: { legal_name: string; ruc: string } };

export default function InformeVigilancia() {
  const [d, setD] = useState<Ind | null>(null);
  const [error, setError] = useState<string | null>(null);
  const brand = useBrand();
  useEffect(() => { api<{ data: Ind }>("/vmd/report", { withCompany: true }).then((r) => setD(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title)); }, []);
  if (error) return <p className="p-8">{error}</p>;
  if (!d) return <p className="p-8">Preparando el informe…</p>;
  const cell = "border border-gray-300 px-2 py-1";
  const table = (t: string, g: { groups: G[]; hidden: number }) => (
    <section className="space-y-2">
      <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">{t}</h2>
      {g.groups.length === 0 ? <p className="text-sm">Sin grupos con {d.min_group} o más casos.</p> : (
        <table className="w-full border-collapse text-xs">
          <thead><tr className="bg-gray-100"><th className={cell}>Grupo</th><th className={cell}>Casos</th><th className={cell}>Abiertos</th><th className={cell}>Cerrados</th><th className={cell}>Control vencido</th><th className={cell}>Riesgo alto</th></tr></thead>
          <tbody>{g.groups.map((x) => <tr key={x.name}><td className={cell}>{x.name}</td><td className={cell}>{x.cases}</td><td className={cell}>{x.open}</td><td className={cell}>{x.closed}</td><td className={cell}>{x.overdue}</td><td className={cell}>{x.high}</td></tr>)}</tbody>
        </table>
      )}
      {g.hidden > 0 && <p className="text-xs text-gray-600">{g.hidden} grupo(s) con menos de {d.min_group} casos no se muestran para proteger la identidad.</p>}
    </section>
  );
  return (
    <div className="min-h-screen bg-gray-100 p-4 text-gray-900 print:bg-white print:p-0">
      <style>{`@page { size: A4; margin: 14mm } @media print { .no-print { display: none } }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-3xl justify-end"><button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">Imprimir / Guardar PDF</button></div>
      <div className="mx-auto max-w-3xl space-y-5 bg-white p-8 shadow print:max-w-none print:p-0 print:shadow-none">
        <header className="text-center">
          <BrandLogo brand={brand} className="mx-auto h-10 w-auto object-contain" />
          <h1 className="text-lg font-bold uppercase">Vigilancia médica ocupacional · informe agregado</h1>
          {d.company && <p className="text-sm">{d.company.legal_name} · RUC {d.company.ruc}</p>}
          <p className="text-sm">Generado el {new Date().toLocaleDateString("es-PE")} · casos en seguimiento: {d.total ?? `menos de ${d.min_group}`}</p>
        </header>
        {table("Por programa", d.by_program)}
        {table("Por área", d.by_area)}
        <p className="text-xs text-gray-600">Informe anónimo: no contiene nombres, documentos ni datos médicos de ninguna persona.</p>
        <BrandFooter brand={brand} />
      </div>
    </div>
  );
}
