"use client";

// Página del cliente para su cotización: 1) la revisa y acepta (o no), 2) ve cómo pagar y envía su constancia,
// 3) espera la confirmación; al confirmar el pago le llega el correo para crear su contraseña.

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { day, money, PLAN_NAMES, type PublicQuote } from "@/lib/quotes";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
type Errors = Record<string, string[]>;

async function send(path: string, body: FormData | object) {
  try {
    const isForm = body instanceof FormData;
    const res = await fetch(`${API_URL}/api/v1/public/quotes/${path}`, {
      method: "POST",
      headers: isForm ? { Accept: "application/json" } : { Accept: "application/json", "Content-Type": "application/json" },
      body: isForm ? body : JSON.stringify(body),
    });
    if (res.ok) return { ok: true as const };
    const j = await res.json().catch(() => ({}));
    if (res.status === 429) return { ok: false as const, errors: {} as Errors, message: "Hiciste varios intentos seguidos. Espera unos minutos." };
    return { ok: false as const, errors: (j.errors ?? {}) as Errors, message: (j.errors ? "Revisa los campos marcados." : j.detail) ?? "No pudimos enviar. Inténtalo otra vez." };
  } catch {
    return { ok: false as const, errors: {} as Errors, message: "No hay conexión. Revisa tu internet e inténtalo otra vez." };
  }
}

const STEPS = ["Revisa", "Paga", "Accede"] as const;

export function QuoteClient({ token }: { token: string }) {
  const [q, setQ] = useState<PublicQuote | null>(null);
  const [missing, setMissing] = useState<string | null>(null);

  const load = useCallback(() => {
    fetch(`${API_URL}/api/v1/public/quotes/${token}`, { headers: { Accept: "application/json" }, cache: "no-store" })
      .then(async (r) => {
        const j = await r.json().catch(() => ({}));
        if (r.ok) setQ(j.data);
        else setMissing(j.detail ?? "No encontramos esta cotización.");
      })
      .catch(() => setMissing("No hay conexión. Revisa tu internet e inténtalo otra vez."));
  }, [token]);
  useEffect(load, [load]);

  if (missing)
    return (
      <div className="site-card space-y-3 p-8 text-center">
        <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[var(--site-surface)] text-[var(--site-text-2)]">
          <Icon name="lock" size={26} />
        </span>
        <h1 className="font-display text-2xl font-bold text-[var(--site-navy-title)]">Este enlace ya no está disponible</h1>
        <p className="text-[15px] text-[var(--site-text-2)]">{missing}</p>
        <p className="text-sm text-[var(--site-text-2)]">Si ya pagaste y confirmamos tu pago, revisa tu correo: ahí está el enlace para crear tu contraseña.</p>
        <Link href="/login" className="site-btn site-btn-outline mt-2 inline-flex">
          Iniciar sesión
        </Link>
      </div>
    );
  if (!q) return <p className="text-center text-[var(--site-text-2)]">Cargando tu cotización…</p>;

  const step = q.status === "sent" ? 1 : q.status === "accepted" ? 2 : q.status === "payment_submitted" ? 3 : q.status === "paid" ? 4 : 1;

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-xs font-bold uppercase tracking-[0.14em] text-[var(--site-action)]">Cotización {q.number}</p>
          <h1 className="mt-1 font-display text-3xl font-bold text-[var(--site-navy-title)]">Hola, {q.contact_name.split(" ")[0]}</h1>
          <p className="mt-1 text-[15px] text-[var(--site-text-2)]">
            {q.provider.trade_name || "EscudoSST"} · {q.provider.legal_name}
            {q.provider.ruc ? ` · RUC ${q.provider.ruc}` : ""}
          </p>
        </div>
        <a href={`${API_URL}/api/v1/public/quotes/${token}/pdf`} target="_blank" rel="noopener" className="site-btn site-btn-outline">
          <Icon name="file" size={16} /> Descargar PDF
        </a>
      </div>

      {!["rejected", "cancelled"].includes(q.status) && (
        <ol className="site-card flex items-center gap-2 p-4" aria-label="Pasos">
          {STEPS.map((t, i) => {
            const n = i + 1;
            const done = n < step;
            const now = n === step || (n === 3 && step === 4);
            return (
              <li key={t} className={`flex items-center gap-2 ${n < STEPS.length ? "flex-1" : ""}`}>
                <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-full text-sm font-bold ${done || step === 4 ? "bg-[#22A35A] text-white" : now ? "bg-[var(--site-navy)] text-white" : "bg-[var(--site-surface)] text-[var(--site-text-2)] ring-1 ring-[var(--site-border)]"}`}>
                  {done || step === 4 ? <Icon name="check" size={14} /> : n}
                </span>
                <span className={`text-sm ${now ? "font-bold text-[var(--site-navy-title)]" : "text-[var(--site-text-2)]"}`}>{t}</span>
                {n < STEPS.length && <span className={`h-0.5 flex-1 rounded ${done ? "bg-[#22A35A]" : "bg-[var(--site-border)]"}`} aria-hidden="true" />}
              </li>
            );
          })}
        </ol>
      )}

      <Detail q={q} />

      {q.status === "sent" && (q.expired ? <Expired q={q} /> : <Decide token={token} q={q} onDone={load} />)}
      {q.status === "accepted" && <Pay token={token} q={q} onDone={load} />}
      {q.status === "payment_submitted" && (
        <Notice icon="clock" tone="amber" title="Recibimos tu constancia">
          La estamos revisando. Cuando confirmemos el pago te llegará un correo a <b>{q.contact_email}</b> para crear tu contraseña y entrar a EscudoSST.
          {q.payment_instructions && <PaymentBox text={q.payment_instructions} total={q.total} />}
        </Notice>
      )}
      {q.status === "rejected" && (
        <Notice icon="close" tone="na" title="Indicaste que no aceptas esta cotización">
          Gracias por avisarnos. Si quieres una propuesta distinta, responde al correo que te enviamos.
        </Notice>
      )}
    </div>
  );
}

function Detail({ q }: { q: PublicQuote }) {
  const period = q.billing_period === "annual" ? "anual" : "mensual";
  return (
    <div className="site-card overflow-hidden">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--site-border)] px-5 py-4 sm:px-6">
        <h2 className="font-display text-lg font-bold text-[var(--site-navy-title)]">
          Detalle {q.companies.length > 1 ? `· grupo de ${q.companies.length} empresas` : ""}
        </h2>
        <span className="text-sm text-[var(--site-text-2)]">
          Pago {period} · válida hasta el {day(q.valid_until)}
        </span>
      </div>
      <div className="divide-y divide-[var(--site-border)]">
        {q.companies.map((c) => (
          <div key={c.ruc} className="px-5 py-4 sm:px-6">
            <p className="flex flex-wrap items-baseline justify-between gap-2">
              <b className="text-[var(--site-navy-title)]">{c.legal_name}</b>
              <span className="text-xs text-[var(--site-text-2)]">
                RUC {c.ruc} · {c.headcount} trabajadores · plan {PLAN_NAMES[c.plan_code]}
              </span>
            </p>
            <ul className="mt-2 space-y-1 text-sm">
              {(c.lines ?? []).map((l) => (
                <li key={l.code} className="flex justify-between gap-3">
                  <span>{l.name}</span>
                  <span className="tabular-nums">{money(l.amount)}</span>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
      <div className="space-y-1 bg-[var(--site-surface)] px-5 py-4 text-sm sm:px-6">
        {Number(q.discount_amount) > 0 && (
          <>
            <p className="flex justify-between">
              <span>Subtotal</span>
              <span className="tabular-nums">{money(q.subtotal)}</span>
            </p>
            <p className="flex justify-between">
              <span>Descuento</span>
              <span className="tabular-nums">− {money(q.discount_amount)}</span>
            </p>
          </>
        )}
        <p className="flex items-baseline justify-between">
          <b className="text-[var(--site-navy-title)]">Total {q.billing_period === "annual" ? "por el año" : "por el mes"}</b>
          <b className="font-display text-2xl tabular-nums text-[var(--site-navy-title)]">{money(q.total)}</b>
        </p>
        {q.billing_period === "annual" && q.annual_discount_pct > 0 && <p className="text-xs text-[var(--site-text-2)]">Incluye {q.annual_discount_pct} % de descuento por pago anual.</p>}
        <p className="text-xs text-[var(--site-text-2)]">Montos en soles (PEN).</p>
      </div>
      {q.client_notes && <p className="whitespace-pre-line border-t border-[var(--site-border)] px-5 py-4 text-sm sm:px-6">{q.client_notes}</p>}
    </div>
  );
}

function Decide({ token, q, onDone }: { token: string; q: PublicQuote; onDone: () => void }) {
  const [agree, setAgree] = useState(false);
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  async function go(path: "accept" | "reject") {
    setBusy(true);
    setMessage(null);
    const r = await send(`${token}/${path}`, path === "accept" ? { accept: agree } : { reason: reason.trim() || null });
    setBusy(false);
    if (r.ok) onDone();
    else setMessage(r.errors.accept?.[0] ?? r.message);
  }

  return (
    <div className="site-card space-y-4 p-5 sm:p-6">
      <h2 className="font-display text-lg font-bold text-[var(--site-navy-title)]">¿Aceptas la cotización?</h2>
      {message && <p role="alert" className="rounded-xl border-l-4 border-[var(--site-red)] bg-[#E2453C]/8 px-4 py-3 text-sm font-semibold text-[#A3271F]">{message}</p>}
      {!rejecting ? (
        <>
          <label className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-sm ${agree ? "border-[var(--site-action)]/40 bg-[var(--site-surface)]" : "border-[#d5dde7]"}`}>
            <input type="checkbox" className="mt-0.5 h-4 w-4 shrink-0 accent-[var(--site-action)]" checked={agree} onChange={(e) => setAgree(e.target.checked)} />
            <span>
              Acepto la cotización {q.number} por <b>{money(q.total)}</b> y sus condiciones, y los{" "}
              <Link href="/legal/terminos-y-condiciones" target="_blank" className="font-semibold text-[var(--site-action)] underline">
                Términos y condiciones
              </Link>
              .
            </span>
          </label>
          <div className="flex flex-col gap-3 sm:flex-row">
            <button type="button" disabled={!agree || busy} onClick={() => go("accept")} className="site-btn site-btn-primary flex-1 disabled:opacity-50">
              <Icon name="check" size={16} /> {busy ? "Enviando…" : "Acepto"}
            </button>
            <button type="button" onClick={() => setRejecting(true)} className="site-btn site-btn-outline sm:w-48">
              No acepto
            </button>
          </div>
          <p className="text-xs text-[var(--site-text-2)]">Al aceptar verás los datos para pagar. Tu cuenta se crea cuando confirmemos el pago.</p>
        </>
      ) : (
        <>
          <label className="block">
            <span className="mb-1.5 block text-sm font-semibold text-[var(--site-navy-title)]">
              ¿Nos cuentas por qué? <span className="font-normal text-[var(--site-text-2)]">(opcional)</span>
            </span>
            <textarea className="input min-h-[90px]" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ej.: el precio, necesitamos otro plan, aún no es el momento" />
          </label>
          <div className="flex flex-col gap-3 sm:flex-row">
            <button type="button" disabled={busy} onClick={() => go("reject")} className="site-btn site-btn-primary flex-1 disabled:opacity-50">
              {busy ? "Enviando…" : "Confirmar que no acepto"}
            </button>
            <button type="button" onClick={() => setRejecting(false)} className="site-btn site-btn-outline sm:w-48">
              Volver
            </button>
          </div>
        </>
      )}
    </div>
  );
}

function Pay({ token, q, onDone }: { token: string; q: PublicQuote; onDone: () => void }) {
  const today = new Date().toLocaleDateString("sv-SE", { timeZone: "America/Lima" });
  const [method, setMethod] = useState("transfer");
  const [paidOn, setPaidOn] = useState(today);
  const [amount, setAmount] = useState(String(Number(q.total)));
  const [reference, setReference] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [errors, setErrors] = useState<Errors>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const err = (k: string) => errors[k]?.[0];

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!file) {
      setErrors({ file: ["Adjunta la constancia de pago."] });
      return;
    }
    setBusy(true);
    setMessage(null);
    const fd = new FormData();
    fd.append("method", method);
    fd.append("paid_on", paidOn);
    fd.append("amount", amount);
    if (reference.trim()) fd.append("reference", reference.trim());
    fd.append("file", file);
    const r = await send(`${token}/payment`, fd);
    setBusy(false);
    if (r.ok) onDone();
    else {
      setErrors(r.errors);
      setMessage(r.message);
    }
  }

  return (
    <div className="space-y-5">
      {q.evidence_rejected_reason && (
        <Notice icon="close" tone="bad" title="Tu constancia anterior no se pudo validar">
          Motivo: {q.evidence_rejected_reason}. Envía una nueva constancia abajo.
        </Notice>
      )}
      <div className="site-card space-y-3 p-5 sm:p-6">
        <h2 className="font-display text-lg font-bold text-[var(--site-navy-title)]">1. Paga {money(q.total)}</h2>
        {q.payment_instructions ? <PaymentBox text={q.payment_instructions} total={q.total} /> : <p className="text-sm text-[var(--site-text-2)]">Te enviaremos los datos para el pago por correo.</p>}
      </div>
      <form onSubmit={submit} noValidate className="site-card space-y-4 p-5 sm:p-6">
        <h2 className="font-display text-lg font-bold text-[var(--site-navy-title)]">2. Envíanos la constancia</h2>
        {message && <p role="alert" className="rounded-xl border-l-4 border-[var(--site-red)] bg-[#E2453C]/8 px-4 py-3 text-sm font-semibold text-[#A3271F]">{message}</p>}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="¿Cómo pagaste?" error={err("method")}>
            <select className="input" value={method} onChange={(e) => setMethod(e.target.value)}>
              {Object.entries(q.methods).map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Fecha del pago" error={err("paid_on")}>
            <input className="input" type="date" max={today} value={paidOn} onChange={(e) => setPaidOn(e.target.value)} />
          </Field>
          <Field label="Monto pagado (S/)" hint={Number(amount) !== Number(q.total) ? `El total es ${money(q.total)}.` : undefined} error={err("amount")}>
            <input className="input tabular-nums" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value.replace(",", ".").replace(/[^\d.]/g, ""))} />
          </Field>
          <Field label="N.° de operación (opcional)" hint="Figura en tu voucher o en la app de tu banco." error={err("reference")}>
            <input className="input" maxLength={120} value={reference} onChange={(e) => setReference(e.target.value)} />
          </Field>
        </div>
        <Field label="Constancia (PDF, JPG o PNG, hasta 5 MB)" error={err("file")}>
          <input
            className="input file:mr-3 file:rounded-lg file:border-0 file:bg-[var(--site-surface)] file:px-3 file:py-1.5 file:font-semibold"
            type="file"
            accept="application/pdf,image/jpeg,image/png,image/webp"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          />
        </Field>
        <button type="submit" disabled={busy} className="site-btn site-btn-primary w-full disabled:opacity-60">
          {busy ? "Enviando…" : "Enviar constancia"}
        </button>
        <p className="text-center text-xs text-[var(--site-text-2)]">Revisamos tu pago y, al confirmarlo, te llega el correo para crear tu contraseña.</p>
      </form>
    </div>
  );
}

function Expired({ q }: { q: PublicQuote }) {
  return (
    <Notice icon="clock" tone="amber" title="Esta cotización venció">
      Venció el {day(q.valid_until)}. Responde al correo que te enviamos y te mandamos una actualizada.
    </Notice>
  );
}

function PaymentBox({ text, total }: { text: string; total: string }) {
  return (
    <div className="mt-3 rounded-xl border border-[var(--site-border)] bg-white p-4 text-sm">
      <p className="mb-2 text-[var(--site-text-2)]">
        Total a pagar: <b className="text-[var(--site-navy-title)]">{money(total)}</b>
      </p>
      <p className="whitespace-pre-line font-medium text-[var(--site-navy-title)]">{text}</p>
    </div>
  );
}

function Notice({ icon, tone, title, children }: { icon: string; tone: "amber" | "bad" | "na"; title: string; children: React.ReactNode }) {
  const tones = { amber: "bg-[#F4B90F]/15 text-[#6B5200]", bad: "bg-[#E2453C]/10 text-[#A3271F]", na: "bg-[var(--site-surface)] text-[var(--site-text-2)]" };
  return (
    <div className="site-card flex items-start gap-4 p-5 sm:p-6">
      <span className={`grid h-11 w-11 shrink-0 place-items-center rounded-xl ${tones[tone]}`}>
        <Icon name={icon} size={22} />
      </span>
      <div className="min-w-0 flex-1 text-[15px] text-[var(--site-text-2)]">
        <h2 className="mb-1 font-display text-lg font-bold text-[var(--site-navy-title)]">{title}</h2>
        {children}
      </div>
    </div>
  );
}

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-semibold text-[var(--site-navy-title)]">{label}</span>
      {children}
      {error ? <span className="mt-1.5 block text-xs font-semibold text-[var(--site-red)]">● {error}</span> : hint && <span className="mt-1.5 block text-xs text-[var(--site-text-2)]">{hint}</span>}
    </label>
  );
}
