"use client";

// Copias de seguridad en «Salud del sistema» (docs/15 §9): estado por base, últimas copias y «Hacer copia ahora».
// Las copias se cifran en el servidor; sin la llave (guardada fuera del servidor) no se pueden abrir.

import { useCallback, useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip } from "@/components/ui/Display";
import { Icon } from "@/components/ui/Icon";
import { api } from "@/lib/api";

type Target = { target: string; label: string; last_ok_at: string | null; last_size_bytes: number | null; last_status: string | null; last_error: string | null; overdue: boolean };
type Run = { id: string; target: string; trigger: string; status: "running" | "ok" | "failed"; started_at: string; finished_at: string | null; size_bytes: number | null; error: string | null; deleted_at: string | null; requested_by: string | null };
type Status = { targets: Target[]; runs: Run[]; config: { disk: string; retention_days: number; daily_at: string; max_age_hours: number; keys_ready: boolean } };

const size = (b: number | null) => (b == null ? "–" : b > 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${(b / 1024).toFixed(0)} KB`);
const when = (d: string | null) => (d ? new Date(d).toLocaleString("es-PE", { dateStyle: "short", timeStyle: "short" }) : "nunca");

export function BackupsSection({ onToast }: { onToast: (t: string) => void }) {
  const me = usePlatformUser();
  const [s, setS] = useState<Status | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    api<{ data: Status }>("/internal/backups").then((r) => setS(r.data));
  }, []);
  useEffect(load, [load]);

  async function runNow() {
    setBusy(true);
    try {
      await api("/internal/backups", { method: "POST" });
      onToast("Copia en marcha. En unos segundos aparece abajo.");
      window.setTimeout(load, 4000);
      window.setTimeout(load, 12000);
    } catch {
      onToast("No se pudo iniciar la copia.");
    } finally {
      window.setTimeout(() => setBusy(false), 4000);
    }
  }

  if (!s) return <div className="h-40 animate-pulse rounded-2xl bg-line/40" />;

  return (
    <section className="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm" aria-label="Copias de seguridad">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-gradient-to-r from-side to-brand px-5 py-3 text-white">
        <h2 className="flex items-center gap-2 font-display font-semibold">
          <Icon name="database" size={18} /> Copias de seguridad
        </h2>
        {me.platform_role !== "support" && (
          <Button size="sm" variant="secondary" icon="refresh" loading={busy} onClick={runNow}>
            Hacer copia ahora
          </Button>
        )}
      </div>

      <div className="grid grid-cols-1 gap-3 p-4 md:grid-cols-2">
        {s.targets.map((t) => {
          const bad = t.last_status === "failed" || t.overdue;
          return (
            <div key={t.target} className={`flex items-start gap-3 rounded-xl border p-4 ${bad ? "border-bad/30 bg-bad-soft/50" : "border-ok/30 bg-ok-soft/40"}`}>
              <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl text-white ${bad ? "bg-bad" : "bg-ok"}`}>
                <Icon name={bad ? "lock" : "check"} size={18} />
              </span>
              <span className="min-w-0 text-sm">
                <b className="block">{t.label}</b>
                <span className="block text-muted">
                  Última copia buena: <b className="text-fg">{when(t.last_ok_at)}</b> · {size(t.last_size_bytes)}
                </span>
                {t.last_status === "failed" ? (
                  <span className="mt-1 block text-xs text-bad">La última falló: {t.last_error}</span>
                ) : t.overdue ? (
                  <span className="mt-1 block text-xs text-bad">Atrasada: no hay copia en las últimas {s.config.max_age_hours} horas.</span>
                ) : null}
              </span>
            </div>
          );
        })}
      </div>

      <ul className="grid grid-cols-1 gap-2 border-y border-line bg-bg/60 px-5 py-3 text-xs text-muted sm:grid-cols-2 xl:grid-cols-4">
        <li className="flex gap-2">
          <Icon name="clock" size={14} className="mt-0.5 shrink-0 text-brand" /> Automática todos los días a las {s.config.daily_at} (hora de Lima).
        </li>
        <li className="flex gap-2">
          <Icon name="lock" size={14} className="mt-0.5 shrink-0 text-brand" /> Cifrada antes de guardarse; la base médica con otra llave. {s.config.keys_ready ? "" : "⚠ Faltan las llaves en el servidor."}
        </li>
        <li className="flex gap-2">
          <Icon name="history" size={14} className="mt-0.5 shrink-0 text-brand" /> Se guardan {s.config.retention_days} días y luego se borran solas.
        </li>
        <li className="flex gap-2">
          <Icon name="database" size={14} className="mt-0.5 shrink-0 text-brand" /> Destino: {s.config.disk === "backups" ? "disco del servidor (en producción: bucket de respaldos de B2)" : s.config.disk}.
        </li>
      </ul>

      <div className="max-h-72 overflow-y-auto">
        <table className="w-full text-sm">
          <thead className="sticky top-0 bg-surface text-left text-xs uppercase tracking-wide text-muted">
            <tr className="border-b border-line">
              <th className="px-5 py-2 font-semibold">Fecha</th>
              <th className="px-3 py-2 font-semibold">Base</th>
              <th className="px-3 py-2 font-semibold">Origen</th>
              <th className="px-3 py-2 font-semibold">Resultado</th>
              <th className="px-5 py-2 text-right font-semibold">Tamaño</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {s.runs.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-5 py-6 text-center text-muted">
                  Todavía no hay copias.
                </td>
              </tr>
            ) : (
              s.runs.map((r) => (
                <tr key={r.id} className={r.deleted_at ? "opacity-50" : ""}>
                  <td className="px-5 py-2 tabular-nums">{when(r.started_at)}</td>
                  <td className="px-3 py-2">{r.target === "medical" ? "Médica" : "Principal"}</td>
                  <td className="px-3 py-2 text-muted">{r.trigger === "manual" ? `A mano${r.requested_by ? ` · ${r.requested_by}` : ""}` : "Automática"}</td>
                  <td className="px-3 py-2">
                    {r.status === "ok" ? <Chip tone="ok">{r.deleted_at ? "Borrada por antigüedad" : "Lista"}</Chip> : r.status === "running" ? <Chip tone="amber">En curso</Chip> : <Chip tone="bad">Falló</Chip>}
                  </td>
                  <td className="px-5 py-2 text-right tabular-nums">{size(r.size_bytes)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
      <p className="border-t border-line px-5 py-2.5 text-xs text-muted">
        Para restaurar, el equipo técnico descifra la copia con <code className="rounded bg-bg px-1">php artisan escudo:backup:decrypt</code> y la carga con <code className="rounded bg-bg px-1">pg_restore</code>. Guarden una copia de las llaves fuera del servidor: sin ellas las copias no se pueden abrir.
      </p>
    </section>
  );
}
