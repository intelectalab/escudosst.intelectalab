"use client";

// /recursos (SCR-WEB-30): tarjetas filtrables por tipo y rubro, buscador, distintivo «Libre» o «Con registro».
// «Libre» descarga al momento; «Con registro» pide un formulario corto. La descarga usa un enlace que vence pronto.

import Link from "next/link";
import { FormEvent, useMemo, useState } from "react";
import { Alert, Check, Field, Honeypot, Submit } from "@/components/site/PublicForms";
import { Icon } from "@/components/ui/Icon";
import { assetUrl, STORY_SECTORS } from "@/lib/site";
import { track } from "@/lib/track";

export type PublicResource = { slug: string; title: string; description: string | null; resource_type: string; sector: string | null; is_gated: boolean; cover_file_id: string | null };

export const RESOURCE_TYPES: Record<string, [string, string]> = {
  template: ["Plantilla", "file"],
  guide: ["Guía", "pen"],
  checklist: ["Lista de verificación", "check"],
  format: ["Formato", "grid"],
};

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export function ResourcesBrowser({ items }: { items: PublicResource[] }) {
  const [type, setType] = useState<string | null>(null);
  const [q, setQ] = useState("");
  const [open, setOpen] = useState<PublicResource | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const list = useMemo(
    () => items.filter((r) => (!type || r.resource_type === type) && (!q || `${r.title} ${r.description ?? ""}`.toLowerCase().includes(q.toLowerCase()))),
    [items, type, q],
  );

  async function free(r: PublicResource) {
    setBusy(r.slug);
    setError(null);
    try {
      const res = await fetch(`${API}/api/v1/public/resources/${r.slug}/download`, { method: "POST", headers: { Accept: "application/json" } });
      const j = await res.json();
      if (!res.ok) throw new Error(j.detail ?? "No se pudo descargar.");
      track("resource_download", r.slug);
      window.location.href = j.data.url;
    } catch (e) {
      setError(e instanceof Error ? e.message : "No se pudo descargar.");
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
        <div className="flex flex-wrap gap-2">
          <Chip on={!type} onClick={() => setType(null)}>
            Todos
          </Chip>
          {Object.entries(RESOURCE_TYPES)
            .filter(([k]) => items.some((r) => r.resource_type === k))
            .map(([k, [l]]) => (
              <Chip key={k} on={type === k} onClick={() => setType(type === k ? null : k)}>
                {l}
              </Chip>
            ))}
        </div>
        <span className="relative lg:ml-auto lg:w-80">
          <Icon name="search" size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--site-text-2)]" />
          <input className="input pl-9" placeholder="Buscar un recurso" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Buscar un recurso" />
        </span>
      </div>
      {error && <Alert text={error} />}
      {list.length === 0 ? (
        <p className="mx-auto max-w-md rounded-2xl bg-[var(--site-surface)] p-8 text-center text-[var(--site-text-2)]">
          {items.length ? "No hay recursos con ese filtro." : "Pronto publicaremos plantillas y guías gratuitas."}
        </p>
      ) : (
        <ul className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {list.map((r) => {
            const [label, icon] = RESOURCE_TYPES[r.resource_type] ?? ["Recurso", "file"];
            return (
              <li key={r.slug} id={r.slug} className="site-card site-card-hover flex flex-col overflow-hidden">
                <span className="relative block aspect-[16/9] bg-gradient-to-br from-[#EAF3FC] to-white">
                  {r.cover_file_id ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={assetUrl(r.cover_file_id) ?? ""} alt="" className="h-full w-full object-cover" />
                  ) : (
                    <span className="grid h-full place-items-center text-[var(--site-action)]">
                      <Icon name={icon} size={40} />
                    </span>
                  )}
                  <span className={`absolute right-3 top-3 rounded-full px-2.5 py-1 text-xs font-bold ${r.is_gated ? "bg-[var(--site-navy)] text-white" : "bg-[#22A35A] text-white"}`}>{r.is_gated ? "Con registro" : "Libre"}</span>
                </span>
                <span className="flex flex-1 flex-col gap-2 p-5">
                  <span className="text-xs font-bold uppercase tracking-wide text-[var(--site-action)]">
                    {label}
                    {r.sector && <span className="text-[var(--site-text-2)]"> · {STORY_SECTORS[r.sector] ?? r.sector}</span>}
                  </span>
                  <b className="font-display text-lg leading-snug text-[var(--site-navy-title)]">{r.title}</b>
                  {r.description && <span className="text-sm text-[var(--site-text-2)]">{r.description}</span>}
                  <button type="button" disabled={busy === r.slug} onClick={() => (r.is_gated ? setOpen(r) : free(r))} className="site-btn site-btn-primary mt-auto w-full !py-2.5 disabled:opacity-60">
                    <Icon name="file" size={16} /> {busy === r.slug ? "Preparando…" : "Descargar gratis"}
                  </button>
                </span>
              </li>
            );
          })}
        </ul>
      )}
      {open && <GateForm r={open} onClose={() => setOpen(null)} />}
    </div>
  );
}

function Chip({ on, onClick, children }: { on: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button type="button" aria-pressed={on} onClick={onClick} className={`rounded-full border px-4 py-2 text-sm font-bold transition-colors ${on ? "border-[var(--site-action)] bg-[var(--site-action)] text-white" : "border-[var(--site-border)] bg-white text-[var(--site-text)] hover:border-[var(--site-action)]"}`}>
      {children}
    </button>
  );
}

/** Formulario corto antes de descargar un recurso «con registro». */
function GateForm({ r, onClose }: { r: PublicResource; onClose: () => void }) {
  const [f, setF] = useState({ full_name: "", email: "", company_name: "", phone: "", website: "" });
  const [privacy, setPrivacy] = useState(false);
  const [marketing, setMarketing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [alert, setAlert] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const err = (k: string) => errors[k]?.[0];

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setAlert(null);
    try {
      const res = await fetch(`${API}/api/v1/public/resources/${r.slug}/download`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({ ...f, sector: r.sector, privacy_consent: privacy, marketing_consent: marketing }),
      });
      const j = await res.json().catch(() => ({}));
      if (res.ok) {
        setDone(j.data.url);
        track("resource_download", r.slug);
      window.location.href = j.data.url;
      } else if (res.status === 422) {
        setErrors(j.errors ?? {});
        setAlert("Revisa los campos marcados.");
      } else if (res.status === 429) setAlert("Hiciste muchos intentos. Espera unos minutos.");
      else setAlert(j.detail ?? "No se pudo descargar.");
    } catch {
      setAlert("No pudimos conectar. Revisa tu internet.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label={`Descargar ${r.title}`}>
      <div className="site-card max-h-[92vh] w-full max-w-lg overflow-y-auto p-6 sm:p-7">
        <div className="mb-4 flex items-start justify-between gap-3">
          <div>
            <p className="site-eyebrow">Descarga gratis</p>
            <h2 className="font-display text-xl font-bold text-[var(--site-navy-title)]">{r.title}</h2>
          </div>
          <button type="button" onClick={onClose} aria-label="Cerrar" className="grid h-9 w-9 place-items-center rounded-full hover:bg-[var(--site-surface)]">
            <Icon name="close" size={18} />
          </button>
        </div>
        {done ? (
          <div className="space-y-3 text-center">
            <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[#22A35A]/12 text-[#167A42]">
              <Icon name="check" size={26} />
            </span>
            <p className="text-[var(--site-text)]">¡Listo! La descarga empezó.</p>
            <p className="text-sm text-[var(--site-text-2)]">
              Si no empezó,{" "}
              <a href={done} className="font-bold text-[var(--site-action)] underline">
                descárgalo aquí
              </a>{" "}
              (el enlace vence en 10 minutos).
            </p>
          </div>
        ) : (
          <form onSubmit={submit} noValidate className="relative space-y-4">
            <Honeypot value={f.website} onChange={(v) => setF({ ...f, website: v })} />
            <Field label="Nombre y apellido" error={err("full_name")}>
              <input className="input" autoComplete="name" maxLength={120} value={f.full_name} onChange={(e) => setF({ ...f, full_name: e.target.value })} />
            </Field>
            <Field label="Correo de trabajo" error={err("email")}>
              <input className="input" type="email" autoComplete="email" maxLength={160} value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} />
            </Field>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Empresa" error={err("company_name")}>
                <input className="input" autoComplete="organization" maxLength={160} value={f.company_name} onChange={(e) => setF({ ...f, company_name: e.target.value })} />
              </Field>
              <Field label="Teléfono" optional error={err("phone")}>
                <input className="input" type="tel" maxLength={20} value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} />
              </Field>
            </div>
            <Check checked={privacy} onChange={setPrivacy} error={err("privacy_consent")}>
              Leí la{" "}
              <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-[var(--site-action)] underline">
                Política de privacidad
              </Link>
              .
            </Check>
            <Check checked={marketing} onChange={setMarketing}>
              Quiero recibir novedades y recursos de SST por correo (opcional).
            </Check>
            <Alert text={alert} />
            <Submit saving={saving}>Descargar</Submit>
          </form>
        )}
      </div>
    </div>
  );
}
