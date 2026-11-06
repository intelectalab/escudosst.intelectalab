"use client";

// Formulario del cliente para contar su caso de éxito y firmar la autorización (enlace personal que le envía el
// equipo). Lo que se firma es el texto que se muestra, con sus datos. Nada se publica sin revisión del equipo.

import { FormEvent, useEffect, useMemo, useState } from "react";
import { Alert, Check, Done, Field, Honeypot, Submit } from "@/components/site/PublicForms";
import { SignaturePad } from "@/components/site/SignaturePad";
import { Icon } from "@/components/ui/Icon";
import { type PublicModule, STORY_SECTORS } from "@/lib/site";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

type Info = { client_name: string; sector: string | null; contact_name: string; contact_email: string; message: string | null; expires_at: string; authorization_template: string; owner: string; brand: string };

const onlyDigits = (v: string, n: number) => v.replace(/\D/g, "").slice(0, n);

export function StoryShareForm({ token }: { token: string }) {
  const [info, setInfo] = useState<Info | null>(null);
  const [gone, setGone] = useState<string | null>(null);
  const [modules, setModules] = useState<PublicModule[]>([]);
  const [f, setF] = useState({
    company_name: "",
    company_ruc: "",
    sector: "construction",
    project_year: String(new Date().getFullYear()),
    title: "",
    challenge: "",
    solution: "",
    quote: "",
    signer_name: "",
    signer_role: "",
    signer_doc_type: "DNI",
    signer_doc_number: "",
    signer_email: "",
    website: "",
  });
  const [results, setResults] = useState([{ value: "", label: "" }]);
  const [picked, setPicked] = useState<string[]>([]);
  const [logo, setLogo] = useState<File | null>(null);
  const [authorize, setAuthorize] = useState(false);
  const [signature, setSignature] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [alert, setAlert] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState(false);

  useEffect(() => {
    fetch(`${API}/api/v1/public/success-stories/share/${token}`, { headers: { Accept: "application/json" } })
      .then(async (r) => {
        const j = await r.json().catch(() => ({}));
        if (!r.ok) return setGone(j.detail ?? "Este enlace no es válido.");
        const d = j.data as Info;
        setInfo(d);
        setF((x) => ({ ...x, company_name: d.client_name, sector: d.sector ?? x.sector, signer_name: d.contact_name, signer_email: d.contact_email }));
      })
      .catch(() => setGone("No pudimos conectar. Prueba de nuevo en unos minutos."));
    fetch(`${API}/api/v1/public/modules`)
      .then((r) => r.json())
      .then((j) => setModules(j.data ?? []))
      .catch(() => undefined);
  }, [token]);

  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
  const err = (k: string) => errors[k]?.[0];

  // Vista del texto que se firma, con los datos que va escribiendo (el servidor arma el texto final igual).
  const preview = useMemo(() => {
    if (!info) return "";
    const fill: Record<string, string> = {
      "{firmante}": f.signer_name || "[tu nombre]",
      "{tipo_documento}": f.signer_doc_type,
      "{documento}": f.signer_doc_number || "[tu documento]",
      "{cargo}": f.signer_role || "[tu cargo]",
      "{empresa}": f.company_name || info.client_name,
      "{ruc_empresa}": f.company_ruc.length === 11 ? ` (RUC ${f.company_ruc})` : "",
      "{titular}": info.owner,
      "{marca}": info.brand,
    };
    return info.authorization_template.replace(/\{[a-z_]+\}/g, (m) => fill[m] ?? m);
  }, [info, f]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setAlert(null);
    const fd = new FormData();
    Object.entries(f).forEach(([k, v]) => v !== "" && fd.append(k, v));
    results.forEach((r, i) => {
      fd.append(`results[${i}][value]`, r.value);
      fd.append(`results[${i}][label]`, r.label);
    });
    picked.forEach((c) => fd.append("modules[]", c));
    if (logo) fd.append("logo", logo);
    fd.append("authorize", authorize ? "1" : "0");
    if (signature) fd.append("signature", signature);
    try {
      const r = await fetch(`${API}/api/v1/public/success-stories/share/${token}`, { method: "POST", headers: { Accept: "application/json" }, body: fd });
      const j = await r.json().catch(() => ({}));
      if (r.ok) {
        setDone(true);
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (r.status === 422) {
        setErrors(j.errors ?? {});
        setAlert("Revisa los campos marcados en rojo.");
      } else if (r.status === 429) setAlert("Hiciste muchos intentos. Espera unos minutos y vuelve a enviar.");
      else setAlert(j.detail ?? "No pudimos enviar. Prueba de nuevo.");
    } catch {
      setAlert("No pudimos conectar. Revisa tu internet y vuelve a enviar.");
    } finally {
      setSaving(false);
    }
  }

  if (gone)
    return (
      <Done icon="lock" title="Este enlace ya no está disponible">
        <p>{gone}</p>
      </Done>
    );
  if (done)
    return (
      <Done icon="check" title="¡Gracias! Recibimos tu historia">
        <p>
          Te enviamos a <b className="text-[var(--site-navy-title)]">{f.signer_email}</b> una copia de la autorización con un enlace para retirarla cuando quieras.
        </p>
        <p className="text-sm">El equipo revisará el caso antes de publicarlo. Si tenemos dudas, te escribimos.</p>
      </Done>
    );
  if (!info) return <div className="site-card h-96 animate-pulse" aria-label="Cargando" />;

  const legend = (icon: string, text: string, sub?: string) => (
    <legend className="mb-1 w-full">
      <span className="flex items-center gap-2 font-display text-lg font-bold text-[var(--site-navy-title)]">
        <span className="grid h-8 w-8 place-items-center rounded-lg bg-[var(--site-surface)] text-[var(--site-action)]">
          <Icon name={icon} size={16} />
        </span>
        {text}
      </span>
      {sub && <span className="mt-1 block text-sm font-normal text-[var(--site-text-2)]">{sub}</span>}
    </legend>
  );

  return (
    <form onSubmit={submit} noValidate className="site-card relative space-y-7 p-6 sm:p-8">
      <Honeypot value={f.website} onChange={(v) => set("website", v)} />
      {info.message && (
        <p className="rounded-xl bg-[var(--site-surface)] px-4 py-3 text-sm text-[var(--site-text)]">
          <b>Mensaje del equipo:</b> {info.message}
        </p>
      )}

      <fieldset className="space-y-4">
        {legend("building", "Tu empresa")}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Nombre de la empresa" error={err("company_name")}>
            <input className="input" maxLength={120} value={f.company_name} onChange={(e) => set("company_name", e.target.value)} />
          </Field>
          <Field label="RUC" optional error={err("company_ruc")}>
            <input className="input tabular-nums" inputMode="numeric" value={f.company_ruc} onChange={(e) => set("company_ruc", onlyDigits(e.target.value, 11))} />
          </Field>
          <Field label="Rubro" error={err("sector")}>
            <select className="input" value={f.sector} onChange={(e) => set("sector", e.target.value)}>
              {Object.entries(STORY_SECTORS).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </Field>
          <Field label="¿Desde qué año usan EscudoSST?" optional error={err("project_year")}>
            <input className="input tabular-nums" inputMode="numeric" value={f.project_year} onChange={(e) => set("project_year", onlyDigits(e.target.value, 4))} />
          </Field>
        </div>
        <Field label="Logo de la empresa" optional hint="PNG, JPG o WEBP, menos de 1 MB. Sin fotos de personas." error={err("logo")}>
          <input type="file" accept="image/png,image/jpeg,image/webp" className="input file:mr-3 file:rounded-lg file:border-0 file:bg-[var(--site-surface)] file:px-3 file:py-1.5 file:font-semibold" onChange={(e) => setLogo(e.target.files?.[0] ?? null)} />
        </Field>
      </fieldset>

      <fieldset className="space-y-4 border-t border-[var(--site-border)] pt-6">
        {legend("pen", "Tu historia", "En pocas líneas y con tus palabras. El equipo puede corregir la redacción, pero no cambia lo que cuentas.")}
        <Field label="Antes: ¿qué problema tenían?" hint="Por ejemplo: «Llevábamos todo en Excel y papel y antes de una inspección nos faltaban firmas»." error={err("challenge")}>
          <textarea className="input min-h-28" maxLength={1500} value={f.challenge} onChange={(e) => set("challenge", e.target.value)} />
        </Field>
        <Field label="Después: ¿cómo lo resolvieron con EscudoSST?" hint="Qué empezaron a usar y qué cambió en el día a día." error={err("solution")}>
          <textarea className="input min-h-28" maxLength={1500} value={f.solution} onChange={(e) => set("solution", e.target.value)} />
        </Field>
        <div>
          <p className="mb-1.5 text-sm font-semibold text-[var(--site-navy-title)]">
            Resultados en números <span className="font-normal text-[var(--site-text-2)]">(opcional, hasta 4)</span>
          </p>
          <p className="mb-2 text-xs text-[var(--site-text-2)]">Solo cifras que la empresa pueda respaldar. Ej.: «3» → «obras en un solo panel».</p>
          <ul className="space-y-2">
            {results.map((r, i) => (
              <li key={i} className="flex gap-2">
                <input className="input w-28 shrink-0 font-semibold" maxLength={20} placeholder="Cifra" aria-label={`Cifra ${i + 1}`} value={r.value} onChange={(e) => setResults(results.map((x, j) => (j === i ? { ...x, value: e.target.value } : x)))} />
                <input className="input" maxLength={80} placeholder="Qué mide" aria-label={`Qué mide la cifra ${i + 1}`} value={r.label} onChange={(e) => setResults(results.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))} />
              </li>
            ))}
          </ul>
          {results.length < 4 && (
            <button type="button" onClick={() => setResults([...results, { value: "", label: "" }])} className="mt-2 inline-flex items-center gap-1 text-sm font-bold text-[var(--site-action)] hover:underline">
              <Icon name="plus" size={14} /> Agregar otro resultado
            </button>
          )}
        </div>
        <Field label="Una frase tuya sobre EscudoSST" optional error={err("quote")}>
          <textarea className="input min-h-20" maxLength={400} value={f.quote} onChange={(e) => set("quote", e.target.value)} />
        </Field>
        {modules.length > 0 && (
          <div>
            <p className="mb-2 text-sm font-semibold text-[var(--site-navy-title)]">
              ¿Qué módulos usan más? <span className="font-normal text-[var(--site-text-2)]">(opcional)</span>
            </p>
            <div className="flex flex-wrap gap-2">
              {modules.map((m) => {
                const c = m.code.trim();
                const on = picked.includes(c);
                return (
                  <button
                    key={c}
                    type="button"
                    aria-pressed={on}
                    onClick={() => setPicked((p) => (p.includes(c) ? p.filter((x) => x !== c) : p.length < 10 ? [...p, c] : p))}
                    className={`rounded-full border px-3 py-1.5 text-sm font-semibold transition-colors ${on ? "border-[var(--site-action)] bg-[var(--site-action)] text-white" : "border-[var(--site-border)] bg-white text-[var(--site-text)] hover:border-[var(--site-action)]"}`}
                  >
                    {m.name}
                  </button>
                );
              })}
            </div>
          </div>
        )}
      </fieldset>

      <fieldset className="space-y-4 border-t border-[var(--site-border)] pt-6">
        {legend("shield", "Autorización", "Quien firma debe poder autorizar en nombre de la empresa (gerencia, jefatura de SST o similar).")}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Tu nombre completo" error={err("signer_name")}>
            <input className="input" maxLength={120} autoComplete="name" value={f.signer_name} onChange={(e) => set("signer_name", e.target.value)} />
          </Field>
          <Field label="Tu cargo" error={err("signer_role")}>
            <input className="input" maxLength={80} placeholder="Jefe(a) de SST" value={f.signer_role} onChange={(e) => set("signer_role", e.target.value)} />
          </Field>
          <Field label="Documento" error={err("signer_doc_number")}>
            <span className="flex gap-2">
              <select className="input w-24 shrink-0" aria-label="Tipo de documento" value={f.signer_doc_type} onChange={(e) => set("signer_doc_type", e.target.value)}>
                <option value="DNI">DNI</option>
                <option value="CE">CE</option>
                <option value="PAS">Pasaporte</option>
              </select>
              <input className="input tabular-nums" maxLength={12} aria-label="Número de documento" value={f.signer_doc_number} onChange={(e) => set("signer_doc_number", e.target.value.replace(/[^A-Za-z0-9]/g, ""))} />
            </span>
          </Field>
          <Field label="Tu correo" hint="Aquí te llega la copia y el enlace para retirar la autorización." error={err("signer_email")}>
            <input className="input" type="email" maxLength={160} autoComplete="email" value={f.signer_email} onChange={(e) => set("signer_email", e.target.value)} />
          </Field>
        </div>
        <div className="max-h-72 overflow-y-auto whitespace-pre-line rounded-xl border border-[var(--site-border)] bg-[var(--site-surface)] p-4 text-sm leading-relaxed text-[var(--site-text)]" aria-label="Texto de la autorización">
          {preview}
        </div>
        <div>
          <p className="mb-1.5 text-sm font-semibold text-[var(--site-navy-title)]">Tu firma</p>
          <SignaturePad onChange={setSignature} error={err("signature")} />
        </div>
        <Check checked={authorize} onChange={setAuthorize} error={err("authorize")}>
          Leí el texto y, con mis datos de arriba, <b>autorizo la publicación</b> del caso en nombre de la empresa.
        </Check>
      </fieldset>

      <Alert text={alert} />
      <Submit saving={saving}>Enviar mi historia</Submit>
      <p className="text-center text-xs text-[var(--site-text-2)]">
        Este enlace es personal y vence el {new Date(info.expires_at).toLocaleDateString("es-PE")}. Se guarda la fecha, la hora y el texto que aceptas como constancia.
      </p>
    </form>
  );
}

export function StoryWithdrawForm({ token }: { token: string }) {
  const [info, setInfo] = useState<{ company_name: string; title: string; accepted_at: string; withdrawn_at: string | null } | null>(null);
  const [gone, setGone] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [alert, setAlert] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  useEffect(() => {
    fetch(`${API}/api/v1/public/success-stories/withdraw/${token}`, { headers: { Accept: "application/json" } })
      .then(async (r) => {
        const j = await r.json().catch(() => ({}));
        if (!r.ok) setGone(j.detail ?? "Este enlace no es válido.");
        else setInfo(j.data);
      })
      .catch(() => setGone("No pudimos conectar. Prueba de nuevo en unos minutos."));
  }, [token]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setAlert(null);
    try {
      const r = await fetch(`${API}/api/v1/public/success-stories/withdraw/${token}`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({ reason: reason || null }),
      });
      if (r.ok) setDone(true);
      else setAlert(r.status === 429 ? "Hiciste muchos intentos. Espera unos minutos." : "No pudimos registrar el retiro. Prueba de nuevo.");
    } catch {
      setAlert("No pudimos conectar. Revisa tu internet.");
    } finally {
      setSaving(false);
    }
  }

  if (gone)
    return (
      <Done icon="lock" title="Este enlace no es válido">
        <p>{gone}</p>
      </Done>
    );
  if (!info) return <div className="site-card h-64 animate-pulse" aria-label="Cargando" />;
  if (done || info.withdrawn_at)
    return (
      <Done icon="check" title="Autorización retirada">
        <p>
          El caso de <b className="text-[var(--site-navy-title)]">{info.company_name}</b> ya no se muestra en nuestro sitio. Te enviamos la confirmación por correo.
        </p>
      </Done>
    );

  return (
    <form onSubmit={submit} className="site-card space-y-5 p-6 sm:p-8">
      <div className="space-y-1">
        <p className="text-sm text-[var(--site-text-2)]">Caso de éxito</p>
        <h2 className="font-display text-xl font-bold text-[var(--site-navy-title)]">{info.title}</h2>
        <p className="text-sm text-[var(--site-text-2)]">
          {info.company_name} · autorizado el {new Date(info.accepted_at).toLocaleDateString("es-PE")}
        </p>
      </div>
      <p className="text-[15px] text-[var(--site-text)]">Si retiras la autorización, el caso deja de mostrarse en el sitio de inmediato. No necesitas dar explicaciones.</p>
      <Field label="¿Quieres contarnos por qué?" optional>
        <textarea className="input min-h-20" maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} />
      </Field>
      <Alert text={alert} />
      <button type="submit" disabled={saving} className="site-btn w-full bg-[var(--site-red)] text-white hover:brightness-95 disabled:opacity-60">
        {saving ? "Retirando…" : "Retirar mi autorización"}
      </button>
    </form>
  );
}
