"use client";

// SCR-SUS-02 · Cuentas y suscripciones (docs/modulos/M-SUS.md §6).
// Lista de cuentas con sus empresas, plan y estado. Filtros en el navegador; el detalle tiene las acciones.

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useState } from "react";
import { CreateAccountModal } from "@/components/sus/CreateAccountModal";
import { Button } from "@/components/ui/Button";
import { Chip, Code, EmptyState, PageHeader, StatCard } from "@/components/ui/Display";
import { FilterBar, FilterSelect, SearchInput } from "@/components/ui/FilterBar";
import { Icon } from "@/components/ui/Icon";
import { api, ApiError } from "@/lib/api";
import { ACCOUNT_TYPE, type AccountCompany, type AccountSummary, companyName, daysUntil, formatDate, periodEnd, REMINDER_DAYS, STATUS } from "@/lib/accounts";
import { PLAN_ACCENT } from "@/lib/plans";

const EMPTY = { q: "", plan: "", status: "", type: "" };

/** Vence dentro de los días de aviso (5): en prueba o activa. Filtro especial «Por vencer». */
function isExpiring(c: AccountCompany): boolean {
  const s = c.subscription;
  if (!s || !["active", "trialing"].includes(s.status)) return false;
  const d = daysUntil(periodEnd(s));
  return d !== null && d >= 0 && d <= REMINDER_DAYS;
}

function matchesStatus(c: AccountCompany, status: string): boolean {
  return !status || (status === "expiring" ? isExpiring(c) : c.subscription?.status === status);
}

export default function CuentasPage() {
  const router = useRouter();
  const [accounts, setAccounts] = useState<AccountSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [f, setF] = useState(EMPTY);
  const [creating, setCreating] = useState(false);

  const load = useCallback(() => {
    api<{ data: AccountSummary[] }>("/internal/accounts")
      .then((r) => setAccounts(r.data))
      .catch((e) => setError(e instanceof ApiError ? e.message : "No pudimos cargar las cuentas."));
  }, []);

  useEffect(load, [load]);

  // Llega filtrada desde Métricas u otras pantallas: ?estado=trialing, ?plan=E
  useEffect(() => {
    const p = new URLSearchParams(window.location.search);
    if (p.get("estado") || p.get("plan")) setF((cur) => ({ ...cur, status: p.get("estado") ?? "", plan: p.get("plan") ?? "" }));
  }, []);

  const filtered = useMemo(() => {
    if (!accounts) return [];
    const q = f.q.trim().toLowerCase();
    return accounts
      .map((a) => {
        const accountHit = !q || a.name.toLowerCase().includes(q) || (a.tax_id ?? "").includes(q);
        const companies = a.companies.filter(
          (c) =>
            (accountHit || c.ruc.includes(q) || c.legal_name.toLowerCase().includes(q) || (c.trade_name ?? "").toLowerCase().includes(q)) &&
            (!f.plan || c.subscription?.plan_code === f.plan) &&
            matchesStatus(c, f.status),
        );
        return { ...a, companies, total: a.companies.length };
      })
      .filter((a) => (!f.type || a.account_type === f.type) && (a.companies.length > 0 || (!f.plan && !f.status && (!q || a.name.toLowerCase().includes(q)))));
  }, [accounts, f]);

  if (error) return <p className="rounded-xl border border-bad/30 bg-bad-soft p-4 text-bad">{error}</p>;
  if (!accounts) return <Skeleton />;

  const companies = accounts.flatMap((a) => a.companies);
  const count = (s: string) => companies.filter((c) => c.subscription?.status === s).length;
  const active = Object.values(f).some(Boolean);
  const byStatus = (status: string) => setF((cur) => ({ ...cur, status: cur.status === status ? "" : status }));
  const shownCompanies = filtered.reduce((n, a) => n + a.companies.length, 0);

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Cuentas y suscripciones"]]}
        icon="building"
        title="Cuentas y suscripciones"
        description="Clientes, sus empresas y el plan de cada una."
      />

      <section aria-label="Resumen" className="grid grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-6">
        <StatCard
          label="Todas las empresas"
          value={companies.length}
          hint={`En ${accounts.length} ${accounts.length === 1 ? "cuenta" : "cuentas"}`}
          icon="building"
          tone="brand"
          active={!f.status}
          onClick={() => setF((cur) => ({ ...cur, status: "" }))}
        />
        <StatCard label="Activas" value={count("active")} hint="Pagando al día" icon="check" tone="ok" active={f.status === "active"} onClick={() => byStatus("active")} />
        <StatCard label="En prueba" value={count("trialing")} hint="Prueba gratuita" icon="clock" tone="brand" active={f.status === "trialing"} onClick={() => byStatus("trialing")} />
        <StatCard
          label="Por vencer"
          value={companies.filter(isExpiring).length}
          hint={`En ${REMINDER_DAYS} días o menos`}
          icon="clock"
          tone="amber"
          active={f.status === "expiring"}
          onClick={() => byStatus("expiring")}
        />
        <StatCard label="Pago pendiente" value={count("past_due")} hint="Periodo vencido" icon="file" tone="bad" active={f.status === "past_due"} onClick={() => byStatus("past_due")} />
        <StatCard label="Suspendidas" value={count("read_only")} hint="Solo lectura" icon="pause" tone="bad" active={f.status === "read_only"} onClick={() => byStatus("read_only")} />
      </section>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-muted">Los precios se muestran «Por definir» hasta que se configuren en Planes y módulos.</p>
        <Button icon="plus" onClick={() => setCreating(true)} className="w-full sm:w-auto">
          Nueva cuenta
        </Button>
      </div>

      <FilterBar
        count={`${filtered.length} ${filtered.length === 1 ? "cuenta" : "cuentas"} · ${shownCompanies} ${shownCompanies === 1 ? "empresa" : "empresas"}`}
        onClear={active ? () => setF(EMPTY) : undefined}
      >
        <SearchInput label="Buscar cuentas" placeholder="Cuenta, razón social o RUC" value={f.q} onChange={(q) => setF({ ...f, q })} />
        <FilterSelect
          label="Plan"
          value={f.plan}
          onChange={(plan) => setF({ ...f, plan })}
          options={[
            ["", "Todos los planes"],
            ["E", "Esencial"],
            ["P", "Profesional"],
            ["C", "Corporativo"],
          ]}
        />
        <FilterSelect
          label="Estado"
          value={f.status}
          onChange={(status) => setF({ ...f, status })}
          options={[["", "Todos los estados"], ["expiring", "Por vencer (5 días)"], ...(Object.entries(STATUS).map(([k, v]) => [k, v.label]) as [string, string][])]}
        />
        <FilterSelect
          label="Tipo de cuenta"
          value={f.type}
          onChange={(type) => setF({ ...f, type })}
          options={[
            ["", "Todos los tipos"],
            ["direct", ACCOUNT_TYPE.direct],
            ["group", ACCOUNT_TYPE.group],
          ]}
        />
      </FilterBar>

      {filtered.length === 0 ? (
        <div className="rounded-2xl border border-line bg-surface">
          {accounts.length === 0 ? (
            <EmptyState icon="building" text="Aún no hay cuentas. Crea la primera con «Nueva cuenta»." />
          ) : (
            <EmptyState text="Ninguna cuenta coincide con los filtros." onClear={() => setF(EMPTY)} />
          )}
        </div>
      ) : (
        <ul className="space-y-4">
          {filtered.map((a) => (
            <AccountCard key={a.id} account={a} />
          ))}
        </ul>
      )}

      {creating && (
        <CreateAccountModal
          onClose={() => setCreating(false)}
          onCreated={(id) => {
            setCreating(false);
            router.push(`/plataforma/cuentas/${id}?nueva=1`);
          }}
        />
      )}
    </div>
  );
}

function AccountCard({ account: a }: { account: AccountSummary & { total: number } }) {
  return (
    <li className="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
      <Link
        href={`/plataforma/cuentas/${a.id}`}
        className="group flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line bg-bg/50 px-4 py-3 hover:bg-brand-soft/40 sm:px-5"
      >
        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-soft text-brand-strong">
          <Icon name="building" size={18} />
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate font-display font-semibold group-hover:text-brand-strong">{a.name}</p>
          <p className="text-xs text-muted">
            {ACCOUNT_TYPE[a.account_type] ?? a.account_type} · {a.total} {a.total === 1 ? "empresa" : "empresas"} · desde{" "}
            {formatDate(a.created_at)}
          </p>
        </div>
        {a.status !== "active" && <Chip tone="bad">Cuenta {a.status}</Chip>}
        <span className="inline-flex items-center gap-1 text-sm font-semibold text-brand">
          <span className="hidden sm:inline">Ver detalle</span> <Icon name="chevron" size={16} />
        </span>
      </Link>
      <ul className="divide-y divide-line">
        {a.companies.map((c) => (
          <CompanyRow key={c.id} accountId={a.id} company={c} single={a.total === 1} />
        ))}
      </ul>
    </li>
  );
}

function CompanyRow({ accountId, company: c, single }: { accountId: string; company: AccountCompany; single: boolean }) {
  const s = c.subscription;
  const trialDays = s?.status === "trialing" ? daysUntil(s.trial_ends_at) : null;

  return (
    <li>
      <Link
        href={`/plataforma/cuentas/${accountId}#empresa-${c.id}`}
        className="grid gap-2 px-4 py-3 hover:bg-bg/60 sm:px-5 md:grid-cols-[minmax(0,1fr)_9rem_9rem_10rem] md:items-center md:gap-4"
      >
        <div className="min-w-0">
          {/* Si la cuenta tiene una sola empresa, su nombre ya está arriba: no se repite. */}
          {!single && <p className="truncate font-medium">{companyName(c)}</p>}
          <p className="flex flex-wrap items-center gap-2 text-xs text-muted">
            <Code>RUC {c.ruc}</Code>
            {c.headcount_declared !== null && <span>{c.headcount_declared} trabajadores</span>}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2 md:contents">
          <span className="inline-flex items-center gap-2 text-sm font-semibold">
            {s ? (
              <>
                <span className="h-2.5 w-2.5 rounded-full" style={{ background: PLAN_ACCENT[s.plan_code]?.bar }} />
                {s.plan_name}
                {s.addons_count > 0 && <span className="text-xs font-normal text-muted">+{s.addons_count}</span>}
              </>
            ) : (
              <span className="text-muted">Sin suscripción</span>
            )}
          </span>
          <span>{s ? <Chip tone={STATUS[s.status].tone} dot>{STATUS[s.status].label}</Chip> : <Chip tone="na">—</Chip>}</span>
          <span className={`text-xs md:text-sm ${s?.status === "past_due" || isExpiring(c) ? "font-semibold text-amber-ink" : "text-muted"}`}>
            {!s
              ? ""
              : s.status === "past_due"
                ? `Se bloquea ${formatDate(s.grace_until)}`
                : s.status === "read_only" || s.status === "trial_expired"
                  ? "Solo lectura"
                  : s.status === "trialing"
                    ? trialDays !== null && trialDays >= 0
                      ? `Prueba: ${trialDays === 0 ? "termina hoy" : `${trialDays} ${trialDays === 1 ? "día" : "días"}`}`
                      : "Prueba vencida"
                    : s.current_period_end
                      ? isExpiring(c)
                        ? `Vence ${daysUntil(s.current_period_end) === 0 ? "hoy" : `en ${daysUntil(s.current_period_end)} días`}`
                        : `Renueva ${formatDate(s.current_period_end)}`
                      : ""}
          </span>
        </div>
      </Link>
    </li>
  );
}

function Skeleton() {
  return (
    <div className="mx-auto max-w-7xl animate-pulse space-y-6" aria-label="Cargando cuentas">
      <div className="h-12 w-80 rounded-xl bg-line/60" />
      <div className="h-24 rounded-2xl bg-line/40" />
      {[0, 1].map((i) => (
        <div key={i} className="h-44 rounded-2xl bg-line/50" />
      ))}
    </div>
  );
}
