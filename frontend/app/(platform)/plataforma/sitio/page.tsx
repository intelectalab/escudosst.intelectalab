"use client";

// Sitio web → Marca y ajustes (SCR-WEB-21). Lo que se guarda aquí se ve en el sitio público al momento
// (WhatsApp, barra de anuncio, contacto, datos legales del pie, enlaces y redes).

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Settings = {
  trade_name: string;
  legal_name: string;
  ruc: string;
  legal_address: string | null;
  primary_domain: string | null;
  contact_email: string | null;
  sales_email: string | null;
  support_email: string | null;
  privacy_email: string | null;
  contact_phone: string | null;
  whatsapp_number: string | null;
  whatsapp_default_message: string | null;
  blog_url: string | null;
  interactive_demo_url: string | null;
  complaints_officer_name: string | null;
  complaints_officer_email: string | null;
  complaints_officer_phone: string | null;
  social_links: Record<string, string> | null;
  announcement_bar: { text?: string; link?: string; active?: boolean; until?: string | null } | null;
};

const SOCIAL: [string, string][] = [
  ["facebook", "Facebook"],
  ["instagram", "Instagram"],
  ["linkedin", "LinkedIn"],
  ["tiktok", "TikTok"],
  ["youtube", "YouTube"],
  ["x", "X (Twitter)"],
];

const SECTIONS: [string, string, string][] = [
  ["whatsapp", "WhatsApp", "mail"],
  ["anuncio", "Barra de anuncio", "bell"],
  ["contacto", "Contacto", "people"],
  ["legal", "Datos legales", "file"],
  ["enlaces", "Dominio y enlaces", "globe"],
  ["redes", "Redes sociales", "star"],
];

export default function SitioPage() {
  const [form, setForm] = useState<Settings | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const closeToast = useCallback(() => setToast(null), []);

  useEffect(() => {
    api<{ data: Settings }>("/internal/site/settings")
      .then((r) => setForm({ ...r.data, social_links: r.data.social_links ?? {}, announcement_bar: r.data.announcement_bar ?? { text: "", link: "", active: false, until: null } }))
      .catch((e) => setError(e instanceof ApiError ? e.message : "No pudimos cargar los ajustes."));
  }, []);

  if (error) return <p className="rounded-xl border border-bad/30 bg-bad-soft p-4 text-bad">{error}</p>;
  if (!form) return <div className="h-96 w-full animate-pulse rounded-2xl bg-line/40" aria-label="Cargando" />;

  const set = <K extends keyof Settings>(k: K, v: Settings[K]) => setForm((f) => (f ? { ...f, [k]: v } : f));
  const val = (k: keyof Settings) => (form[k] as string | null) ?? "";
  const err = (k: string) => errors[k]?.[0];
  const bar = form.announcement_bar ?? {};
  const waLink = form.whatsapp_number ? `https://wa.me/${form.whatsapp_number}?text=${encodeURIComponent(form.whatsapp_default_message || "Hola, quiero conocer EscudoSST")}` : null;

  // Lista para publicar: lo mínimo que el sitio necesita (docs/07 §1.5 y M-WEB).
  const checklist: [string, boolean, string][] = [
    ["Número de WhatsApp", !!form.whatsapp_number, "whatsapp"],
    ["Correo de contacto o ventas", !!(form.contact_email || form.sales_email), "contacto"],
    ["Domicilio legal del titular", !!form.legal_address, "legal"],
    ["Responsable del Libro de Reclamaciones", !!(form.complaints_officer_name && form.complaints_officer_email), "legal"],
    ["Dominio del sitio", !!form.primary_domain, "enlaces"],
  ];
  const done = checklist.filter(([, ok]) => ok).length;

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!form) return;
    setSaving(true);
    setErrors({});
    try {
      await api("/internal/site/settings", { method: "PATCH", body: { ...form, announcement_bar: bar.text ? bar : null } });
      setToast("Ajustes guardados. El sitio los muestra en menos de un minuto.");
    } catch (e2) {
      if (e2 instanceof ApiError && e2.errors) {
        setErrors(e2.errors);
        setToast("Revisa los campos marcados.");
      } else setToast(e2 instanceof ApiError ? e2.message : "No se pudo guardar.");
    } finally {
      setSaving(false);
    }
  }

  const input = (k: keyof Settings, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <input className="input" value={val(k)} aria-invalid={!!err(k) || undefined} onChange={(e) => set(k, e.target.value as never)} {...props} />
  );

  return (
    <form onSubmit={save} className="w-full space-y-6" noValidate>
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web"]]}
        icon="globe"
        title="Contacto y WhatsApp"
        description="Marca, contacto y datos legales del sitio público. Lo que guardes se ve en la web en menos de un minuto."
        aside={
          <div className="flex flex-wrap gap-2">
            <a href="/" target="_blank" rel="noopener" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold shadow-sm hover:border-brand">
              <Icon name="eye" size={16} /> Ver sitio
            </a>
            <Button type="submit" icon="check" loading={saving}>
              Guardar cambios
            </Button>
          </div>
        }
      />


      <div className="grid gap-6 2xl:grid-cols-[16rem_minmax(0,1fr)]">
        {/* Lateral: lista para publicar y accesos */}
        <aside className="space-y-4 2xl:sticky 2xl:top-24 2xl:self-start">
          <section className="rounded-2xl border border-line bg-surface p-4 shadow-sm">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="font-display font-semibold">Lista para publicar</h2>
              <Chip tone={done === checklist.length ? "ok" : "amber"}>
                {done}/{checklist.length}
              </Chip>
            </div>
            <div className="mb-3 h-2 overflow-hidden rounded-full bg-bg">
              <div className="h-full rounded-full bg-ok transition-all" style={{ width: `${(done / checklist.length) * 100}%` }} />
            </div>
            <ul className="space-y-1.5 text-sm">
              {checklist.map(([label, ok, anchor]) => (
                <li key={label}>
                  <a href={`#${anchor}`} className="flex items-start gap-2 rounded-md px-1 py-0.5 hover:bg-bg">
                    <span className={`mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full ${ok ? "bg-ok text-white" : "border-2 border-line"}`}>{ok && <Icon name="check" size={10} />}</span>
                    <span className={ok ? "text-muted line-through" : ""}>{label}</span>
                  </a>
                </li>
              ))}
              <li className="flex items-start gap-2 px-1 py-0.5 text-muted">
                <span className="mt-0.5 h-4 w-4 shrink-0 rounded-full border-2 border-dashed border-line" />
                Textos legales validados por abogado (Parte 2)
              </li>
            </ul>
          </section>
          <nav className="hidden rounded-2xl border border-line bg-surface p-2 shadow-sm xl:block" aria-label="Secciones">
            {SECTIONS.map(([id, label, icon]) => (
              <a key={id} href={`#${id}`} className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-muted hover:bg-bg hover:text-fg">
                <Icon name={icon} size={15} /> {label}
              </a>
            ))}
          </nav>
          <Link href="/plataforma/planes" className="flex items-start gap-3 rounded-2xl border border-line bg-surface p-4 text-sm shadow-sm hover:border-brand">
            <Icon name="tag" size={18} className="mt-0.5 shrink-0 text-brand" />
            <span>
              <b className="block">Planes visibles y precios</b>
              <span className="text-muted">Se editan en Planes y módulos («Visible en el sitio web» y precio).</span>
            </span>
          </Link>
        </aside>

        <div className="space-y-6">
          <Card id="whatsapp" icon="mail" title="WhatsApp" text="Número de ventas de EscudoSST. Activa el botón verde del sitio.">
            <div className="grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]">
              <FormField label="Número" hint="Código de país + número, sin + ni espacios. Ej.: 51987654321" error={err("whatsapp_number")}>
                <input className="input tabular-nums" inputMode="numeric" placeholder="51987654321" value={val("whatsapp_number")} aria-invalid={!!err("whatsapp_number") || undefined} onChange={(e) => set("whatsapp_number", e.target.value.replace(/\D/g, "").slice(0, 15))} />
              </FormField>
              <FormField label="Mensaje que se escribe solo" counter={`${val("whatsapp_default_message").length}/300`} hint="Lo que verá escrito la persona al abrir el chat." error={err("whatsapp_default_message")}>
                {input("whatsapp_default_message", { maxLength: 300, placeholder: "Hola, quiero conocer EscudoSST" })}
              </FormField>
            </div>
            <div className="flex flex-wrap items-center gap-3 rounded-xl bg-bg p-3">
              <span className="grid h-11 w-11 place-items-center rounded-full bg-[#22A35A] text-white shadow-md">
                <svg width="22" height="22" viewBox="0 0 32 32" aria-hidden="true">
                  <path fill="currentColor" d="M16 3C8.8 3 3 8.7 3 15.8c0 2.3.6 4.5 1.8 6.4L3 29l7-1.8c1.8 1 3.9 1.5 6 1.5 7.2 0 13-5.7 13-12.8S23.2 3 16 3z" />
                </svg>
              </span>
              <p className="min-w-0 flex-1 text-sm text-muted">{waLink ? "Así se verá el botón en el sitio. Pruébalo antes de guardar:" : "Sin número, el botón de WhatsApp no aparece en el sitio."}</p>
              {waLink && (
                <a href={waLink} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-sm font-semibold text-brand hover:underline">
                  Probar enlace <Icon name="chevron" size={14} />
                </a>
              )}
            </div>
          </Card>

          <Card
            id="anuncio"
            icon="bell"
            title="Barra de anuncio"
            text="Franja delgada que aparece en la parte de arriba de todas las páginas del sitio, encima del menú. Sirve para un aviso del momento: un módulo nuevo, un webinar o una fecha importante. Opcional."
          >
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label="Texto" counter={`${(bar.text ?? "").length}/140`} error={err("announcement_bar.text")}>
                <input className="input" maxLength={140} placeholder="Ej.: Nuevo módulo de inspecciones" value={bar.text ?? ""} aria-invalid={!!err("announcement_bar.text") || undefined} onChange={(e) => set("announcement_bar", { ...bar, text: e.target.value })} />
              </FormField>
              <FormField label="Enlace (opcional)" hint="Una página del sitio, por ejemplo /planes.">
                <input className="input" maxLength={250} placeholder="/planes" value={bar.link ?? ""} onChange={(e) => set("announcement_bar", { ...bar, link: e.target.value })} />
              </FormField>
              <FormField label="Mostrar hasta (opcional)" hint="Después de esa fecha se oculta sola.">
                <input className="input" type="date" value={bar.until ?? ""} onChange={(e) => set("announcement_bar", { ...bar, until: e.target.value || null })} />
              </FormField>
              <SwitchRow label="Mostrar en el sitio" hint="Apágala sin borrar el texto." checked={!!bar.active} onChange={(v) => set("announcement_bar", { ...bar, active: v })} />
            </div>
            {bar.text && (
              <div>
                <p className="mb-1.5 text-xs font-semibold text-muted">Así se verá arriba del sitio{bar.active ? "" : " (apagada)"}:</p>
                <div className={`flex items-center justify-center gap-2 rounded-lg bg-[#0D3B6E] px-4 py-2 text-sm text-white ${bar.active ? "" : "opacity-40"}`}>
                  <span className="h-2 w-2 rounded-full bg-[#F4B90F]" /> {bar.text}
                  {bar.link && <b className="text-[#F4B90F]">Ver más →</b>}
                </div>
              </div>
            )}
          </Card>

          <Card id="contacto" icon="people" title="Contacto" text="Cada correo tiene un uso distinto. Puede ser el mismo correo en varios campos si lo atiende la misma persona.">
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label="Correo de contacto" hint="Público: aparece en el pie del sitio para cualquier visitante." error={err("contact_email")}>
                {input("contact_email", { type: "email" })}
              </FormField>
              <FormField label="Correo de ventas" hint="Interno: aquí te avisamos cada demo, cotización o consulta nueva del sitio. No se publica." error={err("sales_email")}>
                {input("sales_email", { type: "email" })}
              </FormField>
              <FormField label="Correo de soporte" hint="Para clientes que ya usan EscudoSST: aparece en el pie del sitio como «Soporte para clientes»." error={err("support_email")}>
                {input("support_email", { type: "email" })}
              </FormField>
              <FormField label="Correo de privacidad (datos personales)" hint="Donde las personas piden ver, corregir o borrar sus datos. Lo cita la Política de privacidad." error={err("privacy_email")}>
                {input("privacy_email", { type: "email" })}
              </FormField>
              <FormField label="Teléfono" hint="Público: aparece en el pie del sitio." error={err("contact_phone")}>
                {input("contact_phone", { maxLength: 30 })}
              </FormField>
            </div>
          </Card>

          <Card id="legal" icon="file" title="Datos legales" text="Empresa dueña de EscudoSST. Se muestran en el pie y en el Libro de Reclamaciones.">
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label="Nombre comercial" error={err("trade_name")}>{input("trade_name", { maxLength: 60 })}</FormField>
              <FormField label="Razón social" error={err("legal_name")}>{input("legal_name", { maxLength: 160 })}</FormField>
              <FormField label="RUC" error={err("ruc")}>
                <input className="input tabular-nums" inputMode="numeric" maxLength={11} value={val("ruc")} onChange={(e) => set("ruc", e.target.value.replace(/\D/g, ""))} />
              </FormField>
              <FormField label="Domicilio legal" hint="Dirección fiscal de la empresa, tal como figura en SUNAT." error={err("legal_address")}>{input("legal_address", { maxLength: 250 })}</FormField>
            </div>
            <div className="rounded-xl border border-line p-4">
              <p className="mb-3 text-sm font-semibold">Responsable del Libro de Reclamaciones</p>
              <div className="grid gap-4 sm:grid-cols-3">
                <FormField label="Nombre" error={err("complaints_officer_name")}>{input("complaints_officer_name", { maxLength: 120 })}</FormField>
                <FormField label="Correo" error={err("complaints_officer_email")}>{input("complaints_officer_email", { type: "email" })}</FormField>
                <FormField label="Teléfono" error={err("complaints_officer_phone")}>{input("complaints_officer_phone", { maxLength: 30 })}</FormField>
              </div>
            </div>
          </Card>

          <Card id="enlaces" icon="globe" title="Dominio y enlaces" text="Dirección del sitio y enlaces externos.">
            <div className="grid gap-4 sm:grid-cols-3">
              <FormField label="Dominio" hint="Dirección del sitio, sin https://" error={err("primary_domain")}>
                <input className="input" placeholder="escudosst.com" value={val("primary_domain")} onChange={(e) => set("primary_domain", e.target.value.toLowerCase().replace(/^https?:\/\//, "").trim())} />
              </FormField>
              <FormField label="Blog (opcional)" hint="Si lo llenas, aparece «Blog» en el menú." error={err("blog_url")}>{input("blog_url", { type: "url", placeholder: "https://blog.escudosst.com" })}</FormField>
              <FormField
                label="Demo interactiva (opcional)"
                hint="Enlace a un video o recorrido guiado del sistema con datos de ejemplo, que el visitante ve sin registrarse. Si lo llenas, aparece «Ver la demo ahora» en la página Agenda una demo."
                error={err("interactive_demo_url")}
              >{input("interactive_demo_url", { type: "url", placeholder: "https://" })}</FormField>
            </div>
          </Card>

          <Card id="redes" icon="star" title="Redes sociales" text="Opcional. Se muestran en el pie del sitio.">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {SOCIAL.map(([k, label]) => (
                <FormField key={k} label={label} error={err(`social_links.${k}`)}>
                  <input className="input" type="url" placeholder="https://" value={form.social_links?.[k] ?? ""} onChange={(e) => set("social_links", { ...(form.social_links ?? {}), [k]: e.target.value })} />
                </FormField>
              ))}
            </div>
          </Card>

          <div className="sticky bottom-4 flex justify-end">
            <Button type="submit" icon="check" loading={saving} className="shadow-xl">
              Guardar cambios
            </Button>
          </div>
        </div>
      </div>
      {toast && <Toast message={toast} onClose={closeToast} />}
    </form>
  );
}

function Card({ id, icon, title, text, children }: { id: string; icon: string; title: string; text: string; children: React.ReactNode }) {
  return (
    <section id={id} className="scroll-mt-24 space-y-4 rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6">
      <div className="flex gap-3">
        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong">
          <Icon name={icon} size={19} />
        </span>
        <div>
          <h2 className="font-display text-lg font-semibold">{title}</h2>
          <p className="text-sm text-muted">{text}</p>
        </div>
      </div>
      {children}
    </section>
  );
}
