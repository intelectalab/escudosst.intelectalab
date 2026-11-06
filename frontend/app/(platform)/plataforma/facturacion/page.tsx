"use client";

// SCR-SUS-06 · Facturación del panel del dueño (docs/03 §11): pagos y comprobantes de todas las cuentas,
// empresas con deuda y descarga en CSV. Los pagos se registran desde el detalle de cada cuenta.

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { Chip, Code, EmptyState, PageHeader, StatCard } from "@/components/ui/Display";
import { Button } from "@/components/ui/Button";
import { FilterBar, FilterSelect, SearchInput } from "@/components/ui/FilterBar";
import { Icon } from "@/components/ui/Icon";
import { api, ApiError } from "@/lib/api";
import { formatDate, formatMoney, PAYMENT_METHODS, STATUS, type SubscriptionStatus } from "@/lib/accounts";

type BillingPayment = {
  id: string;
  amount: number;
  currency: string;
  method: string;
  reference: string | null;
  paid_at: string;
  concept: string | null;
  sunat_status: string;
  account_id: string;
  account_name: string;
  company_name: string | null;
  ruc: string | null;
  plan_code: string | null;
  registered_by: string | null;
};

type Debtor = {
  subscription_id: string;
  status: SubscriptionStatus;
  current_period_end: string | null;
  grace_until: string | null;
  account_id: string;
  account_name: string;
  company_name: string;
  ruc: string;
  plan_name: string;
};

const SUNAT: Record<string, { label: string; tone: "amber" | "ok" | "bad" | "na" }> = {
  pending: { label: "SUNAT pendiente", tone: "amber" },
  accepted: { label: "Aceptado SUNAT", tone: "ok" },
  observed: { label: "Observado", tone: "amber" },
  rejected: { label: "Rechazado", tone: "bad" },
  not_applicable: { label: "No aplica", tone: "na" },
};

const EMPTY = { q: "", method: "", month: "", sunat: "" };
const methodLabel = (m: string) => PAYMENT_METHODS.find(([v]) => v === m)?.[1] ?? m;
const monthKey = (d: string) => d.slice(0, 7);
const monthLabel = (k: string) => new Intl.DateTimeFormat("es-PE", { month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(`${k}-01T00:00:00Z`));

export default function FacturacionPage() {
  const [data, setData] = useState<{ payments: BillingPayment[]; debtors: Debtor[] } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [f, setF] = useState(EMPTY);
  const [view, setView] = useState<"payments" | "debtors">("payments");

  useEffect(() => {
    api<{ data: { payments: BillingPayment[]; debtors: Debtor[] } }>("/internal/billing")
      .then((r) => setData(r.data))
      .catch((e) => setError(e instanceof ApiError ? e.message : "No pudimos cargar la facturación."));
  }, []);

  const thisMonth = new Date().toLocaleDateString("en-CA", { timeZone: "America/Lima" }).slice(0, 7);

  const filtered = useMemo(() => {
    if (!data) return [];
    const q = f.q.trim().toLowerCase();
    return data.payments.filter(
      (p) =>
        (!q || [p.account_name, p.company_name ?? "", p.ruc ?? "", p.reference ?? ""].some((v) => v.toLowerCase().includes(q))) &&
        (!f.method || p.method === f.method) &&
        (!f.month || monthKey(p.paid_at) === f.month) &&
        (!f.sunat || p.sunat_status === f.sunat),
    );
  }, [data, f]);

  if (error) return <p className="rounded-xl border border-bad/30 bg-bad-soft p-4 text-bad">{error}</p>;
  if (!data) return <div className="mx-auto h-96 max-w-7xl animate-pulse rounded-2xl bg-line/40" aria-label="Cargando facturación" />;

  const monthPayments = data.payments.filter((p) => monthKey(p.paid_at) === thisMonth);
  const monthTotal = monthPayments.reduce((n, p) => n + p.amount, 0);
  const pendingSunat = data.payments.filter((p) => p.sunat_status === "pending").length;
  const months = [...new Set(data.payments.map((p) => monthKey(p.paid_at)))].sort().reverse();
  const total = filtered.reduce((n, p) => n + p.amount, 0);
  const active = Object.values(f).some(Boolean);

  function exportCsv() {
    const rows = [
      ["Fecha", "Cuenta", "Empresa", "RUC", "Concepto", "Medio", "N.° operación", "Monto", "Moneda", "Estado SUNAT", "Registrado por"],
      ...filtered.map((p) => [p.paid_at.slice(0, 10), p.account_name, p.company_name ?? "", p.ruc ?? "", p.concept ?? "", methodLabel(p.method), p.reference ?? "", p.amount.toFixed(2), p.currency, SUNAT[p.sunat_status]?.label ?? p.sunat_status, p.registered_by ?? ""]),
    ];
    const csv = "﻿" + rows.map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(";")).join("\r\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
    const a = Object.assign(document.createElement("a"), { href: url, download: `pagos-escudosst-${f.month || "todos"}.csv` });
    a.click();
    URL.revokeObjectURL(url);
  }

  return (
    <div className="mx-auto w-full max-w-[1600px] space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Facturación"]]}
        icon="file"
        title="Facturación"
        description="Pagos registrados y empresas con deuda."
      />

      <section aria-label="Resumen" className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Cobrado este mes"
          value={formatMoney(monthTotal)}
          hint={`${monthPayments.length} ${monthPayments.length === 1 ? "pago" : "pagos"} · ${monthLabel(thisMonth)}`}
          icon="check"
          tone="ok"
          active={view === "payments" && f.month === thisMonth && !f.sunat}
          onClick={() => {
            setView("payments");
            setF((c) => ({ ...c, month: c.month === thisMonth ? "" : thisMonth, sunat: "" }));
          }}
        />
        <StatCard
          label="Pagos registrados"
          value={data.payments.length}
          hint="Todos los meses"
          icon="file"
          active={view === "payments" && !active}
          onClick={() => {
            setView("payments");
            setF(EMPTY);
          }}
        />
        <StatCard
          label="Facturas SUNAT pendientes"
          value={pendingSunat}
          hint="Falta el proveedor OSE"
          icon="clock"
          tone="amber"
          active={view === "payments" && f.sunat === "pending"}
          onClick={() => {
            setView("payments");
            setF((c) => ({ ...c, sunat: c.sunat === "pending" ? "" : "pending" }));
          }}
        />
        <StatCard
          label="Empresas con deuda"
          value={data.debtors.length}
          hint="Pago pendiente o bloqueadas"
          icon="lock"
          tone="bad"
          active={view === "debtors"}
          onClick={() => setView((v) => (v === "debtors" ? "payments" : "debtors"))}
        />
      </section>

      {view === "debtors" ? (
        <section className="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
          <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3 sm:px-5">
            <h2 className="font-display font-semibold">Empresas con deuda</h2>
            <Button variant="ghost" size="sm" icon="back" onClick={() => setView("payments")}>
              Ver pagos
            </Button>
          </div>
          {data.debtors.length === 0 ? (
            <EmptyState icon="check" text="Ninguna empresa tiene pagos pendientes. 🎉" />
          ) : (
            <ul className="divide-y divide-line">
              {data.debtors.map((d) => (
                <li key={d.subscription_id}>
                  <Link href={`/plataforma/cuentas/${d.account_id}`} className="grid gap-2 px-4 py-3 hover:bg-bg/60 sm:px-5 lg:grid-cols-[minmax(0,1fr)_10rem_14rem_auto] lg:items-center">
                    <div className="min-w-0">
                      <p className="truncate font-medium">{d.company_name}</p>
                      <p className="text-xs text-muted">
                        {d.account_name} · RUC {d.ruc} · {d.plan_name}
                      </p>
                    </div>
                    <span>
                      <Chip tone={STATUS[d.status].tone} dot>
                        {STATUS[d.status].label}
                      </Chip>
                    </span>
                    <span className="text-sm text-muted">
                      {d.status === "past_due" ? `Se bloquea el ${formatDate(d.grace_until)}` : `Venció el ${formatDate(d.current_period_end)}`}
                    </span>
                    <span className="inline-flex items-center gap-1 text-sm font-semibold text-brand">
                      Registrar pago <Icon name="chevron" size={15} />
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </section>
      ) : (
        <>
          <FilterBar count={`${filtered.length} ${filtered.length === 1 ? "pago" : "pagos"} · ${formatMoney(total)}`} onClear={active ? () => setF(EMPTY) : undefined}>
            <SearchInput label="Buscar pagos" placeholder="Cuenta, empresa, RUC u operación" value={f.q} onChange={(q) => setF({ ...f, q })} />
            <FilterSelect label="Mes" value={f.month} onChange={(month) => setF({ ...f, month })} options={[["", "Todos los meses"], ...months.map((m) => [m, monthLabel(m)] as [string, string])]} />
            <FilterSelect label="Medio de pago" value={f.method} onChange={(method) => setF({ ...f, method })} options={[["", "Todos los medios"], ...PAYMENT_METHODS]} />
            <FilterSelect
              label="Estado SUNAT"
              value={f.sunat}
              onChange={(sunat) => setF({ ...f, sunat })}
              options={[["", "Todos"], ...(Object.entries(SUNAT).map(([k, v]) => [k, v.label]) as [string, string][])]}
            />
          </FilterBar>

          <div className="flex justify-end">
            <Button variant="secondary" icon="file" onClick={exportCsv} disabled={filtered.length === 0}>
              Descargar CSV
            </Button>
          </div>

          {filtered.length === 0 ? (
            <div className="rounded-2xl border border-line bg-surface">
              {data.payments.length === 0 ? (
                <EmptyState icon="file" text="Aún no hay pagos. Se registran desde el detalle de cada cuenta." />
              ) : (
                <EmptyState text="Ningún pago coincide con los filtros." onClear={() => setF(EMPTY)} />
              )}
            </div>
          ) : (
            <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
              {filtered.map((p) => (
                <li key={p.id}>
                  <Link
                    href={`/plataforma/cuentas/${p.account_id}`}
                    className="grid gap-2 px-4 py-3 hover:bg-bg/60 sm:px-5 xl:grid-cols-[7rem_minmax(0,1fr)_10rem_9rem_9rem] xl:items-center xl:gap-4"
                  >
                    <span className="text-sm text-muted">{formatDate(p.paid_at)}</span>
                    <div className="min-w-0">
                      <p className="truncate font-medium">{p.company_name ?? p.account_name}</p>
                      <p className="flex flex-wrap items-center gap-2 text-xs text-muted">
                        {p.ruc && <Code>RUC {p.ruc}</Code>}
                        <span className="truncate">{p.concept}</span>
                      </p>
                    </div>
                    <span className="text-sm">
                      {methodLabel(p.method)}
                      {p.reference && <span className="block truncate text-xs text-muted">Op. {p.reference}</span>}
                    </span>
                    <span>
                      <Chip tone={SUNAT[p.sunat_status]?.tone ?? "na"}>{SUNAT[p.sunat_status]?.label ?? p.sunat_status}</Chip>
                    </span>
                    <span className="font-display text-lg font-bold tabular-nums xl:text-right">{formatMoney(p.amount, p.currency)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </div>
  );
}
