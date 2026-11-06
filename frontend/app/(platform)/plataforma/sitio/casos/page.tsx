"use client";

// Sitio web › Casos de éxito (SCR-WEB-44). Lo normal es invitar al cliente: le llega un enlace, cuenta su historia y
// firma la autorización en línea (queda la constancia). También se puede cargar uno a mano (con la autorización en
// archivo) o un «Ejemplo». Se publica solo con autorización o la marca «Ejemplo» (RN-WEB-41).

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { assetUrl, STORY_SECTORS } from "@/lib/site";
import { STORY_STATUS, type StoryStatus } from "@/lib/stories";

type Row = { id: string; slug: string; title: string; client_name: string; sector: string; logo_file_id: string | null; project_year: number | null; is_example: boolean; status: StoryStatus; has_consent: boolean };
type Filter = "all" | "published" | "pending" | "invites" | "examples";
type Client = { id: string; legal_name: string; trade_name: string | null; ruc: string; contact_name: string | null; contact_email: string | null };
type Invite = { id: string; client_name: string; contact_name: string; contact_email: string; status: "sent" | "submitted"; expired: boolean; expires_at: string; sent_count: number; last_sent_at: string; story_id: string | null };

const STEPS = [
  ["mail", "Invitas al cliente", "Le llega un correo con un enlace personal (vence en 30 días)."],
  ["pen", "Cuenta su historia y firma", "Escribe el antes y el después, sus cifras y autoriza con nombre, cargo y documento."],
  ["shield", "Queda la constancia", "Texto aceptado, fecha, hora y huella. El cliente recibe una copia."],
  ["check", "Revisas y publicas", "Llega como borrador: lo revisas, apruebas y publicas."],
  ["lock", "Retiro automático", "Si el cliente usa el enlace de su correo, el caso se oculta solo."],
] as const;

export default function CasosPanelPage() {
  const [rows, setRows] = useState<Row[] | null>(null);
  const [invites, setInvites] = useState<Invite[]>([]);
  const [inviting, setInviting] = useState(false);
  const [editingText, setEditingText] = useState(false);
  const [filter, setFilter] = useState<Filter>("all");
  const [busy, setBusy] = useState<string | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: Row[] }>("/internal/site/success-stories")
      .then((r) => setRows(r.data))
      .catch(() => setRows([]));
    api<{ data: Invite[] }>("/internal/site/success-stories/invites")
      .then((r) => setInvites(r.data))
      .catch(() => undefined);
  }, []);
  useEffect(load, [load]);

  async function inviteAction(id: string, action: "resend" | "cancel") {
    if (action === "cancel" && !window.confirm("¿Cancelar la invitación? El enlace dejará de servir.")) return;
    setBusy(id + action);
    try {
      await api(`/internal/site/success-stories/invites/${id}${action === "resend" ? "/resend" : ""}`, { method: action === "resend" ? "POST" : "DELETE" });
      setToast({ text: action === "resend" ? "Reenviada con un enlace nuevo (el anterior ya no sirve)." : "Invitación cancelada.", tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: e instanceof ApiError ? (e.detail ?? e.message) : "No se pudo.", tone: "bad" });
    } finally {
      setBusy(null);
    }
  }

  const all = rows ?? [];
  const pending = invites.filter((i) => i.status === "sent");
  const isPending = (r: Row) => r.status === "draft" && (r.has_consent || r.is_example);
  const list = all.filter((r) => (filter === "published" ? r.status === "published" : filter === "pending" ? isPending(r) : filter === "examples" ? r.is_example : true));
  const toggle = (f: Filter) => setFilter((x) => (x === f ? "all" : f));

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Casos de éxito"]]}
        icon="star"
        title="Casos de éxito"
        description="Historias de clientes que se muestran en /casos. El cliente las cuenta y autoriza en línea; tú las revisas y publicas."
        aside={
          <div className="flex flex-wrap gap-2">
            <Button icon="file" onClick={() => setEditingText(true)}>
              Texto de autorización
            </Button>
            <Link href="/plataforma/sitio/casos/nuevo" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold shadow-sm hover:border-brand hover:text-brand-strong">
              <Icon name="pen" size={15} /> Cargar a mano
            </Link>
            <Button variant="primary" icon="mail" onClick={() => setInviting(true)}>
              Invitar a un cliente
            </Button>
          </div>
        }
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Publicados" value={rows ? all.filter((r) => r.status === "published").length : "–"} icon="globe" tone="ok" hint="Se ven en /casos" active={filter === "published"} onClick={() => toggle("published")} />
        <StatCard label="Por aprobar" value={rows ? all.filter(isPending).length : "–"} icon="check" tone="amber" hint="Con autorización, esperan tu revisión" active={filter === "pending"} onClick={() => toggle("pending")} />
        <StatCard label="Invitaciones abiertas" value={pending.filter((i) => !i.expired).length} icon="mail" tone="brand" hint="Esperando que el cliente responda" active={filter === "invites"} onClick={() => toggle("invites")} />
        <StatCard label="Ejemplos" value={rows ? all.filter((r) => r.is_example).length : "–"} icon="star" tone="na" hint="Casos de muestra, no reales" active={filter === "examples"} onClick={() => toggle("examples")} />
      </div>

      <section className="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
        <h2 className="border-b border-line bg-gradient-to-r from-side to-brand px-5 py-2.5 text-sm font-semibold text-white">Cómo funciona</h2>
        <ol className="grid grid-cols-1 gap-0 sm:grid-cols-2 xl:grid-cols-5">
          {STEPS.map(([icon, t, d], i) => (
            <li key={t} className="relative flex gap-3 border-line p-4 sm:[&:not(:last-child)]:border-r">
              <span className="relative grid h-9 w-9 shrink-0 place-items-center rounded-full bg-side text-white ring-4 ring-brand-soft">
                <Icon name={icon} size={16} />
              </span>
              <span>
                <span className="text-[11px] font-bold uppercase tracking-wider text-brand">Paso {i + 1}</span>
                <b className="block text-sm leading-snug">{t}</b>
                <span className="text-xs text-muted">{d}</span>
              </span>
            </li>
          ))}
        </ol>
      </section>

      <div className="grid grid-cols-1 items-start gap-5 2xl:grid-cols-[minmax(0,1fr)_24rem]">
        <section className={`space-y-3 ${filter === "invites" ? "hidden" : ""}`}>
          <h2 className="flex items-center justify-between font-display text-lg font-semibold">
            Casos
            {filter !== "all" && filter !== "invites" && (
              <button type="button" onClick={() => setFilter("all")} className="text-sm font-normal text-brand hover:underline">
                Ver todos
              </button>
            )}
          </h2>
          {rows === null ? (
            <p className="text-sm text-muted">Cargando…</p>
          ) : list.length === 0 && all.length > 0 ? (
            <p className="rounded-2xl border border-dashed border-line bg-surface p-6 text-center text-sm text-muted">Ningún caso en este filtro.</p>
          ) : list.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-line bg-surface p-10 text-center">
              <span className="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-brand-soft text-brand-strong">
                <Icon name="star" size={22} />
              </span>
              <p className="mt-3 font-semibold">Todavía no hay casos</p>
              <p className="mt-1 text-sm text-muted">Invita a un cliente contento. Mientras tanto, puedes cargar uno marcado como «Ejemplo».</p>
            </div>
          ) : (
            <ul className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
              {list.map((r) => (
                <li key={r.id}>
                  <Link href={`/plataforma/sitio/casos/${r.id}`} className="group flex h-full flex-col gap-3 rounded-2xl border border-line bg-surface p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-md">
                    <span className="flex items-start gap-3">
                      {r.logo_file_id ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img src={assetUrl(r.logo_file_id) ?? ""} alt="" className="h-11 w-11 shrink-0 rounded-xl border border-line bg-white object-contain p-1" />
                      ) : (
                        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-brand-soft to-bg font-display text-lg font-bold text-brand-strong">{r.client_name.charAt(0).toUpperCase()}</span>
                      )}
                      <span className="min-w-0">
                        <b className="line-clamp-2 font-display text-[15px] leading-snug group-hover:text-brand-strong">{r.title}</b>
                        <span className="mt-0.5 block truncate text-xs text-muted">
                          {r.client_name} · {STORY_SECTORS[r.sector] ?? r.sector}
                          {r.project_year && ` · ${r.project_year}`}
                        </span>
                      </span>
                    </span>
                    <span className="mt-auto flex flex-wrap items-center gap-1.5">
                      <Chip tone={STORY_STATUS[r.status][1]} dot>
                        {STORY_STATUS[r.status][0]}
                      </Chip>
                      {r.is_example ? <Chip tone="amber">Ejemplo</Chip> : r.has_consent ? <Chip tone="ok">Autorizado</Chip> : <Chip tone="bad">Sin autorización</Chip>}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className={`space-y-3 ${filter === "invites" ? "2xl:col-span-2" : ""}`}>
          <h2 className="flex items-center justify-between font-display text-lg font-semibold">
            Invitaciones
            {pending.length > 0 && <span className="text-sm font-normal text-muted">{pending.length} esperando respuesta</span>}
          </h2>
          <div className={`rounded-2xl border bg-surface p-2 shadow-sm ${filter === "invites" ? "border-brand ring-2 ring-brand/20" : "border-line"}`}>
            {invites.length === 0 ? (
              <p className="p-4 text-center text-sm text-muted">Aún no invitaste a nadie.</p>
            ) : (
              <ul className="divide-y divide-line">
                {invites.map((i) => (
                  <li key={i.id} className="space-y-1.5 p-3">
                    <div className="flex items-start justify-between gap-2">
                      <span className="min-w-0">
                        <b className="block truncate text-sm">{i.client_name}</b>
                        <span className="block truncate text-xs text-muted">
                          {i.contact_name} · {i.contact_email}
                        </span>
                      </span>
                      {i.status === "submitted" ? <Chip tone="ok">Respondió</Chip> : i.expired ? <Chip tone="bad">Venció</Chip> : <Chip tone="brand">Enviada</Chip>}
                    </div>
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                      {i.status === "submitted" && i.story_id ? (
                        <Link href={`/plataforma/sitio/casos/${i.story_id}`} className="font-semibold text-brand hover:underline">
                          Revisar el caso →
                        </Link>
                      ) : (
                        <>
                          <span className="text-muted">
                            {i.expired ? "Venció" : "Vence"} el {new Date(i.expires_at).toLocaleDateString("es-PE")}
                            {i.sent_count > 1 && ` · enviada ${i.sent_count} veces`}
                          </span>
                          <button type="button" disabled={busy === i.id + "resend"} onClick={() => inviteAction(i.id, "resend")} className="font-semibold text-brand hover:underline disabled:opacity-50">
                            Reenviar
                          </button>
                          <button type="button" disabled={busy === i.id + "cancel"} onClick={() => inviteAction(i.id, "cancel")} className="font-semibold text-bad hover:underline disabled:opacity-50">
                            Cancelar
                          </button>
                        </>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </section>
      </div>

      {inviting && (
        <InviteModal
          onClose={() => setInviting(false)}
          onDone={(email) => {
            setInviting(false);
            setToast({ text: `Invitación enviada a ${email}.`, tone: "ok" });
            load();
          }}
        />
      )}
      {editingText && (
        <AuthorizationTextModal
          onClose={() => setEditingText(false)}
          onSaved={() => {
            setEditingText(false);
            setToast({ text: "Texto guardado. Se usa desde ahora; lo ya firmado conserva su versión.", tone: "ok" });
          }}
        />
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

type Template = { template: string; is_default: boolean; version: string; default: string; placeholders: Record<string, string>; required: string[] };

/** Texto de la autorización que firma el cliente. Lo cambia el Dueño; cada cambio es una versión nueva. */
function AuthorizationTextModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [t, setT] = useState<Template | null>(null);
  const [text, setText] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api<{ data: Template }>("/internal/site/success-stories/authorization-text").then((r) => {
      setT(r.data);
      setText(r.data.template);
    });
  }, []);

  async function save(template: string | null) {
    setSaving(true);
    setError(null);
    try {
      await api("/internal/site/success-stories/authorization-text", { method: "PUT", body: { template } });
      onSaved();
    } catch (e) {
      setError(e instanceof ApiError ? (e.errors?.template?.[0] ?? e.detail ?? e.message) : "No se pudo guardar.");
      setSaving(false);
    }
  }

  const missing = t ? t.required.filter((m) => !text.includes(m)) : [];

  return (
    <Modal
      title="Texto de la autorización"
      icon="file"
      onClose={onClose}
      footer={
        <>
          {t && !t.is_default && (
            <Button variant="ghost" onClick={() => save(null)} disabled={saving}>
              Volver al texto modelo
            </Button>
          )}
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" icon="check" loading={saving} disabled={!t || missing.length > 0 || text === t.template} onClick={() => save(text)}>
            Guardar versión nueva
          </Button>
        </>
      }
    >
      {!t ? (
        <div className="h-64 animate-pulse rounded-xl bg-line/40" />
      ) : (
        <div className="space-y-4">
          <p className="flex flex-wrap items-center gap-2 text-sm">
            <Chip tone={t.is_default ? "amber" : "ok"}>{t.is_default ? "Texto modelo" : "Texto propio"}</Chip>
            <span className="text-muted">Versión vigente: {t.version}</span>
          </p>
          <p className="rounded-lg bg-amber-soft/70 px-3 py-2 text-xs text-amber-ink">
            Es el texto que el cliente lee y firma. El modelo debe revisarlo un abogado antes de usarlo con clientes reales. Lo ya firmado no cambia: cada constancia guarda su texto exacto.
          </p>
          <FormField label="Texto" error={error ?? (missing.length ? `Faltan marcadores obligatorios: ${missing.join(", ")}` : undefined)}>
            <textarea className="input min-h-72 font-mono text-[13px] leading-relaxed" value={text} maxLength={6000} onChange={(e) => setText(e.target.value)} />
          </FormField>
          <div>
            <p className="mb-1.5 text-xs font-semibold text-muted">Marcadores (se reemplazan con los datos de quien firma). Tócalos para copiarlos.</p>
            <div className="flex flex-wrap gap-1.5">
              {Object.entries(t.placeholders).map(([k, d]) => (
                <button key={k} type="button" title={d} onClick={() => navigator.clipboard?.writeText(k)} className={`rounded-md border px-2 py-1 font-mono text-xs ${t.required.includes(k) ? "border-brand/40 bg-brand-soft text-brand-strong" : "border-line bg-bg"}`}>
                  {k}
                </button>
              ))}
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
}

function InviteModal({ onClose, onDone }: { onClose: () => void; onDone: (email: string) => void }) {
  const [f, setF] = useState({ client_name: "", sector: "", contact_name: "", contact_email: "", message: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [alert, setAlert] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [results, setResults] = useState<Client[]>([]);
  const [open, setOpen] = useState(false);
  const [picked, setPicked] = useState<Client | null>(null);
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
  const err = (k: string) => errors[k]?.[0];

  // Buscador de empresas registradas: al elegir una, se llena el contacto con su administrador de cuenta.
  useEffect(() => {
    if (picked) return;
    const t = window.setTimeout(() => {
      api<{ data: Client[] }>(`/internal/site/success-stories/clients?q=${encodeURIComponent(f.client_name)}`)
        .then((r) => setResults(r.data))
        .catch(() => setResults([]));
    }, 250);
    return () => window.clearTimeout(t);
  }, [f.client_name, picked]);

  function pick(c: Client) {
    setPicked(c);
    setOpen(false);
    setF((x) => ({ ...x, client_name: c.trade_name || c.legal_name, contact_name: c.contact_name ?? x.contact_name, contact_email: c.contact_email ?? x.contact_email }));
  }

  async function send(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setAlert(null);
    try {
      await api("/internal/site/success-stories/invites", { method: "POST", body: { ...f, sector: f.sector || null, message: f.message || null } });
      onDone(f.contact_email);
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.errors ?? {});
        if (!err.errors) setAlert(err.detail ?? err.message);
      }
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Invitar a un cliente"
      icon="mail"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="invite-story" icon="mail" loading={saving}>
            Enviar invitación
          </Button>
        </>
      }
    >
      <form id="invite-story" onSubmit={send} className="space-y-4" noValidate>
        <p className="rounded-lg bg-brand-soft/60 px-3 py-2 text-sm text-brand-strong">Le llega un correo con un enlace para contar su historia y autorizar la publicación. No tienes que enviar ni recibir archivos.</p>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Empresa del cliente" error={err("client_name")} hint={picked ? `Cliente registrado · RUC ${picked.ruc}` : "Busca por nombre o RUC, o escribe una empresa que no esté registrada."}>
            <span className="relative block">
              <input
                className="input pr-9"
                maxLength={120}
                value={f.client_name}
                role="combobox"
                aria-controls="client-options"
                aria-expanded={open}
                aria-autocomplete="list"
                onFocus={() => setOpen(true)}
                onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                onChange={(e) => {
                  setPicked(null);
                  setOpen(true);
                  set("client_name", e.target.value);
                }}
              />
              <Icon name={picked ? "check" : "search"} size={16} className={`absolute right-3 top-1/2 -translate-y-1/2 ${picked ? "text-ok" : "text-muted"}`} />
              {open && !picked && results.length > 0 && (
                <ul id="client-options" role="listbox" className="absolute left-0 right-0 top-full z-20 mt-1 max-h-64 overflow-y-auto rounded-xl border border-line bg-surface p-1 shadow-xl">
                  {results.map((c) => (
                    <li key={c.id}>
                      <button type="button" role="option" aria-selected={false} onMouseDown={(e) => e.preventDefault()} onClick={() => pick(c)} className="flex w-full items-start gap-2.5 rounded-lg px-2.5 py-2 text-left hover:bg-bg">
                        <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-soft text-xs font-bold text-brand-strong">{(c.trade_name || c.legal_name).charAt(0)}</span>
                        <span className="min-w-0">
                          <b className="block truncate text-sm">{c.trade_name || c.legal_name}</b>
                          <span className="block truncate text-xs text-muted">
                            RUC {c.ruc}
                            {c.contact_name ? ` · ${c.contact_name}` : " · sin administrador"}
                          </span>
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </span>
          </FormField>
          <FormField label="Rubro (opcional)" error={err("sector")}>
            <select className="input" value={f.sector} onChange={(e) => set("sector", e.target.value)}>
              <option value="">Que lo elija el cliente</option>
              {Object.entries(STORY_SECTORS).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Nombre del contacto" error={err("contact_name")}>
            <input className="input" maxLength={120} value={f.contact_name} onChange={(e) => set("contact_name", e.target.value)} />
          </FormField>
          <FormField label="Correo del contacto" error={err("contact_email")}>
            <input className="input" type="email" maxLength={160} value={f.contact_email} onChange={(e) => set("contact_email", e.target.value)} />
          </FormField>
        </div>
        <FormField label="Mensaje personal (opcional)" hint="Aparece en el correo y arriba del formulario." error={err("message")}>
          <textarea className="input min-h-20" maxLength={600} value={f.message} onChange={(e) => set("message", e.target.value)} />
        </FormField>
        {alert && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{alert}</p>}
      </form>
    </Modal>
  );
}
