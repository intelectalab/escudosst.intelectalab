"use client";

// Hojas para imprimir o guardar en PDF (diálogo del navegador) del Comité SST: acta de elección de representantes
// (SCR-COM-06) y acta de reunión con firmas (SCR-COM-08). Usan la sesión de la pestaña.

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import type { Election } from "./ComElection";
import { fmtDateTime } from "./ComElection";
import { MinutesPaper, SignatureGrid, type Minutes } from "./ComModule";

function useSheet<T>(path: string) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: T }>(path, { withCompany: true }).then((r) => setData(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [path]);
  return { data, error };
}

function Sheet({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen bg-gray-100 p-4 text-gray-900 print:bg-white print:p-0">
      <style>{`@page { size: A4; margin: 16mm } @media print { .no-print { display: none } }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-3xl flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow">
        <span className="text-sm text-gray-600">Para el PDF elige «Guardar como PDF» en el diálogo de impresión.</span>
        <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">
          Imprimir / Guardar PDF
        </button>
      </div>
      <div className="mx-auto max-w-3xl space-y-4 bg-white p-8 shadow print:max-w-none print:p-0 print:shadow-none">{children}</div>
    </div>
  );
}

const RESULT: Record<string, string> = { elected_titular: "Titular", elected_alternate: "Suplente", not_elected: "No elegido" };

export function ElectionMinutesSheet({ id }: { id: string }) {
  const { data, error } = useSheet<{ company: { name: string; legal_name: string; ruc: string }; election: Election }>(`/com/elections/${id}/minutes`);
  if (error) return <p className="p-8">{error}</p>;
  if (!data) return <p className="p-8">Preparando el acta…</p>;
  const e = data.election;
  const r = e.results;
  return (
    <Sheet>
      <p className="text-center text-lg font-bold uppercase">Acta de elección de representantes de los trabajadores ante el {e.seats_titular === 1 && e.seats_alternate === 0 ? "Supervisor" : "Comité"} de Seguridad y Salud en el Trabajo</p>
      {e.status !== "results_published" && <p className="text-center text-sm font-bold text-red-700">BORRADOR · resultados aún no publicados</p>}
      <p className="text-sm leading-relaxed">
        {data.company.legal_name} · RUC {data.company.ruc}. Elección electrónica «{e.title}», con votación secreta y directa desde el {fmtDateTime(e.voting_opens_at)} hasta el{" "}
        {fmtDateTime(e.voting_closes_at)}, para elegir {e.seats_titular} representante{e.seats_titular === 1 ? "" : "s"} titular{e.seats_titular === 1 ? "" : "es"} y {e.seats_alternate} suplente
        {e.seats_alternate === 1 ? "" : "s"}. Padrón congelado el {fmtDateTime(e.roll_snapshot_at)} con {r?.eligible ?? e.eligible_voters} electores habilitados.
      </p>
      {r && (
        <>
          <p className="text-sm">
            Votos emitidos: <b>{r.total}</b> · válidos: <b>{r.valid}</b> · en blanco: <b>{r.blank}</b> · nulos: <b>{r.null}</b> · participación:{" "}
            <b>{r.participation_pct == null ? "—" : `${r.participation_pct} %`}</b>.
          </p>
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="bg-gray-100">
                {["N.°", "Candidato", "Votos", "Resultado"].map((h) => <th key={h} className="border border-gray-300 px-2 py-1 text-left">{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {r.ranking.map((x) => (
                <tr key={x.candidate_id}>
                  <td className="border border-gray-300 px-2 py-1">{x.position}</td>
                  <td className="border border-gray-300 px-2 py-1">{x.name}</td>
                  <td className="border border-gray-300 px-2 py-1">{x.votes}</td>
                  <td className="border border-gray-300 px-2 py-1 font-semibold">{RESULT[x.result] ?? x.result}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="text-sm">Regla de desempate: {r.tie_break_rule === "seniority" ? "mayor antigüedad en la empresa" : "sorteo registrado"}.</p>
          {r.ties.map((t) => <p key={t.note} className="text-sm">{t.note}</p>)}
        </>
      )}
      <p className="text-sm">Los votos se registraron sin vincularse a la identidad del elector y no pueden modificarse.</p>
      {e.electoral_board.length > 0 && (
        <div className="grid grid-cols-2 gap-6 pt-10 sm:grid-cols-3">
          {e.electoral_board.map((b) => (
            <div key={b.name} className="border-t border-gray-500 pt-1 text-center text-xs">
              <b>{b.name}</b>
              <p>{b.role ?? "Junta electoral"}</p>
            </div>
          ))}
        </div>
      )}
      <p className="pt-4 text-xs text-gray-500">Generada el {fmtDateTime(new Date().toISOString())} · {data.company.name}</p>
    </Sheet>
  );
}

export function MeetingMinutesSheet({ id }: { id: string }) {
  const { data, error } = useSheet<Minutes>(`/com/meetings/${id}/minutes`);
  if (error) return <p className="p-8">{error}</p>;
  if (!data) return <p className="p-8">Preparando el acta…</p>;
  return (
    <Sheet>
      <MinutesPaper d={data} />
      <div>
        <p className="mb-2 text-sm font-bold">Firmas</p>
        <SignatureGrid d={data} />
      </div>
      <p className="pt-4 text-xs text-gray-500">Generada el {fmtDateTime(new Date().toISOString())} · {data.company.name}</p>
    </Sheet>
  );
}
