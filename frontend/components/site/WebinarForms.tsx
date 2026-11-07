"use client";

// Inscripción a un webinar (SCR-WEB-78) y cancelación con el enlace del correo. Privacidad obligatoria y casilla
// comercial aparte, sin marcar.

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Check, Done, Field, Honeypot, Submit } from "@/components/site/PublicForms";
import { rememberUtm, track } from "@/lib/track";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
type Errors = Record<string, string[]>;

async function post(path: string, body: unknown) {
  try {
    const res = await fetch(`${API}/api/v1/public/${path}`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify(body) });
    const j = await res.json().catch(() => ({}));
    if (res.ok) return { ok: true as const };
    if (res.status === 429) return { ok: false as const, errors: {} as Errors, message: "Hiciste varios intentos seguidos. Espera unos minutos." };
    return { ok: false as const, errors: (j.errors ?? {}) as Errors, message: j.errors ? "Revisa los campos marcados." : (j.detail ?? "No pudimos enviar. Inténtalo otra vez.") };
  } catch {
    return { ok: false as const, errors: {} as Errors, message: "No hay conexión. Revisa tu internet e inténtalo otra vez." };
  }
}

export function WebinarRegisterForm({ slug }: { slug: string }) {
  const [f, setF] = useState({ full_name: "", email: "", company_name: "", phone: "", website: "" });
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
    const r = await post(`webinars/${slug}/register`, { ...f, company_name: f.company_name || null, phone: f.phone || null, privacy_consent: privacy, marketing_consent: marketing, ...rememberUtm() });
    setSaving(false);
    if (r.ok) {
      track("webinar_registered", slug);
      setDone(true);
    } else {
      setErrors(r.errors);
      setMessage(r.message);
    }
  }

  if (done)
    return (
      <Done icon="mail" title="¡Listo, estás inscrito!">
        Te enviamos un correo con los datos y el enlace para entrar. Te recordaremos un día antes y una hora antes.
      </Done>
    );

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      <Honeypot value={f.website} onChange={(v) => set("website", v)} />
      {message && <p role="alert" className="rounded-xl border-l-4 border-[var(--site-red)] bg-[#E2453C]/8 px-4 py-3 text-sm font-semibold text-[#A3271F]">{message}</p>}
      <Field label="Nombres y apellidos" error={err("full_name")}>
        <input className="input" maxLength={120} autoComplete="name" value={f.full_name} onChange={(e) => set("full_name", e.target.value)} />
      </Field>
      <Field label="Correo" hint="Aquí te llega el enlace para entrar." error={err("email")}>
        <input className="input" type="email" maxLength={160} autoComplete="email" value={f.email} onChange={(e) => set("email", e.target.value)} />
      </Field>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Empresa" optional error={err("company_name")}>
          <input className="input" maxLength={160} autoComplete="organization" value={f.company_name} onChange={(e) => set("company_name", e.target.value)} />
        </Field>
        <Field label="Celular" optional error={err("phone")}>
          <input className="input" type="tel" maxLength={20} autoComplete="tel" value={f.phone} onChange={(e) => set("phone", e.target.value)} />
        </Field>
      </div>
      <Check checked={privacy} onChange={setPrivacy} error={err("privacy_consent")}>
        Acepto que usen mis datos para inscribirme y avisarme de este webinar (ver{" "}
        <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-[var(--site-action)] underline">
          Política de privacidad
        </Link>
        ).
      </Check>
      <Check checked={marketing} onChange={setMarketing}>
        Quiero recibir novedades y contenidos de EscudoSST (opcional).
      </Check>
      <Submit saving={saving}>Inscribirme</Submit>
      <p className="text-center text-xs text-[var(--site-text-2)]">El webinar puede grabarse; te avisaremos al inicio.</p>
    </form>
  );
}

export function WebinarCancel({ token }: { token: string }) {
  const [state, setState] = useState<"idle" | "busy" | "done" | "error">("idle");
  const [message, setMessage] = useState<string | null>(null);
  async function cancel() {
    setState("busy");
    const r = await post(`webinars/cancel/${token}`, {});
    if (r.ok) setState("done");
    else {
      setState("error");
      setMessage(r.message);
    }
  }
  if (state === "done")
    return (
      <Done icon="check" title="Cancelamos tu inscripción">
        Ya no recibirás recordatorios de este webinar. Si cambias de opinión, puedes volver a inscribirte mientras haya cupos.
      </Done>
    );
  return (
    <div className="space-y-4 text-center">
      <p className="text-[15px] text-[var(--site-text-2)]">¿Ya no puedes asistir? Cancela tu inscripción para liberar tu cupo.</p>
      {message && <p role="alert" className="text-sm font-semibold text-[#A3271F]">{message}</p>}
      <button type="button" disabled={state === "busy"} onClick={cancel} className="site-btn site-btn-primary disabled:opacity-60">
        {state === "busy" ? "Cancelando…" : "Cancelar mi inscripción"}
      </button>
    </div>
  );
}
