"use client";

// «PDF único» del expediente SUNAFIL (M-SUN SCR-SUN-05, RN-SUN-06/11): portada con índice numerado y el folio de cada
// punto, y una hoja por punto con su estado, brecha y documentos adjuntos. Cada hoja lleva «Folio N de T»; se imprime o
// guarda en PDF con el diálogo del navegador. Usa la sesión de la pestaña (GET /sun/dossiers/{id}/print).

import { Fragment, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";

type Item = {
  number: number;
  folio: number;
  code: string;
  title: string;
  status: string;
  gap: string | null;
  module: string | null;
  register_type: string | null;
  worker: string | null;
  section?: string;
  reviewed_at: string | null;
  files: string[];
};
type Sheet = {
  company: { name: string; legal_name: string; ruc: string };
  dossier: {
    id: string;
    title: string;
    inspection_order_number: string | null;
    inspector_name: string | null;
    appearance_at: string | null;
    period_from: string;
    period_to: string;
    built_at: string;
    site_name: string | null;
  };
  index_pages: number;
  index_per_page: number;
  total_folios: number;
  items: Item[];
  sections?: { key: string; worker_id: string | null; title: string; first_folio: number; first_number: number; count: number; gaps: number }[];
  summary: { total: number; included: number; gaps: number };
  generated_at: string;
};

const ST: Record<string, string> = { included: "Incluido", missing: "Pendiente", expired: "Vencido", not_applicable: "No aplica" };
const day = (v: string | null) => (v ? new Intl.DateTimeFormat("es-PE", { dateStyle: "short", timeZone: "America/Lima" }).format(new Date(v.length === 10 ? `${v}T12:00:00-05:00` : v)) : "—");
const dtm = (v: string | null) => (v ? new Intl.DateTimeFormat("es-PE", { dateStyle: "short", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(v)) : "—");

function Page({ folio, total, data, children }: { folio: number; total: number; data: Sheet; children: React.ReactNode }) {
  return (
    <section className="sheet-page relative mx-auto flex min-h-[260mm] max-w-3xl flex-col bg-white p-8 shadow print:max-w-none print:p-0 print:shadow-none">
      <header className="mb-4 flex items-start justify-between gap-4 border-b border-gray-300 pb-2 text-xs text-gray-600">
        <span>
          {data.company.legal_name} · RUC {data.company.ruc}
          <br />
          {data.dossier.title}
        </span>
        <b className="whitespace-nowrap text-sm text-gray-900">Folio {folio} de {total}</b>
      </header>
      <div className="flex-1">{children}</div>
      <footer className="mt-6 flex justify-between border-t border-gray-300 pt-2 text-[10px] text-gray-500">
        <span>Generado el {dtm(data.generated_at)} con EscudoSST · sin datos médicos</span>
        <span>Folio {folio} de {total}</span>
      </footer>
    </section>
  );
}

export function DossierSheet({ id }: { id: string }) {
  const [data, setData] = useState<Sheet | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: Sheet }>(`/sun/dossiers/${id}/print`, { withCompany: true }).then((r) => setData(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [id]);
  if (error) return <p className="p-8">{error}</p>;
  if (!data) return <p className="p-8">Preparando el expediente…</p>;

  const d = data.dossier;
  const sections = data.sections ?? [];
  const chunks: Item[][] = [];
  for (let i = 0; i < data.items.length; i += data.index_per_page) chunks.push(data.items.slice(i, i + data.index_per_page));
  if (chunks.length === 0) chunks.push([]);

  return (
    <div className="min-h-screen space-y-4 bg-gray-100 p-4 text-gray-900 print:space-y-0 print:bg-white print:p-0">
      <style>{`@page { size: A4; margin: 14mm } @media print { .no-print { display: none } .sheet-page { break-after: page; min-height: auto } .sheet-page:last-child { break-after: auto } }`}</style>
      <div className="no-print mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow">
        <span className="text-sm text-gray-600">PDF único foliado · {data.total_folios} folios. Para el PDF elige «Guardar como PDF» en el diálogo de impresión.</span>
        <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">
          Imprimir / Guardar PDF
        </button>
      </div>

      {chunks.map((chunk, p) => (
        <Page key={`idx-${p}`} folio={p + 1} total={data.total_folios} data={data}>
          {p === 0 && (
            <div className="mb-5 space-y-1">
              <p className="text-xs font-bold uppercase tracking-wider text-gray-500">Expediente para inspección</p>
              <h1 className="text-2xl font-bold">{d.title}</h1>
              <p className="text-sm">
                {d.inspection_order_number && <>Orden de inspección {d.inspection_order_number} · </>}
                Periodo {day(d.period_from)} al {day(d.period_to)}
                {d.site_name && <> · Sede {d.site_name}</>}
              </p>
              {(d.inspector_name || d.appearance_at) && (
                <p className="text-sm">
                  {d.inspector_name && <>Inspector: {d.inspector_name}</>}
                  {d.appearance_at && <> · Comparecencia {dtm(d.appearance_at)}</>}
                </p>
              )}
              {sections.length > 1 && (
                <p className="text-sm">Secciones: {sections.map((x) => `${x.title} (folio ${x.first_folio})`).join(" · ")}.</p>
              )}
              <p className="text-sm">
                Revisado el {dtm(d.built_at)} · {data.summary.included} de {data.summary.total} puntos incluidos
                {data.summary.gaps ? ` · ${data.summary.gaps} pendiente(s) o vencido(s)` : ""}.
              </p>
            </div>
          )}
          <h2 className="mb-2 text-sm font-bold uppercase">Índice{chunks.length > 1 ? ` (${p + 1} de ${chunks.length})` : ""}</h2>
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="bg-gray-100">
                {["N.°", "Punto", "Estado", "Folio"].map((h) => (
                  <th key={h} className="border border-gray-300 px-2 py-1 text-left">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {chunk.map((it, k) => {
                // Sección por trabajador (SCR-SUN-04): encabezado cuando cambia la sección o al empezar cada hoja del índice.
                const sec = sections.length > 1 ? sections.find((x) => x.key === it.section) : undefined;
                const head = sec && (it.number === sec.first_number || k === 0);
                return (
                <Fragment key={it.number}>
                {head && (
                  <tr className="bg-gray-50">
                    <td colSpan={4} className="border border-gray-300 px-2 py-1 font-bold">
                      {sec.title}{it.number !== sec.first_number ? " (continúa)" : ` · ${sec.count} punto(s)${sec.gaps ? `, ${sec.gaps} pendiente(s)` : ""} · desde el folio ${sec.first_folio}`}
                    </td>
                  </tr>
                )}
                <tr>
                  <td className="border border-gray-300 px-2 py-1">{it.number}</td>
                  <td className="border border-gray-300 px-2 py-1">
                    <b>{it.code}.</b> {it.title}
                  </td>
                  <td className="border border-gray-300 px-2 py-1">{ST[it.status] ?? it.status}</td>
                  <td className="border border-gray-300 px-2 py-1 tabular-nums">{it.folio}</td>
                </tr>
                </Fragment>
                );
              })}
            </tbody>
          </table>
        </Page>
      ))}

      {data.items.map((it) => (
        <Page key={it.number} folio={it.folio} total={data.total_folios} data={data}>
          <p className="text-xs font-bold uppercase tracking-wider text-gray-500">
            Punto {it.number} · {it.code}{sections.length > 1 ? ` · ${sections.find((x) => x.key === it.section)?.title ?? ""}` : ""}
          </p>
          <h2 className="mb-3 text-xl font-bold">{it.title}</h2>
          <dl className="grid grid-cols-[10rem_1fr] gap-x-3 gap-y-1.5 text-sm">
            <dt className="text-gray-500">Estado</dt>
            <dd className={it.status === "included" ? "font-semibold" : "font-semibold text-red-700"}>{ST[it.status] ?? it.status}</dd>
            {it.worker && (
              <>
                <dt className="text-gray-500">Trabajador</dt>
                <dd>{it.worker}</dd>
              </>
            )}
            {it.register_type && (
              <>
                <dt className="text-gray-500">Registro</dt>
                <dd>{it.register_type}</dd>
              </>
            )}
            <dt className="text-gray-500">Revisado</dt>
            <dd>{dtm(it.reviewed_at)}</dd>
            <dt className="text-gray-500">Documentos adjuntos</dt>
            <dd>{it.files.length ? it.files.join(", ") : "Registro en el sistema; se muestra en la diligencia."}</dd>
          </dl>
          {it.gap && <p className="mt-4 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{it.gap}</p>}
        </Page>
      ))}
    </div>
  );
}
