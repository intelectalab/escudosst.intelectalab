"use client";

// Copias de seguridad en «Salud del sistema» (docs/15 §9): estado por base, últimas copias y «Hacer copia ahora».
// Las copias se cifran en el servidor; sin la llave (guardada fuera del servidor) no se pueden abrir.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip } from "@/components/ui/Display";
import { Icon } from "@/components/ui/Icon";
import { api } from "@/lib/api";

type Target = { target: string; label: string; last_ok_at: string | null; last_size_bytes: number | null; last_status: string | null; last_error: string | null; overdue: boolean };
type Run = { id: string; target: string; trigger: string; status: "running" | "ok" | "failed"; started_at: string; finished_at: string | null; size_bytes: number | null; error: string | null; deleted_at: string | null; requested_by: string | null; remote_status: "skipped" | "ok" | "failed" | null; remote_uploaded_at: string | null; remote_deleted_at: string | null };
type Remote = { configured: boolean; source?: "panel" | "env" | null; destination: string | null; retention_days: number; last_upload_at: string | null; last_status: "ok" | "failed" | null; last_error: string | null; message: string | null };
type RestoreTarget = { target: string; label: string; last_ok_at: string | null; last_ok_duration_ms: number | null; last_status: "running" | "ok" | "failed" | null; last_error: string | null; overdue: boolean };
type RestoreCheck = { key: string; label: string; ok: boolean };
type RestoreTest = { id: string; target: string; trigger: string; status: "running" | "ok" | "failed"; started_at: string; finished_at: string | null; duration_ms: number | null; tables_count: number | null; rls_tables_count: number | null; migrations_count: number | null; row_counts: Record<string, number>; checks: RestoreCheck[]; error: string | null; backup_started_at: string | null; requested_by: string | null };
type Restore = { targets: RestoreTarget[]; tests: RestoreTest[]; config: { weekly: string; max_age_days: number; ready: boolean } };
type Status = { targets: Target[]; runs: Run[]; config: { disk: string; retention_days: number; daily_at: string; max_age_hours: number; keys_ready: boolean }; remote?: Remote; restore?: Restore };

const size = (b: number | null) => (b == null ? "–" : b > 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${(b / 1024).toFixed(0)} KB`);
const when = (d: string | null) => (d ? new Date(d).toLocaleString("es-PE", { dateStyle: "short", timeStyle: "short" }) : "nunca");
const secs = (ms: number | null) => (ms == null ? "–" : ms >= 60000 ? `${(ms / 60000).toFixed(1)} min` : `${(ms / 1000).toFixed(1)} s`);

export function BackupsSection({ onToast }: { onToast: (t: string) => void }) {
  const me = usePlatformUser();
  const [s, setS] = useState<Status | null>(null);
  const [busy, setBusy] = useState(false);
  const [testing, setTesting] = useState(false);

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

  async function restoreNow() {
    setTesting(true);
    try {
      await api("/internal/backups/restore-test", { method: "POST", body: { target: "all" } });
      onToast("Prueba de restauración en marcha. Puede tardar unos minutos; el resultado aparece abajo.");
      window.setTimeout(load, 15000);
      window.setTimeout(load, 60000);
      window.setTimeout(load, 180000);
    } catch {
      onToast("No se pudo iniciar la prueba de restauración.");
    } finally {
      window.setTimeout(() => setTesting(false), 15000);
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

      {s.remote && (
        <div className={`mx-4 mb-4 flex flex-wrap items-start gap-3 rounded-xl border p-3 text-sm ${!s.remote.configured ? "border-line bg-bg/60" : s.remote.last_status === "failed" ? "border-bad/30 bg-bad-soft/50" : "border-ok/30 bg-ok-soft/40"}`}>
          <Icon name="server" size={18} className={`mt-0.5 shrink-0 ${!s.remote.configured ? "text-muted" : s.remote.last_status === "failed" ? "text-bad" : "text-ok"}`} />
          <span className="min-w-0 flex-1">
            <b>Copia externa: {s.remote.configured ? "activa" : "sin configurar"}</b>
            {s.remote.configured ? (
              <span className="block text-muted">
                {s.remote.destination} · última subida: <b className="text-fg">{when(s.remote.last_upload_at)}</b> · se guardan {s.remote.retention_days} días
                {s.remote.source === "env" ? " · configurada en el servidor (BACKUP_S3_*)" : ""}
              </span>
            ) : (
              <span className="block text-muted">
                {s.remote.message ?? "Las copias solo quedan en el servidor."} Actívala en{" "}
                <Link href="/plataforma/proveedores" className="font-semibold text-brand hover:underline">Proveedores y claves › Copias de seguridad externas</Link>.
              </span>
            )}
            {s.remote.configured && s.remote.last_status === "failed" && <span className="mt-1 block text-xs text-bad">La última subida falló: {s.remote.last_error}</span>}
          </span>
        </div>
      )}

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

      <div className="max-h-72 overflow-y-auto" tabIndex={0} role="region" aria-label="Copias de seguridad">
        <table className="w-full text-sm">
          <thead className="sticky top-0 bg-surface text-left text-xs uppercase tracking-wide text-muted">
            <tr className="border-b border-line">
              <th className="px-5 py-2 font-semibold">Fecha</th>
              <th className="px-3 py-2 font-semibold">Base</th>
              <th className="px-3 py-2 font-semibold">Origen</th>
              <th className="px-3 py-2 font-semibold">Resultado</th>
              <th className="px-3 py-2 font-semibold">Copia externa</th>
              <th className="px-5 py-2 text-right font-semibold">Tamaño</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {s.runs.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-5 py-6 text-center text-muted">
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
                  <td className="px-3 py-2 text-xs text-muted">
                    {r.remote_status === "ok" ? (r.remote_deleted_at ? "Borrada por antigüedad" : `Subida ${when(r.remote_uploaded_at)}`) : r.remote_status === "failed" ? <span className="text-bad">No se subió</span> : r.remote_status === "skipped" ? "Sin configurar" : "–"}
                  </td>
                  <td className="px-5 py-2 text-right tabular-nums">{size(r.size_bytes)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
      {s.restore && (
        <div className="border-t border-line" aria-label="Pruebas de restauración">
          <div className="flex flex-wrap items-center justify-between gap-3 px-5 pt-4">
            <div className="min-w-0">
              <h3 className="flex items-center gap-2 font-display font-semibold">
                <Icon name="history" size={16} className="text-brand" /> Pruebas de restauración
              </h3>
              <p className="text-xs text-muted">
                La última copia buena se restaura en una base temporal, se revisa (tablas, RLS, migraciones y filas de tablas clave) y se borra. {s.restore.config.weekly} (hora de Lima).
                {!s.restore.config.ready && " ⚠ Falta BACKUP_RESTORE_DB_PASSWORD en el servidor."}
              </p>
            </div>
            {me.platform_role !== "support" && (
              <Button size="sm" variant="secondary" icon="refresh" loading={testing} onClick={restoreNow}>
                Probar restauración ahora
              </Button>
            )}
          </div>
          <div className="grid grid-cols-1 gap-3 p-4 md:grid-cols-2">
            {s.restore.targets.map((t) => {
              const bad = t.last_status === "failed" || t.overdue;
              return (
                <div key={t.target} className={`rounded-xl border p-3 text-sm ${bad ? "border-bad/30 bg-bad-soft/50" : "border-ok/30 bg-ok-soft/40"}`}>
                  <b className="block">{t.label}</b>
                  <span className="block text-muted">
                    Última prueba buena: <b className="text-fg">{when(t.last_ok_at)}</b>
                    {t.last_ok_duration_ms != null ? ` · tardó ${secs(t.last_ok_duration_ms)}` : ""}
                  </span>
                  {t.last_status === "failed" ? (
                    <span className="mt-1 block text-xs text-bad">La última falló: {t.last_error}</span>
                  ) : t.overdue ? (
                    <span className="mt-1 block text-xs text-bad">
                      Vencida: no hay una prueba buena en los últimos {s.restore?.config.max_age_days} días. Sin prueba vigente no se publica una versión.
                    </span>
                  ) : null}
                </div>
              );
            })}
          </div>
          <div className="max-h-72 overflow-y-auto" tabIndex={0} role="region" aria-label="Historial de pruebas de restauración">
            <table className="w-full text-sm">
              <thead className="sticky top-0 bg-surface text-left text-xs uppercase tracking-wide text-muted">
                <tr className="border-b border-line">
                  <th className="px-5 py-2 font-semibold">Fecha</th>
                  <th className="px-3 py-2 font-semibold">Base</th>
                  <th className="px-3 py-2 font-semibold">Copia probada</th>
                  <th className="px-3 py-2 font-semibold">Resultado</th>
                  <th className="px-3 py-2 font-semibold">Revisión</th>
                  <th className="px-5 py-2 text-right font-semibold">Duración</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {s.restore.tests.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-5 py-6 text-center text-muted">
                      Todavía no hay pruebas de restauración.
                    </td>
                  </tr>
                ) : (
                  s.restore.tests.map((t) => (
                    <tr key={t.id}>
                      <td className="px-5 py-2 tabular-nums">
                        {when(t.started_at)}
                        <span className="block text-xs text-muted">{t.trigger === "manual" ? `A mano${t.requested_by ? ` · ${t.requested_by}` : ""}` : "Automática"}</span>
                      </td>
                      <td className="px-3 py-2">{t.target === "medical" ? "Médica" : "Principal"}</td>
                      <td className="px-3 py-2 tabular-nums text-muted">{when(t.backup_started_at)}</td>
                      <td className="px-3 py-2">
                        {t.status === "ok" ? <Chip tone="ok">Restaurada</Chip> : t.status === "running" ? <Chip tone="amber">En curso</Chip> : <Chip tone="bad">Falló</Chip>}
                        {t.status === "failed" && t.error && <span className="mt-1 block text-xs text-bad">{t.error}</span>}
                      </td>
                      <td className="px-3 py-2 text-xs text-muted">
                        {t.tables_count != null ? (
                          <>
                            {t.tables_count} tablas · {t.rls_tables_count} con RLS{t.migrations_count != null ? ` · ${t.migrations_count} migraciones` : ""}
                            {Object.keys(t.row_counts).length > 0 && (
                              <span className="block">
                                Filas: {Object.entries(t.row_counts).map(([k, v]) => `${k} ${v.toLocaleString("es-PE")}`).join(" · ")}
                              </span>
                            )}
                          </>
                        ) : (
                          t.checks.filter((c) => !c.ok).map((c) => c.label).join(" · ") || "–"
                        )}
                      </td>
                      <td className="px-5 py-2 text-right tabular-nums">{secs(t.duration_ms)}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <p className="border-t border-line px-5 py-2.5 text-xs text-muted">
        Para restaurar, el equipo técnico descifra la copia con <code className="rounded bg-bg px-1">php artisan escudo:backup:decrypt</code> y la carga con <code className="rounded bg-bg px-1">pg_restore</code>. Guarden una copia de las llaves fuera del servidor: sin ellas las copias no se pueden abrir.
      </p>
    </section>
  );
}
