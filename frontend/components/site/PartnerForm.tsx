"use client";

// Formulario del programa de partners (SCR-WEB-35, RN-WEB-46): tipo, empresa, RUC (opcional, con dígito
// verificador), web, contacto, privacidad obligatoria y casilla comercial aparte. Acuse en pantalla.

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Alert, Check, Field, Honeypot, Submit } from "@/components/site/PublicForms";
import { Icon } from "@/components/ui/Icon";
import { isValidRuc } from "@/lib/accounts";
import { track } from "@/lib/track";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

const TYPES: [string, string, string, string][] = [
  ["consultancy", "Consultora SST", "Asesoras a varias empresas.", "building"],
  ["independent", "Profesional independiente", "Supervisor, ingeniero o asesor SST.", "user"],
  ["institute", "Instituto o centro de formación", "Capacitas en seguridad y salud.", "book"],
];

export function PartnerForm() {
  const [f, setF] = useState({ partner_type: "consultancy", full_name: "", email: "", phone: "", company_name: "", ruc: "", website: "", message: "", hp: "" });
  const [privacy, setPrivacy] = useState(false);
  const [marketing, setMarketing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState(false);
  const [key] = useState(() => (typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : undefined));
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
  const err = (k: string) => errors[k]?.[0];
  const rucBad = f.ruc.length === 11 && !isValidRuc(f.ruc);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMessage(null);
    setErrors({});
    try {
      const res = await fetch(`${API}/api/v1/public/partners`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({
          partner_type: f.partner_type, full_name: f.full_name, email: f.email, phone: f.phone || null, company_name: f.company_name,
          ruc: f.ruc || null, website: f.website.trim() || null, message: f.message.trim() || null,
          privacy_consent: privacy, marketing_consent: marketing, website_hp: f.hp, key,
        }),
      });
      const j = await res.json().catch(() => ({}));
      if (res.ok) {
        setDone(true);
        track("form_submit", "partner");
        document.getElementById("solicitud")?.scrollIntoView({ behavior: "smooth", block: "start" });
      } else {
        setErrors(j.errors ?? {});
        setMessage(res.status === 429 ? "Recibimos varios envíos seguidos. Espera unos minutos." : res.status === 422 ? "Revisa los campos marcados." : "No pudimos enviar. Inténtalo de nuevo.");
      }
    } catch {
      setMessage("No hay conexión. Revisa tu internet e inténtalo otra vez.");
    }
    setSaving(false);
  }

  if (done) {
    return (
      <div className="site-card p-8 text-center" role="status">
        <span className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-[#E8F7EE] text-[#22A35A]">
          <Icon name="check" size={28} />
        </span>
        <h3 className="mt-4 font-display text-2xl font-bold text-[var(--site-navy-title)]">¡Recibimos tu solicitud!</h3>
        <p className="mx-auto mt-2 max-w-md text-[var(--site-text-2)]">
          Te enviamos un correo de confirmación a <b className="text-[var(--site-navy-title)]">{f.email}</b>. Revisaremos tus datos y te escribiremos con la respuesta y, si te aprobamos, con tu código de referido.
        </p>
      </div>
    );
  }

  return (
    <form onSubmit={submit} noValidate className="site-card relative space-y-5 p-6 sm:p-8">
      <Honeypot value={f.hp} onChange={(v) => set("hp", v)} />
      <Alert text={message} />
      <fieldset>
        <legend className="mb-2 text-sm font-semibold text-[var(--site-navy-title)]">¿Qué tipo de partner eres?</legend>
        <div className="grid grid-cols-1 gap-2 md:grid-cols-3">
          {TYPES.map(([v, label, hint, icon]) => (
            <label key={v} className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition ${f.partner_type === v ? "border-[var(--site-action)] bg-[var(--site-surface)] ring-2 ring-[var(--site-action)]/15" : "border-[#d5dde7] hover:border-[var(--site-action)]/50"}`}>
              <input type="radio" name="partner_type" value={v} checked={f.partner_type === v} onChange={() => set("partner_type", v)} className="sr-only" />
              <span className={`grid h-9 w-9 shrink-0 place-items-center rounded-lg ${f.partner_type === v ? "bg-[var(--site-action)] text-white" : "bg-[#EAF3FC] text-[var(--site-action)]"}`}>
                <Icon name={icon} size={17} />
              </span>
              <span className="min-w-0">
                <b className="block text-sm text-[var(--site-navy-title)]">{label}</b>
                <span className="block text-xs text-[var(--site-text-2)]">{hint}</span>
              </span>
            </label>
          ))}
        </div>
      </fieldset>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Empresa o nombre comercial" error={err("company_name")}>
          <input className="input" maxLength={160} autoComplete="organization" value={f.company_name} onChange={(e) => set("company_name", e.target.value)} />
        </Field>
        <Field label="RUC" optional error={err("ruc") ?? (rucBad ? "Revisa el RUC: el último dígito no coincide." : undefined)} hint="11 dígitos.">
          <input className="input tabular-nums" inputMode="numeric" value={f.ruc} onChange={(e) => set("ruc", e.target.value.replace(/\D/g, "").slice(0, 11))} />
        </Field>
        <Field label="Nombre y apellidos" error={err("full_name")}>
          <input className="input" maxLength={120} autoComplete="name" value={f.full_name} onChange={(e) => set("full_name", e.target.value)} />
        </Field>
        <Field label="Correo" error={err("email")}>
          <input className="input" type="email" maxLength={160} autoComplete="email" value={f.email} onChange={(e) => set("email", e.target.value)} />
        </Field>
        <Field label="Celular" optional error={err("phone")}>
          <input className="input" type="tel" maxLength={20} autoComplete="tel" value={f.phone} onChange={(e) => set("phone", e.target.value)} />
        </Field>
        <Field label="Página web" optional error={err("website")}>
          <input className="input" type="url" maxLength={300} placeholder="https://" value={f.website} onChange={(e) => set("website", e.target.value)} />
        </Field>
      </div>
      <Field label="Cuéntanos de ti" optional error={err("message")} hint="¿A cuántas empresas asesoras? ¿En qué rubros?">
        <textarea className="input min-h-24" maxLength={2000} value={f.message} onChange={(e) => set("message", e.target.value)} />
      </Field>
      <Check checked={privacy} onChange={setPrivacy} error={err("privacy_consent")}>
        Acepto la{" "}
        <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-[var(--site-action)] underline">
          Política de privacidad
        </Link>{" "}
        para que revisen mi solicitud.
      </Check>
      <Check checked={marketing} onChange={setMarketing}>
        Quiero recibir novedades y ofertas de EscudoSST (opcional).
      </Check>
      <Submit saving={saving}>Enviar solicitud</Submit>
    </form>
  );
}
