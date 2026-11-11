"use client";

// Mis capacitaciones (M-CAP), certificados y Mis cursos del aula (M-AUL, si la empresa lo tiene activo) con el bloque
// «Recomendados para ti» (docs/07 §5.2 punto 4).

import Link from "next/link";
import { useEffect, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { Alert, Empty, Loading, Panel, Pill, Title } from "@/components/worker/WorkerUi";
import { errorText, fmtDate, workerApi } from "@/lib/workerApp";

type Data = {
  attendances: { id: string; title: string; starts_at: string; hours: number | null; attended: boolean; status: string }[];
  upcoming: { id: string; title: string; starts_at: string; location: string | null; virtual_url: string | null }[];
  certificates: { id: string; course_title: string; certificate_code: string; issued_on: string; expires_on: string | null; hours: number | null; status: string }[];
  aul_enabled: boolean;
  courses: { id: string; title: string; hours: number | null; status: string; progress_pct: number; due_on: string | null; passed: boolean | null; certificate_id: string | null }[];
  recommended: { id: string; title: string; hours: number | null; message: string }[];
};

const today = () => new Date().toISOString().slice(0, 10);

export default function MyTrainingsPage() {
  const [d, setD] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    workerApi<Data>("/trainings").then(setD).catch((e) => setError(errorText(e)));
  }, []);

  if (error) return <Alert>{error}</Alert>;
  if (!d) return <Loading />;
  const open = d.courses.filter((c) => c.status === "enrolled" || c.status === "in_progress");
  const doneCourses = d.courses.filter((c) => !(c.status === "enrolled" || c.status === "in_progress"));

  return (
    <>
      <Title sub="Tus cursos, constancias y certificados.">Mis capacitaciones</Title>

      {d.aul_enabled && (
        <Panel title="Mis cursos">
          {open.length === 0 ? <p className="text-sm text-muted">No tienes cursos por terminar.</p> : (
            <div className="space-y-2">
              {open.map((c) => (
                <Link key={c.id} href={`/mi/cursos/${c.id}`} className="block space-y-2 rounded-xl border border-line p-3 hover:border-brand">
                  <div className="flex items-center justify-between gap-2">
                    <b className="leading-tight">{c.title}</b>
                    <Icon name="play" size={18} className="shrink-0 text-brand" />
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-na-soft" role="progressbar" aria-valuenow={Math.round(c.progress_pct)} aria-valuemin={0} aria-valuemax={100} aria-label={`Avance de ${c.title}`}>
                    <div className="h-full bg-brand" style={{ width: `${Math.min(100, c.progress_pct)}%` }} />
                  </div>
                  <p className="text-xs text-muted">Avance {Math.round(c.progress_pct)}%{c.due_on ? ` · termina antes del ${fmtDate(c.due_on)}` : ""}{c.hours ? ` · ${c.hours} h` : ""}</p>
                </Link>
              ))}
            </div>
          )}
          {doneCourses.length > 0 && (
            <ul className="divide-y divide-line">
              {doneCourses.map((c) => (
                <li key={c.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                  <Link href={`/mi/cursos/${c.id}`} className="font-semibold hover:text-brand">{c.title}</Link>
                  <Pill tone={c.passed ? "ok" : c.status === "failed" ? "bad" : "na"}>{c.passed ? "Aprobado" : c.status === "failed" ? "Desaprobado" : c.status === "expired" ? "Vencido" : "Terminado"}</Pill>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      )}

      {d.aul_enabled && d.recommended.length > 0 && (
        <Panel title="Recomendados para ti">
          <ul className="space-y-2">
            {d.recommended.map((r) => (
              <li key={r.id} className="rounded-xl bg-brand-soft px-4 py-3">
                <b className="block">{r.title}</b>
                <span className="text-sm text-muted">{r.message || "El área de SST te sugiere este curso."}{r.hours ? ` · ${r.hours} h` : ""}</span>
              </li>
            ))}
          </ul>
          <p className="text-xs text-muted">Pide al área de SST que te matricule en el curso que te interese.</p>
        </Panel>
      )}

      {d.upcoming.length > 0 && (
        <Panel title="Próximas sesiones">
          <ul className="divide-y divide-line">
            {d.upcoming.map((s) => (
              <li key={s.id} className="py-2 text-sm">
                <b className="block">{s.title}</b>
                <span className="text-muted">{fmtDate(s.starts_at, true)}{s.location ? ` · ${s.location}` : ""}</span>
                {s.virtual_url && <a href={s.virtual_url} target="_blank" rel="noreferrer" className="ml-2 font-semibold text-brand underline">Entrar</a>}
              </li>
            ))}
          </ul>
        </Panel>
      )}

      <Panel title="Mis certificados">
        {d.certificates.length === 0 ? <p className="text-sm text-muted">Aún no tienes certificados.</p> : (
          <ul className="divide-y divide-line">
            {d.certificates.map((c) => {
              const expired = c.status === "expired" || (!!c.expires_on && c.expires_on < today());
              return (
                <li key={c.id} className="flex items-center justify-between gap-3 py-3">
                  <span className="min-w-0">
                    <b className="block text-sm leading-tight">{c.course_title}</b>
                    <span className="text-xs text-muted">{fmtDate(c.issued_on)}{c.expires_on ? ` · vence ${fmtDate(c.expires_on)}` : ""}</span>
                  </span>
                  {expired ? <Pill tone="bad">Vencido</Pill> : (
                    <a href={`/imprimir/certificado/${c.id}`} target="_blank" rel="noreferrer" className="inline-flex h-12 shrink-0 items-center gap-1 rounded-xl border border-line px-3 text-sm font-semibold hover:border-brand">
                      <Icon name="download" size={16} /> Ver
                    </a>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </Panel>

      <Panel title="Capacitaciones a las que asististe">
        {d.attendances.length === 0 ? <Empty text="Aún no hay asistencias registradas." /> : (
          <ul className="divide-y divide-line">
            {d.attendances.map((a) => (
              <li key={a.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                <span className="min-w-0">
                  <b className="block leading-tight">{a.title}</b>
                  <span className="text-xs text-muted">{fmtDate(a.starts_at)}{a.hours ? ` · ${Number(a.hours).toFixed(1)} h` : ""}</span>
                </span>
                <Pill tone={a.attended ? "ok" : "na"}>{a.attended ? "Asististe" : "Faltaste"}</Pill>
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </>
  );
}
