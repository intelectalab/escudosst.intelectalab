"use client";

// Capacitación (M-CAP, SCR-CAP-07/08; prototipo m-cap.js R.CAP): los cuatro indicadores de la cabecera y la tarjeta
// plegable «Matriz por cargo»: cargos × capacitaciones exigidas con el % de trabajadores del cargo que la tienen vigente
// (certificado validado o asistencia dentro del plazo de renovación) y su semáforo.

import { useEffect, useMemo, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { recordsHref } from "@/lib/records";
import { Icon } from "@/components/ui/Icon";
import { Card, Dot, Kpi, fmtNum, toneOf, TONE_CHIP } from "./Summary";

type Matrix = {
  positions: { id: string; name: string; workers: number }[];
  trainings: { id: string; code: string | null; title: string }[];
  cells: { position_id: string; training_id: string; level: string; total: number; done: number }[];
  kpis: { hours_per_worker: number | null; matrix_pct: number | null; matrix_num: number; matrix_den: number; certificates_pending: number; certificates_expiring: number };
};

export function CapMatrix() {
  const [data, setData] = useState<Matrix | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState(true);
  useEffect(() => {
    api<{ data: Matrix }>("/cap/matrix", { withCompany: true })
      .then((r) => setData(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  const cell = useMemo(() => new Map((data?.cells ?? []).map((c) => [`${c.position_id}|${c.training_id}`, c])), [data]);

  if (error) return <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">{error}</p>;
  if (!data) return <div className="h-28 animate-pulse rounded-xl bg-surface" />;
  const k = data.kpis;
  const mtone = toneOf(k.matrix_pct);

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Kpi label="Horas por trabajador (año)" value={fmtNum(k.hours_per_worker, 1)} sub="Asistencias registradas" />
        <Kpi
          label="Cumplimiento de la matriz"
          value={k.matrix_pct == null ? "—" : `${k.matrix_pct}%`}
          color={mtone === "ok" ? "text-ok" : mtone === "na" ? "text-muted" : "text-amber-ink"}
          sub={k.matrix_den ? `${k.matrix_num} de ${k.matrix_den} obligatorias por cargo` : "Sin cursos obligatorios por cargo"}
          href={recordsHref("CAP", "position_training_requirements")}
        />
        <Kpi label="Certificados por validar" value={String(k.certificates_pending)} color="text-[#F08A24]" href={recordsHref("CAP", "training_certificates", { filters: { status: "pending_validation" } })} />
        <Kpi label="Certificados por vencer" value={String(k.certificates_expiring)} color="text-bad" sub="En los próximos 30 días" href={recordsHref("CAP", "training_certificates", { filters: { status: "validated" } })} />
      </div>

      <Card
        title="Matriz por cargo"
        action={
          <button type="button" className="inline-flex items-center gap-1 text-sm font-semibold text-brand" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
            {open ? "Ocultar" : "Mostrar"} <Icon name={open ? "collapse" : "expand"} size={16} />
          </button>
        }
      >
        {open &&
          (data.positions.length === 0 ? (
            <p className="text-sm text-muted">Aún no hay capacitaciones exigidas por cargo. Configúralas en la pestaña «Matriz por cargo».</p>
          ) : (
            <>
              <div className="mb-3 flex flex-wrap gap-4 text-xs text-muted">
                <span className="inline-flex items-center gap-1"><Dot tone="ok" /> 90% o más</span>
                <span className="inline-flex items-center gap-1"><Dot tone="warn" /> 70% a 89%</span>
                <span className="inline-flex items-center gap-1"><Dot tone="bad" /> menos de 70%</span>
                <span><b>—</b> no exigida</span>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="border-b border-line text-xs font-semibold text-muted">
                    <tr>
                      <th className="min-w-[180px] px-3 py-2">Cargo</th>
                      {data.trainings.map((t) => (
                        <th key={t.id} className="min-w-[110px] px-3 py-2" title={t.title}>
                          <span className="line-clamp-2">{t.title}</span>
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {data.positions.map((p) => (
                      <tr key={p.id} className="border-b border-line last:border-0">
                        <td className="px-3 py-2">
                          <b>{p.name}</b>
                          <span className="block text-xs text-muted">{p.workers} trabajador(es)</span>
                        </td>
                        {data.trainings.map((t) => {
                          const c = cell.get(`${p.id}|${t.id}`);
                          if (!c) return <td key={t.id} className="px-3 py-2 text-muted" title="No exigida">—</td>;
                          const pct = c.total ? Math.round((c.done * 100) / c.total) : null;
                          const tone = toneOf(pct);
                          return (
                            <td key={t.id} className="px-3 py-2" title={`${c.done} de ${c.total} cumplen${c.level === "suggested" ? " · sugerida" : ""}`}>
                              <span className={`inline-flex items-center gap-1.5 rounded-md px-2 py-1 ${TONE_CHIP[tone]}`}>
                                <Dot tone={tone} />
                                <b className="tabular-nums">{pct == null ? "—" : `${pct}%`}</b>
                              </span>
                              <span className="block text-[11px] text-muted">
                                {c.done}/{c.total}
                                {c.level === "suggested" ? " · sugerida" : ""}
                              </span>
                            </td>
                          );
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="mt-3 text-xs text-muted">Cuenta como vigente el certificado validado no vencido o la asistencia dentro del plazo de renovación del cargo.</p>
            </>
          ))}
      </Card>
    </div>
  );
}
