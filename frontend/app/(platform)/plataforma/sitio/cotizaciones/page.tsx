"use client";

// Sitio web › Cotizaciones: cotizaciones formales (plan + complementos, una o varias empresas), su aceptación,
// la constancia de pago del cliente y la confirmación que crea la cuenta. Aquí también van los datos para el pago.

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, EmptyState, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { money, QUOTE_STATUS, type QuoteStatus, when } from "@/lib/quotes";

type Row = {
  id: string;
  number: string;
  status: QuoteStatus;
  contact_name: string;
  contact_email: string;
  companies: { ruc: string; legal_name: string }[];
  billing_period: "monthly" | "annual";
  total: string;
  valid_until: string | null;
  expired: boolean;
  sent_at: string | null;
  payment_submitted_at: string | null;
  created_at: string;
};
type Filter = "open" | "payment_submitted" | "accepted" | "paid" | "closed";

const FILTERS: Record<Filter, QuoteStatus[]> = {
  open: ["draft", "sent", "accepted", "payment_submitted"],
  payment_submitted: ["payment_submitted"],
  accepted: ["accepted"],
  paid: ["paid"],
  closed: ["rejected", "cancelled"],
};

export default function CotizacionesPage() {
  const [rows, setRows] = useState<Row[] | null>(null);
  const [filter, setFilter] = useState<Filter>("open");
  const [settings, setSettings] = useState<{ payment_instructions: string | null; quote_validity_days: number } | null>(null);
  const [editSettings, setEditSettings] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Row[] }>("/internal/site/quotes")
      .then((r) => setRows(r.data))
      .catch(() => setRows([]));
    api<{ data: { payment_instructions: string | null; quote_validity_days: number } }>("/internal/site/quotes/settings")
      .then((r) => setSettings(r.data))
      .catch(() => undefined);
  }, []);
  useEffect(load, [load]);

  const count = useMemo(() => {
    const c = (f: Filter) => (rows ?? []).filter((r) => FILTERS[f].includes(r.status)).length;
    return { open: c("open"), payment_submitted: c("payment_submitted"), accepted: c("accepted"), paid: c("paid"), closed: c("closed") };
  }, [rows]);
  const list = (rows ?? []).filter((r) => FILTERS[filter].includes(r.status));

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Cotizaciones"]]}
        icon="tag"
        title="Cotizaciones"
        description="Envía el precio formal (plan y complementos de cada empresa), recibe la aceptación y la constancia de pago, y al confirmar el pago se crea la cuenta del cliente."
        aside={
          <Link href="/plataforma/sitio/cotizaciones/nueva" className="inline-flex h-10 items-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-white shadow-sm hover:bg-brand-strong">
            <Icon name="plus" size={17} /> Nueva cotización
          </Link>
        }
      />

      <ol className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
        {(
          [
            ["pen", "1. Arma y envía", "Desde una solicitud o en blanco. Le llega al cliente con el PDF y un enlace personal."],
            ["check", "2. El cliente acepta", "En el enlace dice si acepta. Al aceptar ve los datos para pagar. Te avisamos."],
            ["file", "3. Envía su constancia", "Sube la constancia de pago desde el mismo enlace. Te avisamos para que la revises."],
            ["building", "4. Confirmas el pago", "Se crea su cuenta (o se activa su prueba) y le llega el correo para crear su contraseña."],
          ] as const
        ).map(([icon, title, text]) => (
          <li key={title} className="flex items-start gap-3 rounded-2xl border border-line bg-surface p-4 shadow-sm">
            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong">
              <Icon name={icon} size={17} />
            </span>
            <span>
              <b className="block text-sm">{title}</b>
              <span className="text-xs text-muted">{text}</span>
            </span>
          </li>
        ))}
      </ol>

      {settings && (
        <section className={`flex flex-col gap-3 rounded-2xl border p-4 sm:flex-row sm:items-center ${settings.payment_instructions ? "border-line bg-surface" : "border-amber/40 bg-amber-soft"}`}>
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong">
            <Icon name="lock" size={18} />
          </span>
          <div className="min-w-0 flex-1">
            <b className="block text-sm">Datos para el pago</b>
            {settings.payment_instructions ? (
              <p className="line-clamp-2 whitespace-pre-line text-sm text-muted">{settings.payment_instructions}</p>
            ) : (
              <p className="text-sm text-muted">Aún no los escribes. Son necesarios para enviar cotizaciones: el cliente los ve al aceptar.</p>
            )}
            <p className="mt-0.5 text-xs text-muted">Vigencia de cada cotización: {settings.quote_validity_days} días.</p>
          </div>
          <Button icon="pen" onClick={() => setEditSettings(true)}>
            {settings.payment_instructions ? "Editar" : "Escribir datos"}
          </Button>
        </section>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <StatCard label="En curso" value={count.open} icon="tag" tone="brand" active={filter === "open"} onClick={() => setFilter("open")} />
        <StatCard label="Pagos por revisar" value={count.payment_submitted} icon="bell" tone="amber" active={filter === "payment_submitted"} onClick={() => setFilter("payment_submitted")} />
        <StatCard label="Esperando pago" value={count.accepted} icon="clock" tone="amber" active={filter === "accepted"} onClick={() => setFilter("accepted")} />
        <StatCard label="Pagadas" value={count.paid} icon="check" tone="ok" active={filter === "paid"} onClick={() => setFilter("paid")} />
        <StatCard label="No aceptadas o anuladas" value={count.closed} icon="close" tone="na" active={filter === "closed"} onClick={() => setFilter("closed")} />
      </div>

      {rows === null ? (
        <p className="text-sm text-muted">Cargando…</p>
      ) : list.length === 0 ? (
        <EmptyState icon="tag" text={filter === "open" ? "No hay cotizaciones en curso. Crea una con «Nueva cotización» o desde una solicitud del sitio." : "No hay cotizaciones en este estado."} />
      ) : (
        <ul className="grid grid-cols-1 gap-3">
          {list.map((r) => {
            const st = QUOTE_STATUS[r.status];
            return (
              <li key={r.id}>
                <Link
                  href={`/plataforma/sitio/cotizaciones/${r.id}`}
                  className="group flex w-full flex-col gap-3 rounded-2xl border border-line bg-surface p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-md sm:flex-row sm:items-center sm:p-5"
                >
                  <span className={`grid h-11 w-11 shrink-0 place-items-center rounded-xl ${r.status === "payment_submitted" ? "bg-amber text-white" : "bg-brand-soft text-brand-strong"}`}>
                    <Icon name={st.icon} size={20} />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-2">
                      <b className="font-display text-[15px] group-hover:text-brand-strong">{r.companies[0]?.legal_name || r.contact_name}</b>
                      {r.companies.length > 1 && <Chip tone="brand">Grupo · {r.companies.length} empresas</Chip>}
                      <span className="font-mono text-xs text-muted">{r.number}</span>
                    </span>
                    <span className="mt-0.5 block truncate text-sm text-muted">
                      {r.contact_name} · {r.contact_email}
                    </span>
                  </span>
                  <span className="flex shrink-0 items-center gap-3 sm:flex-col sm:items-end sm:gap-1">
                    <b className="tabular-nums">
                      {money(r.total)} <span className="text-xs font-normal text-muted">/{r.billing_period === "annual" ? "año" : "mes"}</span>
                    </b>
                    <Chip tone={r.expired ? "bad" : st.tone} dot>
                      {r.expired ? "Vencida" : st.label}
                    </Chip>
                    <span className="text-xs text-muted">{when(r.payment_submitted_at ?? r.sent_at ?? r.created_at)}</span>
                  </span>
                </Link>
              </li>
            );
          })}
        </ul>
      )}

      {editSettings && settings && (
        <SettingsModal
          initial={settings}
          onClose={() => setEditSettings(false)}
          onSaved={() => {
            setEditSettings(false);
            setToast("Datos para el pago guardados.");
            load();
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function SettingsModal({ initial, onClose, onSaved }: { initial: { payment_instructions: string | null; quote_validity_days: number }; onClose: () => void; onSaved: () => void }) {
  const [text, setText] = useState(initial.payment_instructions ?? "");
  const [days, setDays] = useState(String(initial.quote_validity_days));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      await api("/internal/site/quotes/settings", { method: "PUT", body: { payment_instructions: text.trim() || null, quote_validity_days: Number(days) } });
      onSaved();
    } catch (err) {
      if (err instanceof ApiError) setErrors(err.errors ?? { payment_instructions: [err.message] });
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Datos para el pago"
      subtitle="El cliente los ve cuando acepta la cotización, y le llegan por correo."
      icon="lock"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="quote-settings" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="quote-settings" onSubmit={save} className="space-y-4" noValidate>
        <FormField label="Cómo pagar" hint="Banco, tipo y número de cuenta, CCI, titular y RUC; o número de Yape/Plin. Escribe solo datos reales de la empresa." error={errors.payment_instructions?.[0]}>
          <textarea
            className="input min-h-[150px]"
            maxLength={2000}
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder={"Banco: …\nCuenta corriente en soles: …\nCCI: …\nTitular: …\nRUC: …"}
          />
        </FormField>
        <FormField label="Vigencia de cada cotización (días)" hint="Pasado ese plazo, el cliente ya no puede aceptarla y hay que enviar una actualizada." error={errors.quote_validity_days?.[0]}>
          <input className="input w-32" type="number" min={1} max={90} value={days} onChange={(e) => setDays(e.target.value)} />
        </FormField>
      </form>
    </Modal>
  );
}
