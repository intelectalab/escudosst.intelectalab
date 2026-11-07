"use client";

// Suscripción al boletín (RN-WEB-49): correo, temas, privacidad obligatoria y casilla comercial aparte.
// Doble confirmación: después de enviar, la persona debe abrir el enlace que le llega por correo.

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Alert, Check, Field, Honeypot, Submit } from "@/components/site/PublicForms";
import { Icon } from "@/components/ui/Icon";
import { NL_TOPICS } from "@/lib/newsletter";
import { track } from "@/lib/track";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export function NewsletterForm() {
  const [email, setEmail] = useState("");
  const [name, setName] = useState("");
  const [topics, setTopics] = useState<string[]>(Object.keys(NL_TOPICS));
  const [privacy, setPrivacy] = useState(false);
  const [marketing, setMarketing] = useState(false);
  const [hp, setHp] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [sent, setSent] = useState(false);
  const toggle = (t: string) => setTopics((x) => (x.includes(t) ? x.filter((y) => y !== t) : [...x, t]));
  const topicError = errors.topics?.[0] ?? (Object.keys(errors).some((k) => k.startsWith("topics.")) ? "Elige al menos un tema." : undefined);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMessage(null);
    setErrors({});
    try {
      const res = await fetch(`${API}/api/v1/public/newsletter`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({ email, full_name: name || null, topics, privacy_consent: privacy, marketing_consent: marketing, website_hp: hp }),
      });
      const j = await res.json().catch(() => ({}));
      if (res.ok) {
        setSent(true);
        track("form_submit", "boletin");
      }
      else {
        setErrors(j.errors ?? {});
        setMessage(res.status === 429 ? "Recibimos varios envíos seguidos. Espera unos minutos." : res.status === 422 ? "Revisa los campos marcados." : "No pudimos suscribirte. Inténtalo de nuevo.");
      }
    } catch {
      setMessage("No hay conexión. Revisa tu internet e inténtalo otra vez.");
    }
    setSaving(false);
  }

  if (sent) {
    return (
      <div className="site-card p-8 text-center" role="status">
        <span className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-[#EAF3FC] text-[#1F7FD1]">
          <Icon name="mail" size={26} />
        </span>
        <h2 className="mt-4 font-display text-2xl font-bold text-[var(--site-navy-title)]">Revisa tu correo</h2>
        <p className="mx-auto mt-2 max-w-md text-[var(--site-text-2)]">
          Te enviamos un enlace a <b className="text-[var(--site-navy-title)]">{email}</b>. Ábrelo para confirmar tu suscripción. Si no lo ves en unos minutos, revisa la carpeta de spam o promociones.
        </p>
      </div>
    );
  }

  return (
    <form onSubmit={submit} noValidate className="site-card relative space-y-5 p-6 sm:p-8">
      <Honeypot value={hp} onChange={setHp} />
      <Alert text={message} />
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Correo" error={errors.email?.[0]}>
          <input className="input" type="email" maxLength={160} autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        </Field>
        <Field label="Nombre" optional error={errors.full_name?.[0]}>
          <input className="input" maxLength={120} autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} />
        </Field>
      </div>
      <fieldset>
        <legend className="mb-2 text-sm font-semibold text-[var(--site-navy-title)]">¿Qué te interesa?</legend>
        <div className="flex flex-wrap gap-2">
          {Object.entries(NL_TOPICS).map(([k, l]) => (
            <label
              key={k}
              className={`inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border px-4 text-sm font-semibold transition ${topics.includes(k) ? "border-[var(--site-action)] bg-[var(--site-surface)] text-[var(--site-action)]" : "border-[#d5dde7] text-[var(--site-text-2)]"}`}
            >
              <input type="checkbox" className="h-4 w-4 accent-[var(--site-action)]" checked={topics.includes(k)} onChange={() => toggle(k)} />
              {l}
            </label>
          ))}
        </div>
        {topicError && <p className="mt-1.5 text-sm font-medium text-[#A3271F]">{topicError}</p>}
      </fieldset>
      <Check checked={privacy} onChange={setPrivacy} error={errors.privacy_consent?.[0]}>
        Acepto la{" "}
        <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-[var(--site-action)] underline">
          Política de privacidad
        </Link>{" "}
        para recibir el boletín. Puedo darme de baja con un clic en cada correo.
      </Check>
      <Check checked={marketing} onChange={setMarketing}>
        También quiero recibir ofertas y novedades comerciales de EscudoSST (opcional).
      </Check>
      <Submit saving={saving}>Suscribirme</Submit>
    </form>
  );
}
