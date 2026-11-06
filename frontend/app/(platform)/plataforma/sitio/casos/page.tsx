"use client";

// Sitio web › Casos de éxito (SCR-WEB-44). Lo normal es invitar al cliente: le llega un enlace, cuenta su historia y
// firma la autorización en línea (queda la constancia). También se puede cargar uno a mano (con la autorización en
// archivo) o un «Ejemplo». Se publica solo con autorización o la marca «Ejemplo» (RN-WEB-41).

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { assetUrl, STORY_SECTORS } from "@/lib/site";
import { STORY_STATUS, type StoryStatus } from "@/lib/stories";

type Row = { id: string; slug: string; title: string; client_name: string; sector: string; logo_file_id: string | null; project_year: number | null; is_example: boolean; status: StoryStatus; has_consent: boolean };
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

  const list = rows ?? [];
  const pending = invites.filter((i) => i.status === "sent");
  const stats: [string, number, string][] = [
    ["Publicados", list.filter((r) => r.status === "published").length, "globe"],
    ["Por aprobar", list.filter((r) => r.status === "draft" && (r.has_consent || r.is_example)).length, "check"],
    ["Invitaciones abiertas", pending.filter((i) => !i.expired).length, "mail"],
    ["Ejemplos", list.filter((r) => r.is_example).length, "star"],
  ];

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Casos de éxito"]]}
        icon="star"
        title="Casos de éxito"
        description="Historias de clientes que se muestran en /casos. El cliente las cuenta y autoriza en línea; tú las revisas y publicas."
        aside={
          <div className="flex flex-wrap gap-2">
            <Link href="/plataforma/sitio/casos/nuevo" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold shadow-sm hover:border-brand hover:text-brand-strong">
              <Icon name="pen" size={15} /> Cargar a mano
            </Link>
            <Button variant="primary" icon="mail" onClick={() => setInviting(true)}>
              Invitar a un cliente
            </Button>
          </div>
        }
      />

      <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {stats.map(([l, n, icon]) => (
          <li key={l} className="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 shadow-sm">
            <span className="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-brand to-side text-white shadow-sm">
              <Icon name={icon} size={18} />
            </span>
            <span>
              <b className="block font-display text-2xl leading-none">{rows ? n : "–"}</b>
              <span className="text-xs text-muted">{l}</span>
            </span>
          </li>
        ))}
      </ul>

      <section className="rounded-2xl border border-line bg-surface p-4 shadow-sm">
        <h2 className="px-1 font-display font-semibold">Cómo funciona</h2>
        <ol className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-5">
          {STEPS.map(([icon, t, d], i) => (
            <li key={t} className="relative flex items-start gap-3 rounded-xl bg-bg/70 p-3">
              <span className="relative grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong">
                <Icon name={icon} size={16} />
                <span className="absolute -right-1.5 -top-1.5 grid h-4 w-4 place-items-center rounded-full bg-brand text-[10px] font-bold text-white">{i + 1}</span>
              </span>
              <span>
                <b className="block text-sm leading-snug">{t}</b>
                <span className="text-xs text-muted">{d}</span>
              </span>
            </li>
          ))}
        </ol>
      </section>

      <div className="grid grid-cols-1 items-start gap-5 2xl:grid-cols-[minmax(0,1fr)_24rem]">
        <section className="space-y-3">
          <h2 className="font-display text-lg font-semibold">Casos</h2>
          {rows === null ? (
            <p className="text-sm text-muted">Cargando…</p>
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

        <section className="space-y-3">
          <h2 className="flex items-center justify-between font-display text-lg font-semibold">
            Invitaciones
            {pending.length > 0 && <span className="text-sm font-normal text-muted">{pending.length} esperando respuesta</span>}
          </h2>
          <div className="rounded-2xl border border-line bg-surface p-2 shadow-sm">
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
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function InviteModal({ onClose, onDone }: { onClose: () => void; onDone: (email: string) => void }) {
  const [f, setF] = useState({ client_name: "", sector: "", contact_name: "", contact_email: "", message: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [alert, setAlert] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
  const err = (k: string) => errors[k]?.[0];

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
          <FormField label="Empresa del cliente" error={err("client_name")}>
            <input className="input" maxLength={120} value={f.client_name} onChange={(e) => set("client_name", e.target.value)} />
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
