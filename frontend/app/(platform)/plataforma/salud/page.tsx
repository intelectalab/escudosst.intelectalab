"use client";

// Salud del sistema (panel del dueño, docs/03 §11): estado de cada parte, correos enviados y trabajos fallidos.
// Se actualiza sola cada 30 segundos.

import { useCallback, useEffect, useState } from "react";
import { BackupsSection } from "@/components/platform/BackupsSection";
import { Button } from "@/components/ui/Button";
import { ColumnChart, SERIES_COLORS } from "@/components/ui/Charts";
import { Chip, type ChipTone, EmptyState, PageHeader, StatCard } from "@/components/ui/Display";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { formatDate } from "@/lib/accounts";

type Status = "ok" | "warning" | "error" | "off";
type Check = {
  key: string;
  name: string;
  group: "infra" | "process" | "comms";
  icon: string;
  status: Status;
  detail: string;
  help: string;
  fix: string;
  latency_ms?: number | null;
  at?: string | null;
  action?: string;
};
type Health = {
  environment: string;
  checks: Check[];
  mail_days: { day: string; sent: number; failed: number }[];
  last_mail_error: { at: string; error: string } | null;
  failed_jobs: { uuid: string; queue: string; failed_at: string; error: string }[];
  checked_at: string;
};

const STATUS: Record<Status, { label: string; tone: ChipTone; tile: string; box: string; meaning: string }> = {
  ok: { label: "Funciona", tone: "ok", tile: "bg-ok-soft text-ok", box: "", meaning: "Funciona bien." },
  warning: {
    label: "Revisar",
    tone: "amber",
    tile: "bg-amber-soft text-amber-ink",
    box: "bg-amber-soft text-amber-ink",
    meaning: "Funciona, pero algo no está como debería.",
  },
  error: { label: "Con error", tone: "bad", tile: "bg-bad-soft text-bad", box: "bg-bad-soft text-bad", meaning: "No funciona o está por fallar: hay que atenderlo." },
  off: { label: "Sin configurar", tone: "na", tile: "bg-na-soft text-muted", box: "bg-bg text-muted", meaning: "Todavía no se configura: depende de una decisión o del servidor." },
};

const GROUPS: [Check["group"], string][] = [
  ["infra", "Infraestructura"],
  ["process", "Procesos automáticos"],
  ["comms", "Comunicaciones y servicios externos"],
];

const dayLabel = (d: string) => new Intl.DateTimeFormat("es-PE", { weekday: "short", day: "numeric", timeZone: "UTC" }).format(new Date(`${d}T00:00:00Z`));

export default function SaludPage() {
  const [h, setH] = useState<Health | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<Status | null>(null);
  const [loading, setLoading] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const [running, setRunning] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const closeToast = useCallback(() => setToast(null), []);

  const load = useCallback(() => {
    setLoading(true);
    api<{ data: Health }>("/internal/health")
      .then((r) => {
        setH(r.data);
        setError(null);
      })
      .catch((e) => setError(e instanceof ApiError ? e.message : "No pudimos revisar el sistema."))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    load();
    const t = setInterval(load, 30_000);
    return () => clearInterval(t);
  }, [load]);

  async function runDaily() {
    setRunning(true);
    try {
      const r = await api<{ data: { reminders: number; past_due: number; trial_expired: number; blocked: number } }>("/internal/health/run-daily", { method: "POST" });
      const s = r.data;
      setToast(`Revisión lista: ${s.reminders} avisos enviados, ${s.past_due} pasaron a pago pendiente, ${s.trial_expired} pruebas vencieron, ${s.blocked} quedaron en solo lectura.`);
      setConfirm(false);
      load();
    } catch (e) {
      setToast(e instanceof ApiError ? e.message : "No se pudo correr el proceso.");
    } finally {
      setRunning(false);
    }
  }

  if (!h && error) return <p className="rounded-xl border border-bad/30 bg-bad-soft p-4 text-bad">{error}</p>;
  if (!h) return <div className="mx-auto h-96 max-w-7xl animate-pulse rounded-2xl bg-line/40" aria-label="Revisando el sistema" />;

  const count = (s: Status) => h.checks.filter((c) => c.status === s).length;
  const problems = count("error");
  const shown = h.checks.filter((c) => !filter || c.status === filter);
  const toggle = (s: Status) => setFilter((f) => (f === s ? null : s));
  const sentWeek = h.mail_days.reduce((n, d) => n + d.sent, 0);
  const failedWeek = h.mail_days.reduce((n, d) => n + d.failed, 0);

  return (
    <div className="mx-auto w-full max-w-[1600px] space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Salud del sistema"]]}
        icon="heart"
        title="Salud del sistema"
        description="Estado de cada parte de EscudoSST. Se revisa sola cada 30 segundos."
        aside={
          <Button variant="secondary" icon="refresh" loading={loading} onClick={load} title="Vuelve a medir todo ahora mismo">
            Actualizar estado
          </Button>
        }
      />

      {h.environment === "local" && (
        <div className="flex items-start gap-3 rounded-2xl border border-brand/30 bg-brand-soft/60 px-4 py-3 text-sm text-brand-strong">
          <Icon name="globe" size={18} className="mt-0.5 shrink-0" />
          <p>
            <b>Estás viendo el entorno de desarrollo (tu computadora).</b> Cuando EscudoSST esté en el servidor, esta misma pantalla medirá el disco del servidor, los archivos en Backblaze B2, los
            trabajadores de la cola, el correo de Elastic Email y los respaldos. Lo que hoy dice «Sin configurar» se activa al subirlo.
          </p>
        </div>
      )}

      <div className={`flex items-center gap-3 rounded-2xl border px-4 py-3 ${problems ? "border-bad/30 bg-bad-soft text-bad" : "border-ok/30 bg-ok-soft text-ok"}`} role="status">
        <Icon name={problems ? "lock" : "check"} size={20} className="shrink-0" />
        <p className="text-sm">
          <b>{problems ? `${problems} ${problems === 1 ? "parte tiene" : "partes tienen"} un error.` : "Todo lo configurado funciona."}</b>{" "}
          <span className="opacity-80">Revisado el {formatDate(h.checked_at, true)}.</span>
        </p>
      </div>

      <section aria-label="Resumen" className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Funcionan" value={count("ok")} hint="Sin problemas" icon="check" tone="ok" active={filter === "ok"} onClick={() => toggle("ok")} />
        <StatCard label="Para revisar" value={count("warning")} hint="Advertencias" icon="clock" tone="amber" active={filter === "warning"} onClick={() => toggle("warning")} />
        <StatCard label="Con error" value={problems} hint="Requieren atención" icon="lock" tone="bad" active={filter === "error"} onClick={() => toggle("error")} />
        <StatCard label="Sin configurar" value={count("off")} hint="Pendientes del equipo" icon="box" tone="na" active={filter === "off"} onClick={() => toggle("off")} />
      </section>

      <BackupsSection onToast={(t) => setToast(t)} />

      {shown.length === 0 && (
        <div className="rounded-2xl border border-line bg-surface">
          <EmptyState icon="check" text="Nada en este estado." onClear={() => setFilter(null)} />
        </div>
      )}

      {GROUPS.map(([g, title]) => {
        const items = shown.filter((c) => c.group === g);
        if (!items.length) return null;
        return (
          <section key={g} className="space-y-3">
            <h2 className="font-display text-lg font-semibold">{title}</h2>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              {items.map((c) => (
                <article key={c.key} className="flex gap-3 rounded-2xl border border-line bg-surface p-4 shadow-sm">
                  <span className={`grid h-11 w-11 shrink-0 place-items-center rounded-xl ${STATUS[c.status].tile}`}>
                    <Icon name={c.icon} size={20} />
                  </span>
                  <div className="min-w-0 flex-1 space-y-1.5">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <h3 className="font-semibold">{c.name}</h3>
                      <span title={STATUS[c.status].meaning}>
                        <Chip tone={STATUS[c.status].tone} dot>
                          {STATUS[c.status].label}
                        </Chip>
                      </span>
                    </div>
                    <p className="text-xs text-muted">{c.help}</p>
                    <p className="text-sm font-medium">{c.detail}</p>
                    {c.status !== "ok" && (
                      <p className={`rounded-lg px-2.5 py-1.5 text-xs ${STATUS[c.status].box}`}>
                        <b>Qué hacer:</b> {c.fix}
                      </p>
                    )}
                    <p className="flex flex-wrap gap-x-3 text-xs text-muted">
                      {c.latency_ms != null && <span>Responde en {c.latency_ms} ms</span>}
                      {c.at && <span>Última vez: {formatDate(c.at, true)}</span>}
                    </p>
                    {c.action === "run_daily" && (
                      <Button variant="soft" size="sm" icon="refresh" className="mt-1" onClick={() => setConfirm(true)}>
                        Revisar vencimientos ahora
                      </Button>
                    )}
                  </div>
                </article>
              ))}
            </div>
          </section>
        );
      })}

      {!filter && (
        <section className="grid gap-5 lg:grid-cols-2">
          <div className="rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-5">
            <h2 className="font-display text-lg font-semibold">Correos enviados</h2>
            <p className="mb-4 text-sm text-muted">
              Últimos 7 días: {sentWeek} enviados · {failedWeek} fallidos
            </p>
            <ColumnChart
              label="Correos enviados por día"
              color={SERIES_COLORS[0]}
              data={h.mail_days.map((d) => ({
                key: d.day,
                label: dayLabel(d.day),
                value: d.sent,
                display: String(d.sent),
                detail: `${d.sent} enviados · ${d.failed} fallidos`,
                color: d.failed ? SERIES_COLORS[7] : undefined,
              }))}
            />
            {h.last_mail_error && (
              <p className="mt-3 rounded-lg bg-bad-soft px-3 py-2 text-xs text-bad">
                <b>Último error ({formatDate(h.last_mail_error.at, true)}):</b> {h.last_mail_error.error}
              </p>
            )}
          </div>

          <div className="rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-5">
            <h2 className="font-display text-lg font-semibold">Trabajos fallidos</h2>
            <p className="mb-4 text-sm text-muted">Los 10 más recientes de la cola</p>
            {h.failed_jobs.length === 0 ? (
              <EmptyState icon="check" text="No hay trabajos fallidos." />
            ) : (
              <ul className="divide-y divide-line rounded-xl border border-line">
                {h.failed_jobs.map((j) => (
                  <li key={j.uuid} className="px-3 py-2.5 text-sm">
                    <p className="flex justify-between gap-2">
                      <b>Cola «{j.queue}»</b>
                      <span className="text-xs text-muted">{formatDate(j.failed_at, true)}</span>
                    </p>
                    <p className="truncate text-xs text-bad" title={j.error}>
                      {j.error}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </section>
      )}

      {confirm && (
        <Modal
          title="Revisar vencimientos ahora"
          subtitle="Sin esperar a las 7:00 a. m."
          icon="refresh"
          size="sm"
          onClose={() => setConfirm(false)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setConfirm(false)}>
                Cancelar
              </Button>
              <Button variant="primary" icon="refresh" loading={running} onClick={runDaily}>
                Revisar ahora
              </Button>
            </>
          }
        >
          <div className="space-y-3 text-sm">
            <p>
              Todos los días a las <b>7:00 a. m.</b> el sistema revisa solo qué planes vencen. Con este botón haces esa misma revisión <b>ahora mismo</b>, sin esperar.
            </p>
            <ul className="space-y-1.5">
              <li className="flex gap-2">
                <Icon name="mail" size={15} className="mt-0.5 shrink-0 text-brand" />
                Envía los avisos que tocan hoy (5 días, 3 días y el mismo día del vencimiento).
              </li>
              <li className="flex gap-2">
                <Icon name="clock" size={15} className="mt-0.5 shrink-0 text-amber-ink" />
                Pasa a «Pago pendiente» las empresas cuyo periodo ya venció.
              </li>
              <li className="flex gap-2">
                <Icon name="lock" size={15} className="mt-0.5 shrink-0 text-bad" />
                Deja en solo lectura a quienes terminó la gracia sin pagar.
              </li>
            </ul>
            <p className="rounded-lg bg-bg px-3 py-2 text-xs text-muted">
              Es seguro presionarlo varias veces: nunca repite un aviso ya enviado ni bloquea a quien ya pagó. Queda registrado en la bitácora.
            </p>
          </div>
        </Modal>
      )}
      {toast && <Toast message={toast} onClose={closeToast} />}
    </div>
  );
}
