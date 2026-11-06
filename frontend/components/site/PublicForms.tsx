"use client";

// Formularios públicos del sitio: «Prueba gratis» (registro con RUC) y «Agenda una demo» / cotización / contacto.
// Envían a la API pública; el campo «website» es una trampa para bots (no se ve ni se lee con lector de pantalla).

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { isValidRuc, SECTORS } from "@/lib/accounts";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

type Errors = Record<string, string[]>;

async function post(path: string, body: unknown): Promise<{ ok: true; data: Record<string, string> } | { ok: false; errors: Errors; message: string }> {
  try {
    const res = await fetch(`${API_URL}/api/v1/public/${path}`, {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const json = await res.json().catch(() => ({}));
    if (res.ok) return { ok: true, data: json.data ?? {} };
    if (res.status === 429) return { ok: false, errors: {}, message: "Recibimos varios envíos seguidos. Espera unos minutos e inténtalo otra vez." };
    return { ok: false, errors: json.errors ?? {}, message: res.status === 422 ? "Revisa los campos marcados." : "No pudimos enviar el formulario. Inténtalo de nuevo en un momento." };
  } catch {
    return { ok: false, errors: {}, message: "No hay conexión. Revisa tu internet e inténtalo otra vez." };
  }
}

function Field({ label, error, hint, optional, children, className = "" }: { label: string; error?: string; hint?: string; optional?: boolean; children: React.ReactNode; className?: string }) {
  return (
    <label className={`block ${className}`}>
      <span className="mb-1.5 block text-sm font-semibold text-[var(--site-navy-title)]">
        {label} {optional && <span className="font-normal text-[var(--site-text-2)]">(opcional)</span>}
      </span>
      {children}
      {error ? (
        <span className="mt-1.5 block text-xs font-semibold text-[var(--site-red)]">● {error}</span>
      ) : (
        hint && <span className="mt-1.5 block text-xs text-[var(--site-text-2)]">{hint}</span>
      )}
    </label>
  );
}

function Honeypot({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return (
    <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
      <label>
        No llenar
        <input tabIndex={-1} autoComplete="off" value={value} onChange={(e) => onChange(e.target.value)} name="website" />
      </label>
    </div>
  );
}

function Check({ checked, onChange, error, children }: { checked: boolean; onChange: (v: boolean) => void; error?: string; children: React.ReactNode }) {
  return (
    <div>
      <label className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-sm transition-colors ${checked ? "border-[var(--site-action)]/40 bg-[var(--site-surface)]" : "border-[#d5dde7]"}`}>
        <input type="checkbox" className="mt-0.5 h-4 w-4 shrink-0 accent-[var(--site-action)]" checked={checked} onChange={(e) => onChange(e.target.checked)} />
        <span className="text-[var(--site-text)]">{children}</span>
      </label>
      {error && <span className="mt-1.5 block text-xs font-semibold text-[var(--site-red)]">● {error}</span>}
    </div>
  );
}

function Alert({ text }: { text: string | null }) {
  if (!text) return null;
  return (
    <p role="alert" className="rounded-xl border-l-4 border-[var(--site-red)] bg-[#E2453C]/8 px-4 py-3 text-sm font-semibold text-[#A3271F]">
      {text}
    </p>
  );
}

function Done({ icon, title, children }: { icon: string; title: string; children: React.ReactNode }) {
  return (
    <div className="site-card p-8 text-center sm:p-10" role="status">
      <span className="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-[#22A35A]/12 text-[#167A42]">
        <Icon name={icon} size={30} />
      </span>
      <h2 className="mt-5 font-display text-2xl font-bold text-[var(--site-navy-title)]">{title}</h2>
      <div className="mx-auto mt-3 max-w-md space-y-3 text-[15px] leading-relaxed text-[var(--site-text-2)]">{children}</div>
    </div>
  );
}

function Submit({ saving, children }: { saving: boolean; children: React.ReactNode }) {
  return (
    <button type="submit" disabled={saving} className="site-btn site-btn-primary w-full disabled:opacity-60">
      {saving ? "Enviando…" : children}
      {!saving && <Icon name="chevron" size={16} />}
    </button>
  );
}

/** Lleva al primer campo con error (o al aviso) para que se vea en pantallas pequeñas. */
function focusFirstError() {
  setTimeout(() => {
    const el = document.querySelector<HTMLElement>('form [aria-invalid="true"], form [role="alert"]');
    el?.scrollIntoView({ behavior: "smooth", block: "center" });
    if (el instanceof HTMLInputElement) el.focus({ preventScroll: true });
  }, 50);
}

/** Al terminar, el mensaje de éxito reemplaza al formulario: se sube para que se vea. */
function scrollToForm() {
  setTimeout(() => document.querySelector('[role="status"]')?.scrollIntoView({ behavior: "smooth", block: "center" }), 50);
}

const onlyDigits = (v: string, max: number) => v.replace(/\D/g, "").slice(0, max);

// ---------------------------------------------------------------- Prueba gratis

export function TrialForm() {
  const [f, setF] = useState({ ruc: "", legal_name: "", trade_name: "", economic_sector: "", headcount: "", full_name: "", email: "", phone: "", website: "" });
  const [accept, setAccept] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [sentTo, setSentTo] = useState<string | null>(null);
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
  const err = (k: string) => errors[k]?.[0];
  const rucState = f.ruc.length === 11 ? (isValidRuc(f.ruc) ? "ok" : "bad") : null;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMessage(null);
    const r = await post("trial", {
      ...f,
      trade_name: f.trade_name.trim() || null,
      economic_sector: f.economic_sector || null,
      headcount: f.headcount === "" ? null : Number(f.headcount),
      phone: f.phone.trim() || null,
      accept_legal: accept,
    });
    setSaving(false);
    if (r.ok) {
      setSentTo(r.data.email ?? f.email);
      scrollToForm();
    }
    else {
      setErrors(r.errors);
      setMessage(r.message);
      focusFirstError();
    }
  }

  if (sentTo)
    return (
      <Done icon="mail" title="¡Listo! Revisa tu correo">
        <p>
          Te enviamos un enlace a <b className="text-[var(--site-navy-title)]">{sentTo}</b> para crear tu contraseña y entrar a EscudoSST.
        </p>
        <p className="text-sm">Si no lo ves en unos minutos, revisa la carpeta de spam o promociones.</p>
      </Done>
    );

  return (
    <form onSubmit={submit} noValidate className="site-card relative space-y-6 p-6 sm:p-8">
      <Honeypot value={f.website} onChange={(v) => set("website", v)} />
      <Alert text={message} />

      <fieldset className="space-y-4">
        <legend className="mb-1 flex items-center gap-2 font-display text-lg font-bold text-[var(--site-navy-title)]">
          <span className="grid h-8 w-8 place-items-center rounded-lg bg-[var(--site-surface)] text-[var(--site-action)]">
            <Icon name="building" size={17} />
          </span>
          Tu empresa
        </legend>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field
            label="RUC"
            error={err("ruc") ?? (rucState === "bad" ? "Revisa el RUC: el último dígito no coincide." : undefined)}
            hint={rucState === "ok" ? "RUC válido." : "11 dígitos."}
          >
            <input className="input tabular-nums" inputMode="numeric" autoComplete="off" value={f.ruc} aria-invalid={!!err("ruc") || rucState === "bad" || undefined} onChange={(e) => set("ruc", onlyDigits(e.target.value, 11))} />
          </Field>
          <Field label="Razón social" error={err("legal_name")}>
            <input className="input" maxLength={160} autoComplete="organization" value={f.legal_name} aria-invalid={!!err("legal_name") || undefined} onChange={(e) => set("legal_name", e.target.value)} />
          </Field>
          <Field label="Nombre comercial" optional error={err("trade_name")}>
            <input className="input" maxLength={160} value={f.trade_name} onChange={(e) => set("trade_name", e.target.value)} />
          </Field>
          <Field label="Sector" optional error={err("economic_sector")}>
            <select className="input" value={f.economic_sector} onChange={(e) => set("economic_sector", e.target.value)}>
              <option value="">Elige uno</option>
              {SECTORS.map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </Field>
          <Field label="¿Cuántos trabajadores tiene?" error={err("headcount")} hint="Con esto te sugerimos Comité o Supervisor SST.">
            <input className="input tabular-nums" inputMode="numeric" value={f.headcount} aria-invalid={!!err("headcount") || undefined} onChange={(e) => set("headcount", onlyDigits(e.target.value, 7))} />
          </Field>
        </div>
      </fieldset>

      <fieldset className="space-y-4 border-t border-[var(--site-border)] pt-6">
        <legend className="mb-1 flex items-center gap-2 font-display text-lg font-bold text-[var(--site-navy-title)]">
          <span className="grid h-8 w-8 place-items-center rounded-lg bg-[var(--site-surface)] text-[var(--site-action)]">
            <Icon name="user" size={17} />
          </span>
          Tus datos
        </legend>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Nombre y apellidos" error={err("full_name")}>
            <input className="input" maxLength={120} autoComplete="name" value={f.full_name} aria-invalid={!!err("full_name") || undefined} onChange={(e) => set("full_name", e.target.value)} />
          </Field>
          <Field label="Correo de trabajo" error={err("email")} hint="Aquí te llega el enlace para crear tu contraseña.">
            <input className="input" type="email" maxLength={160} autoComplete="email" value={f.email} aria-invalid={!!err("email") || undefined} onChange={(e) => set("email", e.target.value)} />
          </Field>
          <Field label="Celular" optional error={err("phone")}>
            <input className="input" type="tel" maxLength={30} autoComplete="tel" value={f.phone} onChange={(e) => set("phone", e.target.value)} />
          </Field>
        </div>
      </fieldset>

      <Check checked={accept} onChange={setAccept} error={err("accept_legal")}>
        Acepto los{" "}
        <Link href="/legal/terminos-y-condiciones" target="_blank" className="font-semibold text-[var(--site-action)] underline">
          Términos y condiciones
        </Link>{" "}
        y la{" "}
        <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-[var(--site-action)] underline">
          Política de privacidad
        </Link>
        .
      </Check>

      <Submit saving={saving}>Crear mi cuenta de prueba</Submit>
      <p className="text-center text-sm text-[var(--site-text-2)]">
        ¿Ya tienes cuenta?{" "}
        <Link href="/login" className="font-bold text-[var(--site-action)] hover:underline">
          Inicia sesión
        </Link>
      </p>
    </form>
  );
}

// ---------------------------------------------------------------- Demo, cotización y contacto

export type RequestType = "demo" | "quote" | "contact";

const TYPES: [RequestType, string, string, string][] = [
  ["demo", "Ver una demo", "clock", "Te mostramos el sistema en una videollamada y resolvemos tus dudas."],
  ["quote", "Pedir cotización", "tag", "Te enviamos el precio exacto para tu empresa: plan, complementos y forma de pago."],
  ["contact", "Hacer una consulta", "mail", "Cualquier pregunta sobre EscudoSST antes de contratar."],
];

export type QuoteAddon = { code: string; name: string; available: boolean };

export function DemoForm({
  initialType = "demo",
  initialPlan = "",
  interest = "",
  plans,
  addons = [],
}: {
  initialType?: RequestType;
  initialPlan?: string;
  interest?: string;
  plans: [string, string][];
  addons?: QuoteAddon[];
}) {
  const [type, setType] = useState<RequestType>(initialType);
  // Complemento que viene desde /complementos («Solicitar cotización» de uno en particular).
  const [addonCodes, setAddonCodes] = useState<string[]>(() => addons.filter((a) => a.name === interest).map((a) => a.code));
  const [f, setF] = useState({
    full_name: "",
    email: "",
    phone: "",
    company_name: "",
    ruc: "",
    job_title: "",
    headcount: "",
    economic_sector: "",
    interest_plan_code: initialPlan,
    message: interest && !addons.some((a) => a.name === interest) ? `Me interesa el complemento ${interest}.` : "",
    website: "",
  });
  const [privacy, setPrivacy] = useState(false);
  const [marketing, setMarketing] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState(false);
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
  const err = (k: string) => errors[k]?.[0];

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMessage(null);
    const r = await post("demo-requests", {
      request_type: type,
      ...f,
      phone: f.phone.trim() || null,
      ruc: f.ruc || null,
      job_title: f.job_title.trim() || null,
      headcount: f.headcount === "" ? null : Number(f.headcount),
      economic_sector: f.economic_sector || null,
      interest_plan_code: f.interest_plan_code || null,
      addon_codes: type === "quote" ? addonCodes : [],
      message: f.message.trim() || null,
      privacy_consent: privacy,
      marketing_consent: marketing,
      source_page: typeof window !== "undefined" ? window.location.pathname + window.location.search : null,
    });
    setSaving(false);
    if (r.ok) {
      setDone(true);
      scrollToForm();
    }
    else {
      setErrors(r.errors);
      setMessage(r.message);
      focusFirstError();
    }
  }

  if (done)
    return (
      <Done icon="check" title="¡Gracias! Recibimos tu solicitud">
        <p>
          {type === "demo"
            ? "Te enviamos una confirmación a tu correo. Un asesor te escribirá para coordinar el día y la hora de la demo."
            : type === "quote"
              ? "Te enviamos la cotización a tu correo con el detalle de lo que elegiste. Un asesor te escribirá para resolver tus dudas."
              : "Te enviamos una confirmación a tu correo y te responderemos lo antes posible."}
        </p>
        <p className="text-sm">Mientras tanto, puedes crear tu cuenta de prueba y empezar con tu línea base.</p>
        <Link href="/prueba-gratis" className="site-btn site-btn-outline mt-2">
          Probar gratis
        </Link>
      </Done>
    );

  return (
    <form onSubmit={submit} noValidate className="site-card relative space-y-6 p-6 sm:p-8">
      <Honeypot value={f.website} onChange={(v) => set("website", v)} />
      <div role="radiogroup" aria-label="¿Qué necesitas?" className="grid grid-cols-1 gap-2 sm:grid-cols-3">
        {TYPES.map(([v, label, icon]) => (
          <button
            key={v}
            type="button"
            role="radio"
            aria-checked={type === v}
            onClick={() => setType(v)}
            className={`flex items-center justify-center gap-2 rounded-xl border px-3 py-3 text-sm font-bold transition ${
              type === v ? "border-[var(--site-action)] bg-[var(--site-action)] text-white shadow-md" : "border-[#d5dde7] bg-white text-[var(--site-navy)] hover:border-[var(--site-action)]/50"
            }`}
          >
            <Icon name={icon} size={16} />
            {label}
          </button>
        ))}
      </div>
      <p className="-mt-3 text-sm text-[var(--site-text-2)]">{TYPES.find(([v]) => v === type)?.[3]}</p>
      <Alert text={message} />

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Nombre y apellidos" error={err("full_name")}>
          <input className="input" maxLength={120} autoComplete="name" value={f.full_name} aria-invalid={!!err("full_name") || undefined} onChange={(e) => set("full_name", e.target.value)} />
        </Field>
        <Field label="Correo" error={err("email")}>
          <input className="input" type="email" maxLength={160} autoComplete="email" value={f.email} aria-invalid={!!err("email") || undefined} onChange={(e) => set("email", e.target.value)} />
        </Field>
        <Field label="Celular" optional error={err("phone")}>
          <input className="input" type="tel" maxLength={30} autoComplete="tel" value={f.phone} onChange={(e) => set("phone", e.target.value)} />
        </Field>
        <Field label="Cargo" optional error={err("job_title")}>
          <input className="input" maxLength={120} autoComplete="organization-title" value={f.job_title} onChange={(e) => set("job_title", e.target.value)} />
        </Field>
        <Field label="Empresa" error={err("company_name")}>
          <input className="input" maxLength={160} autoComplete="organization" value={f.company_name} aria-invalid={!!err("company_name") || undefined} onChange={(e) => set("company_name", e.target.value)} />
        </Field>
        <Field label="RUC" optional error={err("ruc")}>
          <input className="input tabular-nums" inputMode="numeric" value={f.ruc} aria-invalid={!!err("ruc") || undefined} onChange={(e) => set("ruc", onlyDigits(e.target.value, 11))} />
        </Field>
        <Field label="Trabajadores" optional error={err("headcount")}>
          <input className="input tabular-nums" inputMode="numeric" value={f.headcount} onChange={(e) => set("headcount", onlyDigits(e.target.value, 7))} />
        </Field>
        <Field label="Sector" optional error={err("economic_sector")}>
          <select className="input" value={f.economic_sector} onChange={(e) => set("economic_sector", e.target.value)}>
            <option value="">Elige uno</option>
            {SECTORS.map(([v, l]) => (
              <option key={v} value={v}>
                {l}
              </option>
            ))}
          </select>
        </Field>
        {type !== "contact" && plans.length > 0 && (
          <Field
            label="Plan de interés"
            optional
            error={err("interest_plan_code")}
            hint={type === "quote" ? "Si aún no lo sabes, te recomendamos uno según el tamaño de tu empresa." : undefined}
            className="sm:col-span-2"
          >
            <select className="input" value={f.interest_plan_code} onChange={(e) => set("interest_plan_code", e.target.value)}>
              <option value="">Aún no lo sé</option>
              {plans.map(([code, name]) => (
                <option key={code} value={code}>
                  {name}
                </option>
              ))}
            </select>
          </Field>
        )}
        {type === "quote" && addons.length > 0 && (
          <div className="sm:col-span-2">
            <p className="mb-1.5 text-sm font-semibold text-[var(--site-navy-title)]">
              Complementos <span className="font-normal text-[var(--site-text-2)]">(opcional)</span>
            </p>
            <div role="group" aria-label="Complementos" className="flex flex-wrap gap-2">
              {addons.map((a) => {
                const on = addonCodes.includes(a.code);
                return (
                  <button
                    key={a.code}
                    type="button"
                    aria-pressed={on}
                    onClick={() => setAddonCodes((c) => (on ? c.filter((x) => x !== a.code) : [...c, a.code]))}
                    className={`inline-flex items-center gap-2 rounded-full border px-3.5 py-2 text-sm font-semibold transition ${
                      on ? "border-[var(--site-action)] bg-[var(--site-action)]/10 text-[var(--site-navy)]" : "border-[#d5dde7] bg-white text-[var(--site-text-2)] hover:border-[var(--site-action)]/50"
                    }`}
                  >
                    <span className={`grid h-4 w-4 place-items-center rounded-full border text-[10px] ${on ? "border-[var(--site-action)] bg-[var(--site-action)] text-white" : "border-[#c3ccd8]"}`} aria-hidden="true">
                      {on ? "✓" : ""}
                    </span>
                    {a.name}
                    {!a.available && <span className="text-xs font-normal">· próximamente</span>}
                  </button>
                );
              })}
            </div>
            <p className="mt-1.5 text-xs text-[var(--site-text-2)]">Te llega al correo el detalle con el precio de cada uno.</p>
          </div>
        )}
        <Field label={type === "contact" ? "Tu consulta" : "¿Algo que debamos saber?"} optional={type !== "contact"} error={err("message")} className="sm:col-span-2">
          <textarea className="input min-h-[96px]" maxLength={2000} value={f.message} onChange={(e) => set("message", e.target.value)} />
        </Field>
      </div>

      <div className="space-y-2.5">
        <Check checked={privacy} onChange={setPrivacy} error={err("privacy_consent")}>
          Acepto la{" "}
          <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-[var(--site-action)] underline">
            Política de privacidad
          </Link>{" "}
          para que me contacten por esta solicitud.
        </Check>
        <Check checked={marketing} onChange={setMarketing}>
          Quiero recibir por correo novedades del producto y contenido útil de SST <span className="text-[var(--site-text-2)]">(opcional, puedo darme de baja cuando quiera)</span>.
        </Check>
      </div>

      <Submit saving={saving}>{type === "demo" ? "Solicitar demo" : type === "quote" ? "Pedir cotización" : "Enviar consulta"}</Submit>
      <p className="text-center text-xs text-[var(--site-text-2)]">
        ¿Ya eres cliente y tienes un reclamo o una queja? Usa el <b>Libro de Reclamaciones</b> del pie de página.
      </p>
    </form>
  );
}
