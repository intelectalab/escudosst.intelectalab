"use client";

// SCR-SUS-09 · Métricas del panel del dueño (docs/03 §11): altas, pruebas, conversión, bajas y uso por módulo.
// Solo agregados. Las tarjetas y barras llevan a la lista filtrada correspondiente.

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { BarList, ColumnChart, SERIES_COLORS } from "@/components/ui/Charts";
import {
  Chip,
  Code,
  EmptyState,
  PageHeader,
  StatCard,
} from "@/components/ui/Display";
import { SearchInput } from "@/components/ui/FilterBar";
import { Icon } from "@/components/ui/Icon";
import { api, ApiError } from "@/lib/api";
import { formatDate, formatMoney } from "@/lib/accounts";
import { CATALOG_GROUPS, PLAN_ACCENT } from "@/lib/plans";

type Metrics = {
  companies: number;
  accounts: number;
  by_status: Record<string, number>;
  by_plan: { code: string; name: string; companies: number }[];
  trials: {
    started: number;
    active: number;
    expired: number;
    converted: number;
    list: {
      subscription_id: string;
      trial_ends_at: string;
      account_id: string;
      account_name: string;
      company_name: string;
      ruc: string;
      headcount_declared: number | null;
      days_left: number;
    }[];
  };
  months: {
    month: string;
    signups: number;
    cancellations: number;
    blocked: number;
    revenue: number;
  }[];
  modules: {
    code: string;
    name: string;
    group: number;
    enabled: number;
    read_only: number;
    companies: {
      name: string;
      account_id: string;
      mode: "enabled" | "read_only";
    }[];
  }[];
  addons: { code: string; name: string; companies: number }[];
};

const shortMonth = (k: string) =>
  new Intl.DateTimeFormat("es-PE", { month: "short", timeZone: "UTC" })
    .format(new Date(`${k}-01T00:00:00Z`))
    .replace(".", "");
const longMonth = (k: string) =>
  new Intl.DateTimeFormat("es-PE", {
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(`${k}-01T00:00:00Z`));

export default function MetricasPage() {
  const router = useRouter();
  const [m, setM] = useState<Metrics | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [q, setQ] = useState("");
  const [allModules, setAllModules] = useState(false);
  const [group, setGroup] = useState<number | null>(null);

  useEffect(() => {
    api<{ data: Metrics }>("/internal/metrics")
      .then((r) => setM(r.data))
      .catch((e) =>
        setError(
          e instanceof ApiError ? e.message : "No pudimos cargar las métricas.",
        ),
      );
  }, []);

  const modules = useMemo(() => {
    if (!m) return [];
    const t = q.trim().toLowerCase();
    return m.modules.filter(
      (x) =>
        (group === null || x.group === group) &&
        (!t ||
          x.name.toLowerCase().includes(t) ||
          x.code.toLowerCase().includes(t)),
    );
  }, [m, q, group]);

  if (error)
    return (
      <p className="rounded-xl border border-bad/30 bg-bad-soft p-4 text-bad">
        {error}
      </p>
    );
  if (!m)
    return (
      <div
        className="mx-auto h-96 max-w-7xl animate-pulse rounded-2xl bg-line/40"
        aria-label="Cargando métricas"
      />
    );

  const s = (k: string) => m.by_status[k] ?? 0;
  const rate = m.trials.started
    ? Math.round((m.trials.converted / m.trials.started) * 100)
    : null;
  const thisMonth = m.months[m.months.length - 1];
  const signups6 = m.months.reduce((n, x) => n + x.signups, 0);
  const losses6 = m.months.reduce((n, x) => n + x.cancellations + x.blocked, 0);
  const shownModules =
    allModules || q || group !== null ? modules : modules.slice(0, 12);
  const goAccounts = (params: string) =>
    router.push(`/plataforma/cuentas?${params}`);

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Métricas"]]}
        icon="grid"
        title="Métricas"
        description="Altas, pruebas, ingresos y módulos más contratados."
      />

      <section
        aria-label="Resumen"
        className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4"
      >
        <StatCard
          label="Empresas activas"
          value={s("active")}
          hint={`De ${m.companies} empresas en ${m.accounts} cuentas`}
          icon="building"
          tone="ok"
          onClick={() => goAccounts("estado=active")}
        />
        <StatCard
          label="En prueba ahora"
          value={m.trials.active}
          hint="Ver quiénes y cuántos días les quedan"
          icon="clock"
          onClick={() =>
            document
              .getElementById("pruebas")
              ?.scrollIntoView({ behavior: "smooth" })
          }
        />
        <StatCard
          label="Conversión de pruebas"
          value={rate === null ? "—" : `${rate}%`}
          hint={`${m.trials.converted} de ${m.trials.started} pruebas pagaron`}
          icon="check"
          tone="brand"
          onClick={() =>
            document
              .getElementById("pruebas")
              ?.scrollIntoView({ behavior: "smooth" })
          }
        />
        <StatCard
          label="Ingresos del mes"
          value={formatMoney(thisMonth?.revenue ?? 0)}
          hint={thisMonth ? longMonth(thisMonth.month) : ""}
          icon="file"
          tone="amber"
          onClick={() => router.push("/plataforma/facturacion")}
        />
      </section>

      <section className="grid gap-5 lg:grid-cols-2">
        <Panel
          title="Altas de empresas por mes"
          hint={`${signups6} en los últimos 6 meses · ${losses6} bajas o bloqueos`}
        >
          <ColumnChart
            label="Altas de empresas por mes"
            data={m.months.map((x) => ({
              key: x.month,
              label: shortMonth(x.month),
              value: x.signups,
              display: String(x.signups),
              detail: `${longMonth(x.month)} · ${x.cancellations} ${x.cancellations === 1 ? "baja" : "bajas"} · ${x.blocked} ${x.blocked === 1 ? "bloqueo" : "bloqueos"} por falta de pago`,
            }))}
          />
        </Panel>
        <Panel title="Ingresos por mes" hint="Pagos confirmados, en soles">
          <ColumnChart
            color={SERIES_COLORS[5]}
            label="Ingresos por mes en soles"
            data={m.months.map((x) => ({
              key: x.month,
              label: shortMonth(x.month),
              value: x.revenue,
              display: x.revenue ? `S/ ${Math.round(x.revenue)}` : "0",
              detail: `${longMonth(x.month)} · ${formatMoney(x.revenue)}`,
            }))}
          />
        </Panel>
      </section>

      <section className="grid gap-5 lg:grid-cols-2">
        <Panel
          title="Empresas por plan"
          hint="Presiona un plan para ver sus empresas"
        >
          <BarList
            label="Empresas por plan"
            data={m.by_plan.map((p) => ({
              key: p.code,
              label: p.name,
              value: p.companies,
              display: `${p.companies} ${p.companies === 1 ? "empresa" : "empresas"}`,
              color: PLAN_ACCENT[p.code]?.bar,
              detail: "Presiona para ver sus empresas",
            }))}
            onSelect={(d) => goAccounts(`plan=${d.key}`)}
          />
          <div className="mt-4 grid grid-cols-2 gap-2 border-t border-line pt-4 text-sm">
            {(
              [
                ["trialing", "En prueba", "brand"],
                ["past_due", "Pago pendiente", "amber"],
                ["read_only", "Suspendidas", "bad"],
                ["trial_expired", "Prueba vencida", "na"],
              ] as const
            ).map(([k, label, tone]) => (
              <button
                key={k}
                type="button"
                onClick={() => goAccounts(`estado=${k}`)}
                className="rounded-lg px-2 py-1.5 text-left hover:bg-bg"
              >
                <span className="block font-display text-lg font-bold">
                  {s(k)}
                </span>
                <Chip tone={tone} dot>
                  {label}
                </Chip>
              </button>
            ))}
          </div>
        </Panel>

        <Panel
          title="Complementos contratados"
          hint="Empresas con el complemento activo"
        >
          {m.addons.every((a) => a.companies === 0) ? (
            <EmptyState
              icon="box"
              text="Ninguna empresa tiene complementos todavía."
            />
          ) : (
            <BarList
              label="Complementos contratados"
              data={m.addons.map((a) => ({
                key: a.code,
                label: a.name,
                value: a.companies,
                display: String(a.companies),
                color: SERIES_COLORS[1],
                detail: `${a.companies} ${a.companies === 1 ? "empresa lo tiene" : "empresas lo tienen"} activo`,
              }))}
            />
          )}
        </Panel>
      </section>

      <Panel
        id="pruebas"
        title="Empresas en prueba"
        hint={`${m.trials.active} en prueba · ${m.trials.expired} pruebas vencidas sin contratar · ${m.trials.converted} convertidas`}
      >
        {m.trials.list.length === 0 ? (
          <EmptyState
            icon="clock"
            text="No hay empresas en prueba en este momento."
          />
        ) : (
          <ul className="divide-y divide-line rounded-xl border border-line">
            {m.trials.list.map((t) => (
              <li key={t.subscription_id}>
                <Link
                  href={`/plataforma/cuentas/${t.account_id}`}
                  className="grid gap-2 px-4 py-3 hover:bg-bg/60 sm:grid-cols-[minmax(0,1fr)_10rem_9rem] sm:items-center"
                >
                  <div className="min-w-0">
                    <p className="truncate font-medium">{t.company_name}</p>
                    <p className="flex flex-wrap items-center gap-2 text-xs text-muted">
                      <Code>RUC {t.ruc}</Code>
                      {t.headcount_declared !== null && (
                        <span>{t.headcount_declared} trabajadores</span>
                      )}
                    </p>
                  </div>
                  <span className="text-sm text-muted">
                    Termina {formatDate(t.trial_ends_at)}
                  </span>
                  <span>
                    <Chip tone={t.days_left <= 3 ? "amber" : "brand"} dot>
                      {t.days_left <= 0
                        ? "Termina hoy"
                        : `Quedan ${t.days_left} ${t.days_left === 1 ? "día" : "días"}`}
                    </Chip>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <Panel
        title="Uso por módulo"
        hint="Cuántas empresas tienen contratado cada módulo. Presiona un módulo para ver cuáles son."
      >
        <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
          <div className="w-full max-w-md">
            <SearchInput
              label="Buscar módulo"
              placeholder="Nombre o código del módulo"
              value={q}
              onChange={setQ}
            />
          </div>
        </div>
        {/* Leyenda: el color es el grupo del catálogo; presionar un grupo filtra */}
        <div
          className="mb-4 flex flex-wrap gap-2"
          role="group"
          aria-label="Grupos de módulos"
        >
          {Object.entries(CATALOG_GROUPS)
            .filter(([n]) => m.modules.some((x) => x.group === Number(n)))
            .map(([n, name]) => {
              const on = group === Number(n);
              return (
                <button
                  key={n}
                  type="button"
                  aria-pressed={on}
                  onClick={() => setGroup(on ? null : Number(n))}
                  className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold transition-colors ${
                    on
                      ? "border-transparent text-white shadow-md"
                      : "border-line text-muted hover:border-brand/40 hover:text-fg"
                  }`}
                  style={
                    on
                      ? { background: SERIES_COLORS[Number(n) - 1] }
                      : undefined
                  }
                >
                  <span
                    className="h-2.5 w-2.5 rounded-full"
                    style={{
                      background: on ? "#fff" : SERIES_COLORS[Number(n) - 1],
                    }}
                  />
                  {name}
                </button>
              );
            })}
        </div>
        {shownModules.length === 0 ? (
          <EmptyState
            text="Ningún módulo coincide con la búsqueda."
            onClear={() => {
              setQ("");
              setGroup(null);
            }}
          />
        ) : (
          <BarList
            columns={2}
            label="Uso por módulo"
            data={shownModules.map((x) => ({
              key: x.code,
              label: `${x.code} · ${x.name}`,
              value: x.enabled,
              display: `${x.enabled} ${x.enabled === 1 ? "empresa" : "empresas"}`,
              color: SERIES_COLORS[x.group - 1],
              detail:
                `${CATALOG_GROUPS[x.group]} · ${x.enabled} ${x.enabled === 1 ? "empresa lo tiene" : "empresas lo tienen"}` +
                (x.read_only ? ` y ${x.read_only} en solo lectura` : "") +
                ". Presiona para ver cuáles.",
            }))}
            renderDetail={(d) => {
              const mod = m.modules.find((x) => x.code === d.key);
              return (
                <ul className="space-y-1.5">
                  {mod?.companies.map((c) => (
                    <li
                      key={`${c.account_id}-${c.name}`}
                      className="flex items-center justify-between gap-2 text-sm"
                    >
                      <Link
                        href={`/plataforma/cuentas/${c.account_id}`}
                        className="truncate text-brand hover:underline"
                      >
                        {c.name}
                      </Link>
                      <Chip tone={c.mode === "enabled" ? "ok" : "amber"} dot>
                        {c.mode === "enabled" ? "Habilitado" : "Solo lectura"}
                      </Chip>
                    </li>
                  ))}
                </ul>
              );
            }}
          />
        )}
        {!q && group === null && modules.length > 12 && (
          <button
            type="button"
            onClick={() => setAllModules((v) => !v)}
            className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-brand hover:underline"
          >
            <Icon
              name="chevron"
              size={15}
              className={allModules ? "-rotate-90" : "rotate-90"}
            />
            {allModules ? "Ver menos" : `Ver los ${modules.length} módulos`}
          </button>
        )}
      </Panel>
    </div>
  );
}

function Panel({
  id,
  title,
  hint,
  children,
}: {
  id?: string;
  title: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <section
      id={id}
      className="scroll-mt-24 rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-5"
    >
      <div className="mb-4">
        <h2 className="font-display text-lg font-semibold">{title}</h2>
        {hint && <p className="text-sm text-muted">{hint}</p>}
      </div>
      {children}
    </section>
  );
}
