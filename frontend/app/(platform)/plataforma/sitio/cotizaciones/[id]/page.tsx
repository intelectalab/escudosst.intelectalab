"use client";

// Sitio web › Cotizaciones › una cotización: se arma (una o varias empresas del grupo, cada una con su plan y
// complementos), se envía, y luego se sigue: aceptación del cliente, constancia de pago y confirmación del pago.
// «nueva» abre el editor en blanco o con los datos de una solicitud (?solicitud=id).

import Link from "next/link";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { FormEvent, Fragment, Suspense, useCallback, useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { ChoiceCards, FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { SECTORS } from "@/lib/accounts";
import { api, API_URL, ApiError } from "@/lib/api";
import { day, money, PLAN_NAMES, type QuoteCompany, type QuoteDetail, QUOTE_STATUS, when } from "@/lib/quotes";
import { session } from "@/lib/session";

type Addon = { code: string; name: string; available: boolean; price: string | null };
type Plan = { code: string; name: string; price: string | null };
type Form = {
  demo_request_id: string | null;
  contact_name: string;
  contact_email: string;
  contact_phone: string;
  billing_period: "monthly" | "annual";
  companies: QuoteCompany[];
  discount_amount: string;
  valid_until: string;
  client_notes: string;
  internal_notes: string;
};
type Preview = { companies: (QuoteCompany & { amount: number | null })[]; subtotal: number; discount_amount: number; total: number; complete: boolean; annual_discount_pct: number };
type Errors = Record<string, string[]>;

const EMPTY_COMPANY: QuoteCompany = { ruc: "", legal_name: "", trade_name: "", headcount: null, economic_sector: null, plan_code: "P", addon_codes: [] };
const METHODS: [string, string][] = [
  ["transfer", "Transferencia"],
  ["deposit", "Depósito"],
  ["yape", "Yape"],
  ["plin", "Plin"],
  ["other", "Otro"],
];

/** Abre un archivo privado de la cotización (PDF o constancia) en otra pestaña. */
async function openFile(id: string, kind: "pdf" | "evidence") {
  const w = window.open("", "_blank");
  const res = await fetch(`${API_URL}/api/v1/internal/site/quotes/${id}/file/${kind}`, { headers: { Authorization: `Bearer ${session.getToken()}` } });
  if (!res.ok) {
    w?.close();
    return;
  }
  const url = URL.createObjectURL(await res.blob());
  if (w) w.location.href = url;
}

export default function CotizacionPage() {
  return (
    <Suspense>
      <Cotizacion />
    </Suspense>
  );
}

function Cotizacion() {
  const { id } = useParams<{ id: string }>();
  const isNew = id === "nueva";
  const requestId = useSearchParams().get("solicitud");
  const router = useRouter();
  const [quote, setQuote] = useState<QuoteDetail | null>(null);
  const [form, setForm] = useState<Form | null>(null);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [addons, setAddons] = useState<Addon[]>([]);
  const [toast, setToast] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    if (isNew) {
      api<{ data: Form & { discount_amount: number } }>(`/internal/site/quotes/new${requestId ? `?request=${requestId}` : ""}`)
        .then((r) => setForm({ ...r.data, contact_phone: r.data.contact_phone ?? "", discount_amount: String(r.data.discount_amount ?? 0) }))
        .catch((e) => setError(e instanceof ApiError ? e.message : "No se pudo cargar."));
      return;
    }
    api<{ data: QuoteDetail }>(`/internal/site/quotes/${id}`)
      .then((r) => {
        setQuote(r.data);
        setForm({
          demo_request_id: r.data.demo_request_id,
          contact_name: r.data.contact_name,
          contact_email: r.data.contact_email,
          contact_phone: r.data.contact_phone ?? "",
          billing_period: r.data.billing_period,
          companies: r.data.companies.map((c) => ({ ...c, trade_name: c.trade_name ?? "" })),
          discount_amount: String(Number(r.data.discount_amount)),
          valid_until: r.data.valid_until ?? "",
          client_notes: r.data.client_notes ?? "",
          internal_notes: r.data.internal_notes ?? "",
        });
      })
      .catch((e) => setError(e instanceof ApiError ? e.message : "No se pudo cargar."));
  }, [id, isNew, requestId]);

  useEffect(load, [load]);
  useEffect(() => {
    api<{ data: { plans: Plan[]; addons: Addon[] } }>("/public/pricing")
      .then((r) => {
        setPlans(r.data.plans.filter((p) => p.code !== "K"));
        setAddons(r.data.addons);
      })
      .catch(() => undefined);
  }, []);

  const title = isNew ? "Nueva cotización" : (quote?.number ?? "Cotización");
  const editable = isNew || !!quote?.editable;

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Cotizaciones", "/plataforma/sitio/cotizaciones"], [title]]}
        icon="tag"
        title={
          <span className="flex flex-wrap items-center gap-3">
            {title}
            {quote && (
              <Chip tone={QUOTE_STATUS[quote.status].tone} dot>
                {QUOTE_STATUS[quote.status].label}
              </Chip>
            )}
          </span>
        }
        description={
          isNew
            ? "Agrega cada empresa con su RUC, plan y complementos. Si es un grupo, cada empresa tendrá su propia suscripción dentro de una sola cuenta."
            : quote
              ? `Para ${quote.contact_name} · ${quote.contact_email}${quote.sent_at ? ` · enviada el ${when(quote.sent_at)}` : ""}`
              : undefined
        }
      />
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error}</p>}
      {!form ? (
        !error && <p className="text-sm text-muted">Cargando…</p>
      ) : (
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
          <div className="min-w-0">
            {editable ? (
              <Editor
                form={form}
                setForm={setForm}
                plans={plans}
                addons={addons}
                quote={quote}
                onSaved={(newId, msg) => {
                  setToast(msg);
                  if (isNew) router.replace(`/plataforma/sitio/cotizaciones/${newId}`);
                  else load();
                }}
              />
            ) : (
              quote && <ReadOnly quote={quote} />
            )}
          </div>
          {quote && (
            <Actions
              quote={quote}
              onDone={(msg) => {
                setToast(msg);
                load();
              }}
            />
          )}
        </div>
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

// ------------------------------------------------------------------ Editor

function Editor({
  form,
  setForm,
  plans,
  addons,
  quote,
  onSaved,
}: {
  form: Form;
  setForm: (f: Form) => void;
  plans: Plan[];
  addons: Addon[];
  quote: QuoteDetail | null;
  onSaved: (id: string, msg: string) => void;
}) {
  const [errors, setErrors] = useState<Errors>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [preview, setPreview] = useState<Preview | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm({ ...form, [k]: v });
  const setCompany = (i: number, patch: Partial<QuoteCompany>) => set("companies", form.companies.map((c, j) => (j === i ? { ...c, ...patch } : c)));
  const err = (k: string) => errors[k]?.[0];

  const body = useCallback(
    () => ({
      ...form,
      contact_phone: form.contact_phone.trim() || null,
      discount_amount: Number(form.discount_amount || 0),
      valid_until: form.valid_until || null,
      companies: form.companies.map((c) => ({ ...c, headcount: c.headcount ? Number(c.headcount) : null })),
    }),
    [form],
  );

  // Montos al momento (sin guardar) mientras se edita.
  useEffect(() => {
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => {
      api<{ data: Preview }>("/internal/site/quotes/preview", { method: "POST", body: body() })
        .then((r) => setPreview(r.data))
        .catch(() => setPreview(null));
    }, 400);
    return () => {
      if (timer.current) clearTimeout(timer.current);
    };
  }, [body]);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMessage(null);
    setErrors({});
    try {
      if (quote) {
        await api(`/internal/site/quotes/${quote.id}`, { method: "PUT", body: body() });
        onSaved(quote.id, quote.status === "draft" ? "Cotización guardada." : "Cotización guardada. Volvió a borrador: envíala otra vez para que el cliente vea los cambios.");
      } else {
        const r = await api<{ data: { id: string } }>("/internal/site/quotes", { method: "POST", body: body() });
        onSaved(r.data.id, "Cotización creada como borrador. Revísala y envíala.");
      }
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.errors ?? {});
        setMessage(e.errors ? "Revisa los campos marcados." : e.message);
      } else setMessage("No se pudo guardar.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={save} noValidate className="space-y-5">
      {message && (
        <p role="alert" className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">
          {message}
          {errors.companies?.[0] && <span className="mt-1 block font-normal">{errors.companies[0]}</span>}
        </p>
      )}
      {quote && quote.status !== "draft" && (
        <p className="rounded-lg bg-amber-soft px-4 py-3 text-sm text-amber">
          Si cambias algo, la cotización vuelve a borrador y el enlace que tiene el cliente deja de servir. Después de guardar, envíala otra vez.
        </p>
      )}

      <section className="form-section space-y-4">
        <h2 className="flex items-center gap-2 font-display text-base font-bold">
          <Icon name="user" size={17} /> Contacto del cliente
        </h2>
        <p className="-mt-2 text-xs text-muted">Recibe la cotización y, al confirmar el pago, queda como administrador de la cuenta.</p>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <FormField label="Nombre y apellidos" error={err("contact_name")}>
            <input className="input" maxLength={120} value={form.contact_name} onChange={(e) => set("contact_name", e.target.value)} />
          </FormField>
          <FormField label="Correo" error={err("contact_email")}>
            <input className="input" type="email" maxLength={160} value={form.contact_email} onChange={(e) => set("contact_email", e.target.value)} />
          </FormField>
          <FormField label="Celular (opcional)" error={err("contact_phone")}>
            <input className="input" maxLength={30} value={form.contact_phone} onChange={(e) => set("contact_phone", e.target.value)} />
          </FormField>
        </div>
        <ChoiceCards
          label="Periodo de pago"
          columns={2}
          value={form.billing_period}
          onChange={(v) => set("billing_period", v)}
          options={[
            { value: "monthly", title: "Mensual", detail: "Paga mes a mes." },
            { value: "annual", title: "Anual", detail: preview?.annual_discount_pct ? `Paga el año completo con ${preview.annual_discount_pct} % de descuento.` : "Paga el año completo." },
          ]}
        />
      </section>

      {form.companies.map((c, i) => {
        const p = preview?.companies[i];
        return (
          <section key={i} className="form-section space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="flex items-center gap-2 font-display text-base font-bold">
                <Icon name="building" size={17} /> {form.companies.length > 1 ? `Empresa ${i + 1} del grupo` : "Empresa"}
              </h2>
              <span className="flex items-center gap-3">
                {p && <b className="tabular-nums text-brand-strong">{money(p.amount)}</b>}
                {form.companies.length > 1 && (
                  <Button size="sm" variant="danger-ghost" icon="close" onClick={() => set("companies", form.companies.filter((_, j) => j !== i))}>
                    Quitar
                  </Button>
                )}
              </span>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <FormField label="RUC" error={err(`companies.${i}.ruc`)}>
                <input className="input tabular-nums" inputMode="numeric" maxLength={11} value={c.ruc} onChange={(e) => setCompany(i, { ruc: e.target.value.replace(/\D/g, "") })} />
              </FormField>
              <div className="sm:col-span-2">
                <FormField label="Razón social" error={err(`companies.${i}.legal_name`)}>
                  <input className="input" maxLength={160} value={c.legal_name} onChange={(e) => setCompany(i, { legal_name: e.target.value })} />
                </FormField>
              </div>
              <FormField label="Trabajadores" error={err(`companies.${i}.headcount`)}>
                <input className="input tabular-nums" type="number" min={1} value={c.headcount ?? ""} onChange={(e) => setCompany(i, { headcount: e.target.value ? Number(e.target.value) : null })} />
              </FormField>
              <FormField label="Sector (opcional)">
                <select className="input" value={c.economic_sector ?? ""} onChange={(e) => setCompany(i, { economic_sector: e.target.value || null })}>
                  <option value="">Sin indicar</option>
                  {SECTORS.map(([v, l]) => (
                    <option key={v} value={v}>
                      {l}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField label="Plan" error={err(`companies.${i}.plan_code`)}>
                <select className="input" value={c.plan_code} onChange={(e) => setCompany(i, { plan_code: e.target.value as QuoteCompany["plan_code"] })}>
                  {plans.map((pl) => (
                    <option key={pl.code} value={pl.code}>
                      {pl.name} · {money(pl.price)}/mes
                    </option>
                  ))}
                </select>
              </FormField>
            </div>
            <div>
              <span className="mb-1.5 block text-sm font-semibold">Complementos (opcional)</span>
              <div className="flex flex-wrap gap-2">
                {addons.map((a) => {
                  const on = c.addon_codes.includes(a.code);
                  return (
                    <button
                      key={a.code}
                      type="button"
                      disabled={!a.available && !on}
                      onClick={() => setCompany(i, { addon_codes: on ? c.addon_codes.filter((x) => x !== a.code) : [...c.addon_codes, a.code] })}
                      className={`rounded-full border px-3 py-1.5 text-sm transition ${on ? "border-brand bg-brand text-white" : "border-line bg-surface hover:border-brand"} disabled:cursor-not-allowed disabled:opacity-50`}
                      title={a.available ? undefined : "Próximamente: aún no se puede contratar"}
                    >
                      {on && "✓ "}
                      {a.name} <span className={on ? "text-white/80" : "text-muted"}>{a.available ? `· ${money(a.price)}/mes` : "· próximamente"}</span>
                    </button>
                  );
                })}
              </div>
              {err(`companies.${i}.addon_codes`) && <p className="mt-1.5 text-xs font-medium text-bad">● {err(`companies.${i}.addon_codes`)}</p>}
            </div>
          </section>
        );
      })}
      <Button icon="plus" onClick={() => set("companies", [...form.companies, { ...EMPTY_COMPANY, plan_code: form.companies[0]?.plan_code ?? "P" }])}>
        Agregar otra empresa del grupo
      </Button>

      <section className="form-section space-y-4">
        <h2 className="flex items-center gap-2 font-display text-base font-bold">
          <Icon name="file" size={17} /> Condiciones
        </h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Descuento (S/, opcional)" hint="Se resta del total." error={err("discount_amount")}>
            <input className="input tabular-nums" inputMode="decimal" value={form.discount_amount} onChange={(e) => set("discount_amount", e.target.value.replace(",", ".").replace(/[^\d.]/g, ""))} />
          </FormField>
          <FormField label="Válida hasta" hint="Si la dejas pasada, al enviar se pone la vigencia por defecto." error={err("valid_until")}>
            <input className="input" type="date" value={form.valid_until} onChange={(e) => set("valid_until", e.target.value)} />
          </FormField>
        </div>
        <FormField label="Condiciones y notas para el cliente (opcional)" hint="Salen en el PDF. Ej.: si el precio incluye IGV, qué incluye la implementación." error={err("client_notes")}>
          <textarea className="input min-h-[90px]" maxLength={3000} value={form.client_notes} onChange={(e) => set("client_notes", e.target.value)} />
        </FormField>
        <FormField label="Nota interna (opcional)" hint="Solo la ve el equipo." error={err("internal_notes")}>
          <textarea className="input min-h-[70px]" maxLength={3000} value={form.internal_notes} onChange={(e) => set("internal_notes", e.target.value)} />
        </FormField>
      </section>

      <div className="sticky bottom-3 z-10 flex flex-col gap-3 rounded-2xl border border-line bg-surface p-4 shadow-lg sm:flex-row sm:items-center">
        <div className="min-w-0 flex-1 text-sm">
          {preview ? (
            <>
              <span className="text-muted">Subtotal {money(preview.subtotal)}</span>
              {preview.discount_amount > 0 && <span className="text-muted"> · descuento − {money(preview.discount_amount)}</span>}
              <b className="ml-2 font-display text-lg text-brand-strong">
                Total {money(preview.total)} <span className="text-xs font-normal text-muted">/{form.billing_period === "annual" ? "año" : "mes"}</span>
              </b>
              {!preview.complete && <span className="block text-xs text-amber">Hay conceptos sin precio: defínelos en «Planes y precios» antes de enviar.</span>}
            </>
          ) : (
            <span className="text-muted">Completa los datos para ver el total.</span>
          )}
        </div>
        <Button variant="primary" type="submit" icon="check" loading={saving}>
          {quote ? "Guardar cambios" : "Guardar borrador"}
        </Button>
      </div>
    </form>
  );
}

// ------------------------------------------------------------------ Solo lectura

function ReadOnly({ quote }: { quote: QuoteDetail }) {
  return (
    <div className="space-y-5">
      <section className="form-section">
        <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
          {(
            [
              ["Contacto", `${quote.contact_name}${quote.contact_phone ? ` · ${quote.contact_phone}` : ""}`],
              ["Correo", quote.contact_email],
              ["Periodo", quote.billing_period === "annual" ? "Anual" : "Mensual"],
              ["Válida hasta", day(quote.valid_until)],
              ["Aceptada", when(quote.accepted_at)],
              ["Cuenta creada", when(quote.confirmed_at)],
            ] as const
          )
            .filter(([, v]) => v)
            .map(([k, v]) => (
              <div key={k}>
                <dt className="text-xs font-semibold uppercase tracking-wide text-muted">{k}</dt>
                <dd className="mt-0.5 break-words font-medium">{v}</dd>
              </div>
            ))}
        </dl>
      </section>
      <QuoteTable quote={quote} />
      {quote.client_notes && <p className="whitespace-pre-line rounded-xl bg-bg px-4 py-3 text-sm">{quote.client_notes}</p>}
      {quote.internal_notes && <p className="whitespace-pre-line rounded-xl border border-dashed border-line px-4 py-3 text-sm text-muted">Nota interna: {quote.internal_notes}</p>}
    </div>
  );
}

function QuoteTable({ quote }: { quote: QuoteDetail }) {
  return (
    <section className="form-section overflow-x-auto">
      <table className="w-full min-w-[520px] text-sm">
        <thead>
          <tr className="text-left text-xs uppercase tracking-wide text-muted">
            <th className="pb-2">Concepto</th>
            <th className="pb-2 text-right">Monto {quote.billing_period === "annual" ? "anual" : "mensual"}</th>
          </tr>
        </thead>
        <tbody>
          {quote.companies.map((c) => (
            <Fragment key={c.ruc}>
              <tr className="border-t border-line bg-bg">
                <td className="py-2 pl-2 font-semibold" colSpan={2}>
                  {c.legal_name} · RUC {c.ruc} · {c.headcount} trabajadores
                </td>
              </tr>
              {(c.lines ?? []).map((l) => (
                <tr key={`${c.ruc}-${l.code}`} className="border-t border-line">
                  <td className="py-1.5 pl-5">{l.name}</td>
                  <td className="py-1.5 text-right tabular-nums">{money(l.amount)}</td>
                </tr>
              ))}
            </Fragment>
          ))}
          {Number(quote.discount_amount) > 0 && (
            <tr className="border-t border-line">
              <td className="py-1.5 text-right">Descuento</td>
              <td className="py-1.5 text-right tabular-nums">− {money(quote.discount_amount)}</td>
            </tr>
          )}
          <tr className="border-t-2 border-fg/20">
            <td className="py-2 text-right font-bold">Total</td>
            <td className="py-2 text-right font-display text-lg font-bold tabular-nums text-brand-strong">{money(quote.total)}</td>
          </tr>
        </tbody>
      </table>
    </section>
  );
}

// ------------------------------------------------------------------ Acciones según el estado

function Actions({ quote, onDone }: { quote: QuoteDetail; onDone: (msg: string) => void }) {
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [modal, setModal] = useState<"cancel" | "return" | "confirm" | null>(null);

  async function act(action: string, body?: unknown, msg = "Listo.") {
    setBusy(action);
    setError(null);
    try {
      await api(`/internal/site/quotes/${quote.id}/${action}`, { method: "POST", body });
      setModal(null);
      onDone(msg);
    } catch (e) {
      const m = e instanceof ApiError ? (e.errors ? Object.values(e.errors)[0]?.[0] : e.message) : "No se pudo.";
      setError(m ?? "No se pudo.");
      throw e;
    } finally {
      setBusy(null);
    }
  }

  const s = quote.status;
  return (
    <aside className="space-y-4 xl:sticky xl:top-20 xl:self-start">
      <section className="form-section space-y-3">
        <h2 className="font-display text-base font-bold">¿Qué sigue?</h2>
        {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm font-semibold text-bad">{error}</p>}

        {["draft", "sent", "rejected"].includes(s) && (
          <>
            <p className="text-sm text-muted">
              {s === "draft" && "Revisa los montos y envíala. Al cliente le llega el PDF y un enlace para responder."}
              {s === "sent" && `Enviada ${quote.sent_count} ${quote.sent_count === 1 ? "vez" : "veces"}. Esperando que el cliente responda. Si la reenvías, el enlace anterior deja de servir.`}
              {s === "rejected" && `El cliente no la aceptó${quote.reject_reason ? `: «${quote.reject_reason}»` : "."} Puedes ajustarla y enviarla otra vez.`}
            </p>
            <Button full variant="primary" icon="mail" loading={busy === "send"} onClick={() => act("send", undefined, `Cotización enviada a ${quote.contact_email}.`).catch(() => undefined)}>
              {s === "draft" ? "Enviar al cliente" : "Enviar otra vez"}
            </Button>
          </>
        )}

        {s === "accepted" && (
          <>
            <p className="text-sm text-muted">
              El cliente aceptó el {when(quote.accepted_at)}: ya ve los datos para pagar; te avisaremos cuando envíe su constancia.
              {quote.evidence_rejected_reason && ` Devolviste su constancia anterior: «${quote.evidence_rejected_reason}».`}
            </p>
            <p className="text-xs text-muted">Si el pago te llegó por otra vía (p. ej. lo viste en el banco), puedes confirmarlo sin constancia.</p>
            <Button full variant="primary" icon="check" onClick={() => setModal("confirm")}>
              Confirmar pago y crear la cuenta
            </Button>
          </>
        )}

        {s === "payment_submitted" && quote.payment && (
          <>
            <p className="text-sm text-muted">El cliente envió su constancia el {when(quote.payment.submitted_at)}: revísala y compara con tu banco.</p>
            <dl className="grid grid-cols-2 gap-2 rounded-xl bg-bg p-3 text-sm">
              <dt className="text-muted">Monto</dt>
              <dd className={`text-right font-bold tabular-nums ${Number(quote.payment.amount) !== Number(quote.total) ? "text-bad" : ""}`}>{money(quote.payment.amount)}</dd>
              <dt className="text-muted">Total cotizado</dt>
              <dd className="text-right tabular-nums">{money(quote.total)}</dd>
              <dt className="text-muted">Medio</dt>
              <dd className="text-right">{quote.payment.method_label}</dd>
              <dt className="text-muted">Fecha</dt>
              <dd className="text-right">{day(quote.payment.paid_on)}</dd>
              {quote.payment.reference && (
                <>
                  <dt className="text-muted">N.° de operación</dt>
                  <dd className="break-all text-right font-mono text-xs">{quote.payment.reference}</dd>
                </>
              )}
            </dl>
            <Button full icon="eye" onClick={() => openFile(quote.id, "evidence")}>
              Ver la constancia
            </Button>
            <Button full variant="primary" icon="check" onClick={() => setModal("confirm")}>
              Confirmar pago y crear la cuenta
            </Button>
            <Button full variant="danger-ghost" icon="close" onClick={() => setModal("return")}>
              Devolver constancia
            </Button>
          </>
        )}

        {s === "paid" && (
          <>
            <p className="text-sm text-muted">
              Pago confirmado el {when(quote.confirmed_at)} — la cuenta quedó activa y al administrador le llegó el correo para crear su contraseña (si aún no tenía usuario).
            </p>
            {quote.account_id && (
              <Link href={`/plataforma/cuentas/${quote.account_id}`} className="flex h-10 w-full items-center justify-center gap-2 rounded-lg bg-brand text-sm font-semibold text-white hover:bg-brand-strong">
                <Icon name="building" size={17} /> Ver la cuenta
              </Link>
            )}
          </>
        )}

        {s === "cancelled" && <p className="text-sm text-muted">Anulada{quote.cancel_reason ? `: «${quote.cancel_reason}»` : "."}</p>}

        {quote.has_pdf && (
          <Button full icon="file" onClick={() => openFile(quote.id, "pdf")}>
            Ver el PDF enviado
          </Button>
        )}
        {!["paid", "cancelled"].includes(s) && (
          <Button full variant="ghost" icon="close" onClick={() => setModal("cancel")}>
            Anular cotización
          </Button>
        )}
        {quote.demo_request_id && (
          <Link href="/plataforma/sitio/solicitudes" className="block text-center text-xs text-brand-strong hover:underline">
            Viene de una solicitud del sitio
          </Link>
        )}
      </section>

      {modal === "confirm" && <ConfirmModal quote={quote} busy={busy === "confirm-payment"} error={error} onClose={() => setModal(null)} onSubmit={(b) => act("confirm-payment", b, "Pago confirmado. La cuenta quedó activa.").catch(() => undefined)} />}
      {(modal === "cancel" || modal === "return") && (
        <ReasonModal
          title={modal === "cancel" ? "Anular cotización" : "Devolver constancia"}
          hint={modal === "cancel" ? "Solo lo ve el equipo. El enlace del cliente deja de servir." : "Le llega al cliente por correo, con un enlace nuevo para enviar otra constancia."}
          placeholder={modal === "cancel" ? "Ej.: el cliente pidió otra propuesta" : "Ej.: el monto no coincide con el total"}
          busy={busy !== null}
          error={error}
          onClose={() => setModal(null)}
          onSubmit={(reason) =>
            act(modal === "cancel" ? "cancel" : "reject-evidence", { reason }, modal === "cancel" ? "Cotización anulada." : "Constancia devuelta: le avisamos al cliente.").catch(() => undefined)
          }
        />
      )}
    </aside>
  );
}

function ConfirmModal({ quote, busy, error, onClose, onSubmit }: { quote: QuoteDetail; busy: boolean; error: string | null; onClose: () => void; onSubmit: (b: unknown) => void }) {
  const today = new Date().toLocaleDateString("sv-SE", { timeZone: "America/Lima" });
  const [paidOn, setPaidOn] = useState(quote.payment?.paid_on ?? today);
  const [amount, setAmount] = useState(String(Number(quote.payment?.amount ?? quote.total)));
  const [method, setMethod] = useState(quote.payment?.method ?? "transfer");
  const [reference, setReference] = useState(quote.payment?.reference ?? "");
  const differs = Number(amount) !== Number(quote.total);
  const isGroup = quote.companies.length > 1;

  return (
    <Modal
      title="Confirmar pago y crear la cuenta"
      subtitle={`${quote.number} · total ${money(quote.total)}`}
      icon="check"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" icon="check" loading={busy} onClick={() => onSubmit({ paid_on: paidOn, amount: Number(amount), method, reference: reference.trim() || null })}>
            Confirmar pago
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error}</p>}
        <p className="text-sm text-muted">Confirma solo si el dinero ya está en la cuenta de la empresa. Al confirmar:</p>
        <ul className="list-inside list-disc space-y-1 text-sm">
          <li>
            Se crea la cuenta{isGroup ? ` de grupo con ${quote.companies.length} empresas (cada una con su suscripción)` : ""}, o se activa la que ya tenía en prueba.
          </li>
          <li>Se registra el pago {isGroup ? "repartido entre las empresas" : ""} y queda el comprobante «SUNAT pendiente».</li>
          <li>A {quote.contact_email} le llega el correo para crear su contraseña.</li>
        </ul>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Fecha del pago">
            <input className="input" type="date" max={today} value={paidOn} onChange={(e) => setPaidOn(e.target.value)} />
          </FormField>
          <FormField label="Monto recibido (S/)" hint={differs ? "No coincide con el total cotizado." : undefined}>
            <input className="input tabular-nums" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value.replace(",", ".").replace(/[^\d.]/g, ""))} />
          </FormField>
          <FormField label="Medio">
            <select className="input" value={method} onChange={(e) => setMethod(e.target.value)}>
              {METHODS.map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="N.° de operación (opcional)">
            <input className="input" maxLength={120} value={reference} onChange={(e) => setReference(e.target.value)} />
          </FormField>
        </div>
        <p className="text-xs text-muted">
          Plan{isGroup ? "es" : ""}: {quote.companies.map((c) => `${c.legal_name} → ${PLAN_NAMES[c.plan_code]}`).join(" · ")}
        </p>
      </div>
    </Modal>
  );
}

function ReasonModal({
  title,
  hint,
  placeholder,
  busy,
  error,
  onClose,
  onSubmit,
}: {
  title: string;
  hint: string;
  placeholder: string;
  busy: boolean;
  error: string | null;
  onClose: () => void;
  onSubmit: (reason: string) => void;
}) {
  const [reason, setReason] = useState("");
  return (
    <Modal
      title={title}
      icon="close"
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="danger" loading={busy} disabled={reason.trim().length < 3} onClick={() => onSubmit(reason.trim())}>
            {title}
          </Button>
        </>
      }
    >
      {error && <p className="mb-3 rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error}</p>}
      <FormField label="Motivo" hint={hint}>
        <textarea className="input min-h-[90px]" maxLength={300} value={reason} placeholder={placeholder} onChange={(e) => setReason(e.target.value)} />
      </FormField>
    </Modal>
  );
}
