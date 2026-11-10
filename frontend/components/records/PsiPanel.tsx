"use client";

// Encuesta psicosocial anónima: enlace y QR para que respondan los trabajadores, avance de respuestas y,
// al cerrar, los resultados por dimensión y por área (solo grupos con el mínimo de respuestas).

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { recordsHref, type RecordRow } from "@/lib/records";
import { QrCode } from "@/components/mfa/QrCode";

type Link_ = { url: string; status: string; responses: number; workers: number; min_group: number };
type Dim = { code: string; name: string; score: number | null; level: "low" | "medium" | "high" | null };
type Results = { responses: number; min_group: number; shown: boolean; dimensions: Dim[]; areas: { area_id: string | null; area: string; responses: number; dimensions: Dim[] }[]; hidden_responses: number };

const LEVEL = { low: ["Bajo", "bg-ok-soft text-ok"], medium: ["Medio", "bg-amber-soft text-amber-ink"], high: ["Alto", "bg-bad-soft text-bad"] } as const;

function Level({ d }: { d: Dim }) {
  if (d.score === null || !d.level) return <span className="text-xs text-muted">—</span>;
  const [label, tone] = LEVEL[d.level];
  return <span className={`rounded-md px-2 py-0.5 text-xs font-semibold ${tone}`}>{label} · {d.score}</span>;
}

export function PsiPanel({ row }: { row: RecordRow }) {
  const [link, setLink] = useState<Link_ | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    api<{ data: Link_ }>(`/psi/surveys/${row.id}/link`, { withCompany: true })
      .then((r) => setLink(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [row.id, row.status]);

  const results: Results | null = typeof row.results === "string" ? JSON.parse(row.results) : (row.results as Results | null) ?? null;

  return (
    <section className="space-y-3 rounded-xl border border-line px-4 py-3 text-sm">
      {error && <p className="text-bad">{error}</p>}
      {link && row.status === "draft" && (
        <p className="text-muted">Revisa el título y las fechas y luego toca «Abrir encuesta». El cuestionario base tiene 18 preguntas en 6 temas y toma unos 5 minutos.</p>
      )}
      {link && row.status === "open" && (
        <div className="flex flex-wrap items-start gap-4">
          <QrCode value={link.url} size={120} />
          <div className="min-w-0 flex-1 space-y-2">
            <p className="font-semibold">Comparte este enlace o el QR con los trabajadores</p>
            <p className="break-all rounded-lg bg-bg px-2 py-1 text-xs">{link.url}</p>
            <div className="flex flex-wrap gap-2">
              <button
                onClick={() => navigator.clipboard.writeText(link.url).then(() => setCopied(true))}
                className="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white"
              >
                {copied ? "Copiado" : "Copiar enlace"}
              </button>
              <a href={`https://wa.me/?text=${encodeURIComponent(`Encuesta anónima de bienestar en el trabajo: ${link.url}`)}`} target="_blank" rel="noreferrer" className="rounded-lg border border-line px-3 py-1.5 font-semibold text-brand hover:border-brand">
                Enviar por WhatsApp
              </a>
            </div>
            <p className="text-muted">
              <b>{link.responses}</b> de {link.workers} trabajadores respondieron. Nadie (ni el Jefe SST) puede ver quién respondió ni qué respondió cada persona.
            </p>
          </div>
        </div>
      )}
      {results && (
        <div className="space-y-3">
          <p className="font-semibold">
            Resultados · {results.responses} respuestas
            <span className="font-normal text-muted"> · 0 = sin riesgo, 100 = riesgo máximo</span>
          </p>
          {!results.shown ? (
            <p className="rounded-lg bg-amber-soft px-3 py-2 text-amber-ink">
              Hubo menos de {results.min_group} respuestas: para proteger el anonimato no se muestran resultados.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full text-left">
                <thead>
                  <tr className="border-b border-line text-xs text-muted">
                    <th className="py-2 pr-3">Tema</th>
                    <th className="py-2 pr-3">Toda la empresa</th>
                    {results.areas.map((a) => (
                      <th key={a.area_id ?? "none"} className="py-2 pr-3">
                        {a.area_id ? (
                          <a className="text-brand hover:underline" href={recordsHref("TRB", "workers", { filters: { current_area_id: a.area_id, status: "active,on_leave" } })}>{a.area}</a>
                        ) : (
                          a.area
                        )}{" "}
                        ({a.responses})
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {results.dimensions.map((d, i) => (
                    <tr key={d.code} className="border-b border-line last:border-0">
                      <td className="py-2 pr-3">{d.name}</td>
                      <td className="py-2 pr-3"><Level d={d} /></td>
                      {results.areas.map((a) => (
                        <td key={a.area_id ?? "none"} className="py-2 pr-3"><Level d={a.dimensions[i]} /></td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {results.hidden_responses > 0 && results.shown && (
            <p className="text-xs text-muted">{results.hidden_responses} respuesta(s) de áreas con menos de {results.min_group} personas cuentan en el total pero no se muestran por área.</p>
          )}
          {results.shown && results.dimensions.some((d) => d.level === "high") && (
            <a href={recordsHref("ACR", "corrective_actions", { filters: { status: "open,in_progress,overdue" } })} className="inline-block text-sm font-semibold text-brand hover:underline">
              Hay temas en riesgo alto: planifica acciones en Acciones correctivas →
            </a>
          )}
        </div>
      )}
    </section>
  );
}
