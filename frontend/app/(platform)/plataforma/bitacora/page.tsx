"use client";

// SCR-AUD-01 · Bitácora de auditoría de la plataforma (docs/modulos/M-AUD.md).
// Quién hizo qué y cuándo en el panel (planes, cuentas, suscripciones, pagos) y lo que hizo el sistema solo
// (avisos, vencimientos, bloqueos). Es inalterable: se puede verificar que nadie la modificó.

import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { EmptyState, PageHeader, StatCard } from "@/components/ui/Display";
import { FilterBar, FilterSelect, SearchInput } from "@/components/ui/FilterBar";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";
import { formatDate } from "@/lib/accounts";
import { actionLabel, AUDIT_ACTIONS, type AuditEvent, describe, ENTITY_LABELS, FIELD_LABELS, formatValue, HIDDEN_FIELDS } from "@/lib/audit";

type Response = {
  data: AuditEvent[];
  meta: { total: number; page: number; per_page: number; pages: number };
  facets: { actions: { action: string; count: number }[]; actors: { id: string; name: string; count: number }[] };
  stats: { total: number; today: number; week: number; system: number };
};

type Verify = { ok: boolean; checked: number; problems: { chain_seq: number; problem: string }[]; verified_at: string };

const EMPTY = { q: "", action: "", actor: "", from: "", to: "" };
const today = () => new Date().toLocaleDateString("en-CA", { timeZone: "America/Lima" });
const daysAgo = (n: number) => new Date(Date.now() - n * 86_400_000).toLocaleDateString("en-CA", { timeZone: "America/Lima" });

export default function BitacoraPage() {
  const [res, setRes] = useState<Response | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [f, setF] = useState(EMPTY);
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState<AuditEvent | null>(null);
  const [verify, setVerify] = useState<Verify | null>(null);
  const [verifying, setVerifying] = useState(false);

  // La búsqueda espera a que se deje de escribir.
  useEffect(() => {
    const t = setTimeout(() => setF((cur) => (cur.q === q ? cur : { ...cur, q })), 350);
    return () => clearTimeout(t);
  }, [q]);

  useEffect(() => setPage(1), [f]);

  const load = useCallback(() => {
    setLoading(true);
    const params = new URLSearchParams(Object.entries({ ...f, page: String(page) }).filter(([, v]) => v));
    api<Response>(`/internal/audit?${params}`)
      .then((r) => {
        setRes(r);
        setError(null);
      })
      .catch((e) => setError(e instanceof ApiError ? (e.errors ? Object.values(e.errors)[0][0] : e.message) : "No pudimos cargar la bitácora."))
      .finally(() => setLoading(false));
  }, [f, page]);

  useEffect(load, [load]);

  async function runVerify() {
    setVerifying(true);
    try {
      setVerify((await api<{ data: Verify }>("/internal/audit/verify")).data);
    } finally {
      setVerifying(false);
    }
  }

  if (!res && error) return <p className="rounded-xl border border-bad/30 bg-bad-soft p-4 text-bad">{error}</p>;
  if (!res) return <div className="mx-auto h-96 max-w-7xl animate-pulse rounded-2xl bg-line/40" aria-label="Cargando bitácora" />;

  const isToday = f.from === today() && f.to === today() && !f.actor;
  const isWeek = f.from === daysAgo(6) && !f.to && !f.actor;
  const isSystem = f.actor === "system" && !f.from;
  const active = Object.values(f).some(Boolean);
  const clear = () => {
    setQ("");
    setF(EMPTY);
  };

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Bitácora de auditoría"]]}
        icon="history"
        title="Bitácora de auditoría"
        description="Quién cambió qué y cuándo. No se puede editar ni borrar."
        aside={
          <Button variant="secondary" icon="shield" loading={verifying} onClick={runVerify}>
            Verificar integridad
          </Button>
        }
      />

      {verify && (
        <div className={`flex items-start gap-3 rounded-2xl border px-4 py-3 ${verify.ok ? "border-ok/30 bg-ok-soft text-ok" : "border-bad/30 bg-bad-soft text-bad"}`} role="status">
          <Icon name={verify.ok ? "shield" : "lock"} size={20} className="mt-0.5 shrink-0" />
          <div className="text-sm">
            <p className="font-semibold">{verify.ok ? "La bitácora está intacta" : "Se encontraron alteraciones en la bitácora"}</p>
            <p>
              {verify.ok
                ? `Se revisaron ${verify.checked} eventos: ninguno fue modificado, borrado ni cambiado de orden.`
                : verify.problems.map((p) => `Evento n.° ${p.chain_seq}: ${p.problem}`).join(" · ")}{" "}
              <span className="opacity-80">Verificado el {formatDate(verify.verified_at, true)}</span>
            </p>
          </div>
        </div>
      )}

      <section aria-label="Resumen" className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Todos los eventos" value={res.stats.total} hint="Desde el inicio" icon="history" active={!active} onClick={clear} />
        <StatCard
          label="Hoy"
          value={res.stats.today}
          hint="Hora de Lima"
          icon="clock"
          tone="ok"
          active={isToday}
          onClick={() => setF(isToday ? EMPTY : { ...EMPTY, from: today(), to: today() })}
        />
        <StatCard
          label="Últimos 7 días"
          value={res.stats.week}
          hint="Esta semana"
          icon="grid"
          tone="amber"
          active={isWeek}
          onClick={() => setF(isWeek ? EMPTY : { ...EMPTY, from: daysAgo(6) })}
        />
        <StatCard
          label="Hechos por el sistema"
          value={res.stats.system}
          hint="Avisos, vencimientos y bloqueos"
          icon="mail"
          tone="na"
          active={isSystem}
          onClick={() => setF(isSystem ? EMPTY : { ...EMPTY, actor: "system" })}
        />
      </section>

      <FilterBar count={`${res.meta.total} ${res.meta.total === 1 ? "evento" : "eventos"}`} onClear={active ? clear : undefined}>
        <SearchInput label="Buscar en la bitácora" placeholder="Empresa, motivo, persona…" value={q} onChange={setQ} />
        <FilterSelect
          label="Acción"
          value={f.action}
          onChange={(action) => setF({ ...f, action })}
          options={[["", "Todas las acciones"], ...res.facets.actions.map((a) => [a.action, `${actionLabel(a.action)} (${a.count})`] as [string, string])]}
        />
        <FilterSelect
          label="Quién"
          value={f.actor}
          onChange={(actor) => setF({ ...f, actor })}
          options={[["", "Todos"], ...res.facets.actors.map((a) => [a.id, `${a.name} (${a.count})`] as [string, string])]}
        />
        <label className="flex min-w-0 flex-col gap-1.5 lg:w-40">
          <span className="text-xs font-semibold text-muted">Desde</span>
          <input type="date" className={`filter-control ${f.from ? "is-active" : ""}`} max={f.to || today()} value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
        </label>
        <label className="flex min-w-0 flex-col gap-1.5 lg:w-40">
          <span className="text-xs font-semibold text-muted">Hasta</span>
          <input type="date" className={`filter-control ${f.to ? "is-active" : ""}`} min={f.from || undefined} max={today()} value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
        </label>
      </FilterBar>

      {error && <p className="rounded-xl border border-bad/30 bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>}

      <section className={`overflow-hidden rounded-2xl border border-line bg-surface shadow-sm transition-opacity ${loading ? "opacity-60" : ""}`} aria-busy={loading}>
        {res.data.length === 0 ? (
          <EmptyState text="Ningún evento coincide con los filtros." onClear={active ? clear : undefined} />
        ) : (
          <ul className="divide-y divide-line">
            {res.data.map((e) => {
              const meta = AUDIT_ACTIONS[e.action] ?? { icon: "file", tone: "na" as const };
              return (
                <li key={e.id}>
                  <button type="button" onClick={() => setOpen(e)} className="grid w-full gap-x-4 gap-y-1 px-4 py-3 text-left hover:bg-bg/60 sm:px-5 md:grid-cols-[2.25rem_minmax(0,1fr)_12rem_9rem] md:items-center">
                    <span className="hidden h-9 w-9 place-items-center rounded-full bg-bg text-brand md:grid">
                      <Icon name={meta.icon} size={16} />
                    </span>
                    <div className="min-w-0">
                      <p className="flex flex-wrap items-center gap-2">
                        <span className="font-semibold">{actionLabel(e.action)}</span>
                        {e.reason && <span className="truncate text-xs italic text-muted">«{e.reason}»</span>}
                      </p>
                      <p className="truncate text-sm text-muted">{describe(e) || ENTITY_LABELS[e.entity_type] || e.entity_type}</p>
                    </div>
                    <span className="flex items-center gap-2 text-sm">
                      <span className={`grid h-6 w-6 shrink-0 place-items-center rounded-full text-[10px] font-bold ${e.actor ? "bg-brand-soft text-brand-strong" : "bg-na-soft text-muted"}`}>
                        {e.actor ? e.actor.split(" ").slice(0, 2).map((w) => w[0]).join("") : <Icon name="clock" size={12} />}
                      </span>
                      <span className="truncate">{e.actor ?? "Sistema"}</span>
                    </span>
                    <span className="text-xs text-muted md:text-right">{formatDate(e.occurred_at, true)}</span>
                  </button>
                </li>
              );
            })}
          </ul>
        )}
        {res.meta.pages > 1 && (
          <div className="flex items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm sm:px-5">
            <span className="text-muted">
              Página {res.meta.page} de {res.meta.pages}
            </span>
            <div className="flex gap-2">
              <Button variant="secondary" size="sm" icon="back" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                Anterior
              </Button>
              <Button variant="secondary" size="sm" disabled={page >= res.meta.pages} onClick={() => setPage((p) => p + 1)}>
                Siguiente
                <Icon name="chevron" size={15} />
              </Button>
            </div>
          </div>
        )}
      </section>

      {open && <EventModal event={open} onClose={() => setOpen(null)} />}
    </div>
  );
}

function EventModal({ event: e, onClose }: { event: AuditEvent; onClose: () => void }) {
  const c = e.changes ?? {};
  const before = (c.before ?? null) as Record<string, unknown> | null;
  const after = (c.after ?? null) as Record<string, unknown> | null;
  const diffKeys = [...new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})])];
  const rest = Object.entries(c).filter(([k]) => !HIDDEN_FIELDS.includes(k));

  return (
    <Modal
      title={actionLabel(e.action)}
      subtitle={`${formatDate(e.occurred_at, true)} · ${e.actor ?? "Sistema (automático)"}`}
      icon={AUDIT_ACTIONS[e.action]?.icon ?? "file"}
      onClose={onClose}
      footer={
        <Button variant="secondary" onClick={onClose}>
          Cerrar
        </Button>
      }
    >
      <div className="space-y-5">
        <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line text-sm sm:grid-cols-4">
          {(
            [
              ["Evento n.°", String(e.chain_seq)],
              ["Qué", ENTITY_LABELS[e.entity_type] ?? e.entity_type],
              ["Módulo", e.module_code ?? "—"],
              ["Quién", e.actor ?? "Sistema"],
            ] as const
          ).map(([k, v]) => (
            <div key={k} className="min-w-0 bg-surface px-3 py-2.5">
              <dt className="text-[11px] font-semibold uppercase tracking-wide text-muted">{k}</dt>
              <dd className="truncate font-semibold">{v}</dd>
            </div>
          ))}
        </dl>

        {e.reason && (
          <p className="rounded-lg bg-bg px-3 py-2 text-sm">
            <b>Motivo:</b> {e.reason}
          </p>
        )}

        {diffKeys.length > 0 && (
          <section>
            <h3 className="mb-2 text-sm font-bold uppercase tracking-wide">Antes y después</h3>
            <div className="overflow-hidden rounded-xl border border-line">
              <table className="w-full text-sm">
                <thead className="bg-bg text-left text-xs uppercase tracking-wide text-muted">
                  <tr>
                    <th className="px-3 py-2">Campo</th>
                    <th className="px-3 py-2">Antes</th>
                    <th className="px-3 py-2">Después</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {diffKeys.map((k) => {
                    const a = formatValue(before?.[k]);
                    const b = formatValue(after?.[k]);
                    return (
                      <tr key={k}>
                        <td className="px-3 py-2 font-medium">{FIELD_LABELS[k] ?? k}</td>
                        <td className={`px-3 py-2 ${a !== b ? "text-bad line-through decoration-bad/40" : "text-muted"}`}>{a}</td>
                        <td className={`px-3 py-2 ${a !== b ? "font-semibold text-ok" : "text-muted"}`}>{b}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </section>
        )}

        {rest.length > 0 && (
          <section>
            <h3 className="mb-2 text-sm font-bold uppercase tracking-wide">Detalle</h3>
            <dl className="divide-y divide-line rounded-xl border border-line text-sm">
              {rest.map(([k, v]) => (
                <div key={k} className="grid gap-1 px-3 py-2 sm:grid-cols-[10rem_minmax(0,1fr)]">
                  <dt className="text-muted">{FIELD_LABELS[k] ?? k.replace(/_/g, " ")}</dt>
                  <dd className="break-words">{formatValue(v)}</dd>
                </div>
              ))}
            </dl>
          </section>
        )}

        <p className="flex items-start gap-2 text-xs text-muted">
          <Icon name="shield" size={14} className="mt-0.5 shrink-0" />
          Este evento está encadenado por hash con el anterior: si alguien lo modificara, «Verificar integridad» lo detectaría.
        </p>
      </div>
    </Modal>
  );
}

