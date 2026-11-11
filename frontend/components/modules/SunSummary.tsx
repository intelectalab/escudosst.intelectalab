"use client";

// Modo Inspección SUNAFIL (M-SUN, SCR-SUN-01/02; prototipo m-diferenciadores.js R.SUN): autodiagnóstico calculado con
// los datos reales de los módulos (puntaje en anillo y una fila por área con semáforo, barra, % y enlace), la tarjeta
// «¿Llegó un inspector?» que crea el expediente y lo abre para armarlo, y los expedientes recientes.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { records, recordsHref, type RecordRow } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { Bar, Card, Chip, Dot, Ring, type Tone } from "./Summary";
import { SunWizard } from "./SunWizard";

type Area = { name: string; module: string; table: string | null; pct: number | null; tone: Tone; detail: string; enabled: boolean };
type Diagnosis = { score: number | null; evaluated_at: string; areas: Area[] };

const STATUS: Record<string, [string, Tone]> = {
  queued: ["En cola", "na"],
  building: ["Armando", "warn"],
  ready: ["Completo", "ok"],
  ready_with_gaps: ["Con brechas", "warn"],
  failed: ["Falló", "bad"],
  archived: ["Archivado", "na"],
};

const dt = (v: unknown) => (v ? new Intl.DateTimeFormat("es-PE", { dateStyle: "short", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(String(v))) : "");

export function SunSummary({ readOnly }: { readOnly: boolean }) {
  const [diag, setDiag] = useState<Diagnosis | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [recent, setRecent] = useState<RecordRow[] | null>(null);

  const load = useCallback(() => {
    setError(null);
    api<{ data: Diagnosis }>("/sun/diagnosis", { withCompany: true })
      .then((r) => setDiag(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  useEffect(() => {
    load();
    records.list("SUN", "inspection_dossiers", { page: 1 }).then((r) => setRecent(r.data.slice(0, 3))).catch(() => setRecent([]));
  }, [load]);

  // «Generar expediente» abre el asistente (SCR-SUN-03): crea el expediente y lo arma.
  const [wizard, setWizard] = useState(false);
  const reloadRecent = () => records.list("SUN", "inspection_dossiers", { page: 1 }).then((r) => setRecent(r.data.slice(0, 3))).catch(() => undefined);

  const score = diag?.score ?? null;
  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <Card title="Autodiagnóstico frente a una inspección" action={<Button size="sm" icon="refresh" onClick={load}>Volver a evaluar</Button>}>
        {error ? (
          <p className="text-sm text-muted">{error}</p>
        ) : !diag ? (
          <div className="h-48 animate-pulse rounded-lg bg-bg" />
        ) : (
          <>
            <div className="mb-3 flex flex-wrap items-center gap-5">
              <Ring pct={score} label="Puntaje" />
              <div className="min-w-[200px] flex-1 text-sm">
                <p>
                  <b>Puntaje {score ?? "—"} de 100.</b> Si SUNAFIL llegara hoy, estas son las brechas a cerrar primero.
                </p>
                <p className="mt-1 text-xs text-muted">Evaluado el {dt(diag.evaluated_at)} con los datos del sistema. Toca un área para ir a resolverla.</p>
              </div>
            </div>
            <ul className="divide-y divide-line">
              {[...diag.areas]
                .sort((a, b) => (a.pct ?? 101) - (b.pct ?? 101))
                .map((a) => (
                  <li key={a.name}>
                    <Link
                      href={a.enabled && a.table ? recordsHref(a.module, a.table) : `/m/${a.module.toLowerCase()}`}
                      className="flex items-center gap-3 rounded-lg px-1 py-2.5 hover:bg-brand-soft/40"
                    >
                      <Dot tone={a.tone} />
                      <span className="min-w-0 flex-1">
                        <b className="block text-sm">{a.name}</b>
                        <span className="block text-xs text-muted">{a.detail}</span>
                      </span>
                      <span className="hidden w-28 sm:block">{a.pct !== null && <Bar pct={a.pct} tone={a.tone} />}</span>
                      <b className="w-10 text-right font-display tabular-nums">{a.pct ?? "—"}</b>
                    </Link>
                  </li>
                ))}
            </ul>
          </>
        )}
      </Card>
      <div className="space-y-4">
        <Card title="¿Llegó un inspector?">
          <p className="mb-3 text-sm text-muted">Arma el expediente que te pidan en minutos, por trabajador, periodo u obligación, y compártelo con un enlace que vence solo.</p>
          {!readOnly && (
            <Button variant="primary" size="lg" full onClick={() => setWizard(true)} icon="shield">
              Generar expediente
            </Button>
          )}
          {wizard && <SunWizard onClose={() => setWizard(false)} onDone={reloadRecent} />}
        </Card>
        <Card title="Expedientes recientes">
          {recent === null ? (
            <p className="text-sm text-muted">Cargando…</p>
          ) : recent.length === 0 ? (
            <p className="text-sm text-muted">Aún no hay expedientes.</p>
          ) : (
            <ul className="divide-y divide-line">
              {recent.map((x) => {
                const [label, tone] = STATUS[String(x.status ?? "")] ?? [String(x.status ?? "—"), "na" as Tone];
                return (
                  <li key={x.id}>
                    <Link href={recordsHref("SUN", "inspection_dossiers", { open: x.id })} className="flex items-center gap-3 py-2.5 hover:text-brand">
                      <Icon name="file" size={18} />
                      <span className="min-w-0 flex-1">
                        <b className="block truncate text-sm">{String(x.title ?? "Expediente")}</b>
                        <span className="block text-xs text-muted">{x.appearance_at ? `Comparecencia ${dt(x.appearance_at)}` : `Actualizado ${dt(x.updated_at)}`}</span>
                      </span>
                      <Chip tone={tone}>{label}</Chip>
                    </Link>
                  </li>
                );
              })}
            </ul>
          )}
        </Card>
      </div>
    </div>
  );
}
