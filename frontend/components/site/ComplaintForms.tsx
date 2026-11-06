"use client";

// Libro de Reclamaciones virtual (SCR-WEB-17 y 18): hoja de reclamación y seguimiento por código + clave.
// Los campos los fija el D.S. 011-2011-PCM; al enviar se muestra la constancia (imprimible o guardable en PDF).

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Icon } from "@/components/ui/Icon";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

type Errors = Record<string, string[]>;
export type Provider = { legal_name: string | null; trade_name: string | null; ruc: string | null; legal_address: string | null };

async function post(path: string, body: unknown) {
  try {
    const res = await fetch(`${API_URL}/api/v1/public/${path}`, {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const json = await res.json().catch(() => ({}));
    if (res.ok) return { ok: true as const, data: json.data };
    if (res.status === 429) return { ok: false as const, errors: {} as Errors, message: "Hiciste varios intentos seguidos. Espera unos minutos e inténtalo otra vez." };
    return { ok: false as const, errors: (json.errors ?? {}) as Errors, message: res.status === 422 ? "Revisa los campos marcados." : "No pudimos enviar la hoja. Inténtalo de nuevo en un momento." };
  } catch {
    return { ok: false as const, errors: {} as Errors, message: "No hay conexión. Revisa tu internet e inténtalo otra vez." };
  }
}

const fmtDate = (d: string, time = false) =>
  new Intl.DateTimeFormat("es-PE", { dateStyle: "long", ...(time ? { timeStyle: "short" } : {}), timeZone: "America/Lima" }).format(new Date(d.length === 10 ? `${d}T12:00:00-05:00` : d));

function Field({ label, error, hint, optional, children, className = "" }: { label: string; error?: string; hint?: string; optional?: boolean; children: React.ReactNode; className?: string }) {
  return (
    <label className={`block ${className}`}>
      <span className="mb-1.5 block text-sm font-semibold text-[var(--site-navy-title)]">
        {label} {optional && <span className="font-normal text-[var(--site-text-2)]">(opcional)</span>}
      </span>
      {children}
      {error ? <span className="mt-1.5 block text-xs font-semibold text-[var(--site-red)]">● {error}</span> : hint && <span className="mt-1.5 block text-xs text-[var(--site-text-2)]">{hint}</span>}
    </label>
  );
}

function Section({ n, title, children }: { n: number; title: string; children: React.ReactNode }) {
  return (
    <fieldset className="space-y-4 border-t border-[var(--site-border)] pt-6 first:border-0 first:pt-0">
      <legend className="mb-1 flex items-center gap-2.5 font-display text-lg font-bold text-[var(--site-navy-title)]">
        <span className="grid h-7 w-7 place-items-center rounded-full bg-[var(--site-navy)] text-sm text-white">{n}</span>
        {title}
      </legend>
      {children}
    </fieldset>
  );
}

const DOCS: [string, string][] = [
  ["DNI", "DNI"],
  ["CE", "Carné de extranjería"],
  ["PASSPORT", "Pasaporte"],
  ["RUC", "RUC"],
  ["OTHER", "Otro"],
];

type Receipt = { complaint_code: string; filed_at: string; response_due_on: string; tracking_key: string };

export function ComplaintForm({ provider, responseDays }: { provider: Provider; responseDays: number | null }) {
  const [f, setF] = useState({
    consumer_full_name: "",
    consumer_document_type: "DNI",
    consumer_document_number: "",
    consumer_address: "",
    consumer_email: "",
    consumer_phone: "",
    guardian_full_name: "",
    guardian_document_type: "DNI",
    guardian_document_number: "",
    guardian_email: "",
    guardian_phone: "",
    item_type: "service",
    item_description: "",
    amount_claimed: "",
    complaint_type: "",
    detail: "",
    consumer_request: "",
    website: "",
  });
  const [minor, setMinor] = useState(false);
  const [privacy, setPrivacy] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [receipt, setReceipt] = useState<(Receipt & { type: string; detail: string; request: string; name: string }) | null>(null);
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
  const err = (k: string) => errors[k]?.[0];
  const inv = (k: string) => (err(k) ? true : undefined);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMessage(null);
    const nul = (v: string) => (v.trim() === "" ? null : v.trim());
    const r = await post("complaints", {
      ...f,
      consumer_address: nul(f.consumer_address),
      consumer_phone: nul(f.consumer_phone),
      is_minor: minor,
      guardian_full_name: minor ? nul(f.guardian_full_name) : null,
      guardian_document_type: minor ? f.guardian_document_type : null,
      guardian_document_number: minor ? nul(f.guardian_document_number) : null,
      guardian_email: minor ? nul(f.guardian_email) : null,
      guardian_phone: minor ? nul(f.guardian_phone) : null,
      amount_claimed: f.amount_claimed === "" ? null : Number(f.amount_claimed),
      complaint_type: f.complaint_type || null,
      privacy_consent: privacy,
    });
    setSaving(false);
    if (r.ok && r.data.complaint_code) {
      setReceipt({ ...r.data, type: f.complaint_type, detail: f.detail, request: f.consumer_request, name: f.consumer_full_name });
      setTimeout(() => window.scrollTo({ top: 0, behavior: "smooth" }), 50);
    } else if (!r.ok) {
      setErrors(r.errors);
      setMessage(r.message);
      setTimeout(() => document.querySelector('[role="alert"]')?.scrollIntoView({ behavior: "smooth", block: "center" }), 50);
    }
  }

  if (receipt) return <ReceiptView r={receipt} provider={provider} />;

  return (
    <form onSubmit={submit} noValidate className="site-card relative space-y-6 p-6 sm:p-8">
      <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
        <input tabIndex={-1} autoComplete="off" name="website" value={f.website} onChange={(e) => set("website", e.target.value)} />
      </div>
      {message && (
        <p role="alert" className="rounded-xl border-l-4 border-[var(--site-red)] bg-[#E2453C]/8 px-4 py-3 text-sm font-semibold text-[#A3271F]">
          {message}
        </p>
      )}

      <Section n={1} title="Tus datos">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Nombres y apellidos" error={err("consumer_full_name")} className="sm:col-span-2">
            <input className="input" maxLength={160} autoComplete="name" value={f.consumer_full_name} aria-invalid={inv("consumer_full_name")} onChange={(e) => set("consumer_full_name", e.target.value)} />
          </Field>
          <Field label="Tipo de documento" error={err("consumer_document_type")}>
            <select className="input" value={f.consumer_document_type} onChange={(e) => set("consumer_document_type", e.target.value)}>
              {DOCS.map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Número de documento" error={err("consumer_document_number")}>
            <input className="input tabular-nums" maxLength={20} value={f.consumer_document_number} aria-invalid={inv("consumer_document_number")} onChange={(e) => set("consumer_document_number", e.target.value.trim())} />
          </Field>
          <Field label="Correo" error={err("consumer_email")} hint="Aquí te enviamos la constancia y la respuesta.">
            <input className="input" type="email" maxLength={160} autoComplete="email" value={f.consumer_email} aria-invalid={inv("consumer_email")} onChange={(e) => set("consumer_email", e.target.value)} />
          </Field>
          <Field label="Celular" optional error={err("consumer_phone")}>
            <input className="input" type="tel" maxLength={30} autoComplete="tel" value={f.consumer_phone} onChange={(e) => set("consumer_phone", e.target.value)} />
          </Field>
          <Field label="Domicilio" error={err("consumer_address")} className="sm:col-span-2">
            <input className="input" maxLength={250} autoComplete="street-address" value={f.consumer_address} aria-invalid={inv("consumer_address")} onChange={(e) => set("consumer_address", e.target.value)} />
          </Field>
        </div>
        <label className="flex cursor-pointer items-center gap-3 text-sm">
          <input type="checkbox" className="h-4 w-4 accent-[var(--site-action)]" checked={minor} onChange={(e) => setMinor(e.target.checked)} />
          Soy menor de edad <span className="text-[var(--site-text-2)]">(se piden los datos de tu padre, madre o apoderado)</span>
        </label>
        {minor && (
          <div className="grid grid-cols-1 gap-4 rounded-xl bg-[var(--site-surface)] p-4 sm:grid-cols-2">
            <Field label="Nombre del padre, madre o apoderado" error={err("guardian_full_name")} className="sm:col-span-2">
              <input className="input" maxLength={160} value={f.guardian_full_name} aria-invalid={inv("guardian_full_name")} onChange={(e) => set("guardian_full_name", e.target.value)} />
            </Field>
            <Field label="Tipo de documento" error={err("guardian_document_type")}>
              <select className="input" value={f.guardian_document_type} onChange={(e) => set("guardian_document_type", e.target.value)}>
                {DOCS.filter(([v]) => v !== "RUC").map(([v, l]) => (
                  <option key={v} value={v}>
                    {l}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Número de documento" error={err("guardian_document_number")}>
              <input className="input tabular-nums" maxLength={20} value={f.guardian_document_number} aria-invalid={inv("guardian_document_number")} onChange={(e) => set("guardian_document_number", e.target.value.trim())} />
            </Field>
            <Field label="Correo" optional error={err("guardian_email")}>
              <input className="input" type="email" maxLength={160} value={f.guardian_email} onChange={(e) => set("guardian_email", e.target.value)} />
            </Field>
            <Field label="Celular" optional error={err("guardian_phone")}>
              <input className="input" type="tel" maxLength={30} value={f.guardian_phone} onChange={(e) => set("guardian_phone", e.target.value)} />
            </Field>
          </div>
        )}
      </Section>

      <Section n={2} title="¿Qué contrataste?">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Es un…" error={err("item_type")}>
            <select className="input" value={f.item_type} onChange={(e) => set("item_type", e.target.value)}>
              <option value="service">Servicio (suscripción, soporte, curso)</option>
              <option value="product">Producto</option>
            </select>
          </Field>
          <Field label="Monto reclamado (S/)" optional error={err("amount_claimed")}>
            <input className="input tabular-nums" inputMode="decimal" value={f.amount_claimed} onChange={(e) => set("amount_claimed", e.target.value.replace(",", ".").replace(/[^\d.]/g, ""))} />
          </Field>
          <Field label="¿Cuál?" error={err("item_description")} hint="Ej.: plan Profesional, curso de primeros auxilios." className="sm:col-span-2">
            <input className="input" maxLength={500} value={f.item_description} aria-invalid={inv("item_description")} onChange={(e) => set("item_description", e.target.value)} />
          </Field>
        </div>
      </Section>

      <Section n={3} title="Tu reclamo o queja">
        <div role="radiogroup" aria-label="Tipo" className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {(
            [
              ["claim", "Reclamo", "Algo de lo que contrataste falló. Ej.: un cobro mal hecho."],
              ["complaint", "Queja", "No te gustó cómo te atendieron."],
            ] as const
          ).map(([v, title, text]) => (
            <button
              key={v}
              type="button"
              role="radio"
              aria-checked={f.complaint_type === v}
              onClick={() => set("complaint_type", v)}
              className={`rounded-xl border p-4 text-left transition ${f.complaint_type === v ? "border-[var(--site-action)] bg-[var(--site-action)]/8 ring-1 ring-[var(--site-action)]" : "border-[#d5dde7] bg-white hover:border-[var(--site-action)]/50"}`}
            >
              <b className="flex items-center gap-2 font-display text-[var(--site-navy-title)]">
                <span className={`grid h-4 w-4 place-items-center rounded-full border ${f.complaint_type === v ? "border-[var(--site-action)]" : "border-[#c3ccd8]"}`}>
                  {f.complaint_type === v && <span className="h-2 w-2 rounded-full bg-[var(--site-action)]" />}
                </span>
                {title}
              </b>
              <span className="mt-1 block text-sm text-[var(--site-text-2)]">{text}</span>
            </button>
          ))}
        </div>
        {err("complaint_type") && <p className="text-xs font-semibold text-[var(--site-red)]">● Elige si es reclamo o queja.</p>}
        <Field label="¿Qué pasó?" error={err("detail")} hint="Con fechas si las tienes.">
          <textarea className="input min-h-[120px]" maxLength={4000} value={f.detail} aria-invalid={inv("detail")} onChange={(e) => set("detail", e.target.value)} />
        </Field>
        <Field label="¿Qué solución esperas?" error={err("consumer_request")}>
          <textarea className="input min-h-[80px]" maxLength={2000} value={f.consumer_request} aria-invalid={inv("consumer_request")} onChange={(e) => set("consumer_request", e.target.value)} />
        </Field>
      </Section>

      <div className="space-y-2">
        <label className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-sm ${privacy ? "border-[var(--site-action)]/40 bg-[var(--site-surface)]" : "border-[#d5dde7]"}`}>
          <input type="checkbox" className="mt-0.5 h-4 w-4 shrink-0 accent-[var(--site-action)]" checked={privacy} onChange={(e) => setPrivacy(e.target.checked)} />
          <span>
            Acepto que usen mis datos para atender esta hoja (ver{" "}
            <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-[var(--site-action)] underline">
              Política de privacidad
            </Link>
            ).
          </span>
        </label>
        {err("privacy_consent") && <p className="text-xs font-semibold text-[var(--site-red)]">● {err("privacy_consent")}</p>}
      </div>

      <button type="submit" disabled={saving} className="site-btn site-btn-primary w-full disabled:opacity-60">
        {saving ? "Enviando…" : "Enviar hoja de reclamación"}
      </button>
      <p className="text-center text-xs leading-relaxed text-[var(--site-text-2)]">
        {responseDays ? `Te responderemos por correo en máximo ${responseDays} días hábiles. ` : ""}
        Presentar esta hoja no te impide acudir a otras vías ni es requisito para denunciar ante el INDECOPI.
      </p>
    </form>
  );
}

function ReceiptView({ r, provider }: { r: Receipt & { type: string; detail: string; request: string; name: string }; provider: Provider }) {
  const type = r.type === "claim" ? "Reclamo" : "Queja";
  const rows: [string, string][] = [
    ["Código", r.complaint_code],
    ["Clave de seguimiento", r.tracking_key],
    ["Tipo", type],
    ["Registrada el", fmtDate(r.filed_at, true)],
    ["Respuesta a más tardar el", fmtDate(r.response_due_on)],
    ["Consumidor", r.name],
    ["Proveedor", `${provider.legal_name ?? ""}${provider.ruc ? ` · RUC ${provider.ruc}` : ""}`],
  ];
  return (
    <div className="site-card space-y-6 p-6 sm:p-8 print:shadow-none" role="status">
      <div className="flex items-start gap-4">
        <span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-[#22A35A]/12 text-[#167A42]">
          <Icon name="check" size={28} />
        </span>
        <div>
          <h2 className="font-display text-2xl font-bold text-[var(--site-navy-title)]">Constancia de tu {type.toLowerCase()}</h2>
          <p className="mt-1 text-[15px] text-[var(--site-text-2)]">Registramos tu hoja. También te enviamos esta constancia por correo.</p>
        </div>
      </div>
      <dl className="divide-y divide-[var(--site-border)] rounded-xl border border-[var(--site-border)]">
        {rows.map(([k, v]) => (
          <div key={k} className="flex flex-wrap justify-between gap-2 px-4 py-3 text-sm">
            <dt className="text-[var(--site-text-2)]">{k}</dt>
            <dd className={`font-semibold text-[var(--site-navy-title)] ${k.startsWith("Clave") || k === "Código" ? "font-mono" : ""}`}>{v}</dd>
          </div>
        ))}
      </dl>
      <div className="space-y-2 text-sm">
        <p>
          <b>Detalle:</b> {r.detail}
        </p>
        <p>
          <b>Pedido:</b> {r.request}
        </p>
      </div>
      <p className="rounded-xl bg-[#F4B90F]/15 px-4 py-3 text-sm text-[#6B5200]">
        Guarda el <b>código</b> y la <b>clave de seguimiento</b>: con ambos puedes consultar el estado de tu hoja.
      </p>
      <div className="flex flex-col gap-3 sm:flex-row print:hidden">
        <button type="button" onClick={() => window.print()} className="site-btn site-btn-primary flex-1">
          <Icon name="file" size={16} /> Descargar o imprimir constancia
        </button>
        <Link href={`/libro-de-reclamaciones/seguimiento?codigo=${r.complaint_code}`} className="site-btn site-btn-outline flex-1">
          Ver el estado
        </Link>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- Seguimiento

const STATUS: Record<string, [string, string]> = {
  received: ["Recibida", "Registramos tu hoja y la revisaremos."],
  in_progress: ["En revisión", "Estamos revisando tu caso."],
  extended: ["Plazo extendido", "Necesitamos más tiempo para responder; te avisamos por correo."],
  answered: ["Respondida", "Te enviamos la respuesta a tu correo."],
  closed: ["Cerrada", "El caso está cerrado. La respuesta está en tu correo."],
};

export function TrackingForm({ initialCode = "" }: { initialCode?: string }) {
  const [code, setCode] = useState(initialCode);
  const [key, setKey] = useState("");
  const [result, setResult] = useState<Record<string, string | null> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setResult(null);
    const r = await post("complaints/status", { code, key });
    setSaving(false);
    if (r.ok) setResult(r.data);
    else setError(r.errors.code?.[0] ?? r.errors.key?.[0] ?? r.message);
  }

  return (
    <div className="space-y-5">
      <form onSubmit={submit} noValidate className="site-card space-y-4 p-6 sm:p-8">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Código" hint="Ej.: LR-2026-000001">
            <input className="input font-mono uppercase" maxLength={20} value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} />
          </Field>
          <Field label="Clave de seguimiento" hint="Está en tu constancia y en el correo.">
            <input className="input font-mono uppercase" maxLength={40} value={key} onChange={(e) => setKey(e.target.value.toUpperCase().trim())} />
          </Field>
        </div>
        {error && (
          <p role="alert" className="rounded-xl border-l-4 border-[var(--site-red)] bg-[#E2453C]/8 px-4 py-3 text-sm font-semibold text-[#A3271F]">
            {error}
          </p>
        )}
        <button type="submit" disabled={saving || !code || !key} className="site-btn site-btn-primary w-full disabled:opacity-60">
          {saving ? "Buscando…" : "Consultar estado"}
        </button>
      </form>
      {result && (
        <div className="site-card space-y-4 p-6 sm:p-8" role="status">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="font-mono text-lg font-bold text-[var(--site-navy-title)]">{result.complaint_code}</p>
            <span className="rounded-full bg-[var(--site-action)]/10 px-3 py-1 text-sm font-bold text-[var(--site-navy)]">{STATUS[result.status ?? ""]?.[0]}</span>
          </div>
          <p className="text-[15px] text-[var(--site-text-2)]">{STATUS[result.status ?? ""]?.[1]}</p>
          <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-[var(--site-text-2)]">Registrada</dt>
              <dd className="font-semibold">{result.filed_at && fmtDate(result.filed_at, true)}</dd>
            </div>
            <div>
              <dt className="text-[var(--site-text-2)]">Respuesta a más tardar</dt>
              <dd className="font-semibold">{fmtDate((result.extended_until ?? result.response_due_on) as string)}</dd>
            </div>
            {result.answered_at && (
              <div>
                <dt className="text-[var(--site-text-2)]">Respondida</dt>
                <dd className="font-semibold">{fmtDate(result.answered_at, true)}</dd>
              </div>
            )}
          </dl>
        </div>
      )}
    </div>
  );
}
