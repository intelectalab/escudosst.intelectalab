"use client";

// Sitio web › Boletín (SCR-WEB-47): suscriptores por estado y tema, y ediciones (redactar, vista previa, envío de
// prueba a tu correo, programar o enviar ya). Solo el Dueño envía o programa. Una edición enviada no se edita.

import { FormEvent, useCallback, useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { HelpBody } from "@/components/site/HelpBody";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Issue = {
  id: string;
  subject: string;
  slug: string;
  preheader: string | null;
  body: string;
  topics_json: string[];
  status: "draft" | "scheduled" | "sent";
  scheduled_at: string | null;
  sent_at: string | null;
  recipients_count: number;
  unsubscribes_count: number;
  failed: number;
};
type Data = {
  subscribers: Record<string, number>;
  by_topic: Record<string, number>;
  confirmed: { email: string; topics_json: string[]; confirmed_at: string }[];
  month: { altas: number; bajas: number };
  issues: Issue[];
};

const STATUS = { draft: ["Borrador", "amber"], scheduled: ["Programada", "brand"], sent: ["Enviada", "ok"] } as const;
const when = (d: string) => new Date(d).toLocaleString("es-PE", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit", timeZone: "America/Lima" });
const errText = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.message) : "No se pudo.");

export default function BoletinPanelPage() {
  const me = usePlatformUser();
  const owner = me.platform_role !== "support";
  const [data, setData] = useState<Data | null>(null);
  const [topics, setTopics] = useState<Record<string, string>>({});
  const [tab, setTab] = useState<"issues" | "subs">("issues");
  const [editing, setEditing] = useState<Issue | "new" | null>(null);
  const [sending, setSending] = useState<Issue | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: Data; topics: Record<string, string> }>("/internal/site/newsletter").then((r) => {
      setData(r.data);
      setTopics(r.topics);
    });
  }, []);
  useEffect(load, [load]);

  async function act(i: Issue, action: "test" | "cancel" | "delete") {
    if (action === "delete" && !window.confirm(`¿Eliminar el borrador «${i.subject}»?`)) return;
    try {
      const r = await api<{ data?: { email: string } }>(action === "delete" ? `/internal/site/newsletter/issues/${i.id}` : `/internal/site/newsletter/issues/${i.id}/${action}`, { method: action === "delete" ? "DELETE" : "POST" });
      setToast({ text: action === "test" ? `Prueba enviada a ${r?.data?.email}.` : action === "cancel" ? "Envío cancelado: volvió a borrador." : "Borrador eliminado.", tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: errText(e), tone: "bad" });
    }
  }

  const subs = data?.subscribers ?? {};
  const issues = data?.issues ?? [];
  const tLabel = (t: string) => topics[t] ?? (t === "status" ? "Estado del servicio" : t);

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Boletín"]]}
        icon="mail"
        title="Boletín normativo"
        description="Las personas se suscriben en /boletin y confirman su correo. Aquí escribes cada edición, te la envías de prueba y la mandas a quienes eligieron esos temas."
        aside={
          <Button variant="primary" icon="plus" onClick={() => setEditing("new")}>
            Nueva edición
          </Button>
        }
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Suscriptores" value={data ? (subs.confirmed ?? 0) : "–"} icon="people" tone="ok" hint="Confirmaron su correo" active={tab === "subs"} onClick={() => setTab(tab === "subs" ? "issues" : "subs")} />
        <StatCard label="Por confirmar" value={data ? (subs.pending ?? 0) : "–"} icon="clock" tone="amber" hint="No abrieron el enlace aún" />
        <StatCard label="Este mes" value={data ? `+${data.month.altas}` : "–"} icon="star" tone="brand" hint={data ? `${data.month.bajas} baja${data.month.bajas === 1 ? "" : "s"}` : ""} />
        <StatCard label="Ediciones enviadas" value={data ? issues.filter((i) => i.status === "sent").length : "–"} icon="mail" tone="na" hint={`${issues.filter((i) => i.status === "draft").length} en borrador`} active={tab === "issues"} onClick={() => setTab("issues")} />
      </div>

      <div className="rounded-2xl border border-brand/15 bg-gradient-to-r from-brand-soft/70 to-surface p-4 text-sm">
        <p className="font-semibold text-brand-strong">Cómo funciona</p>
        <ul className="mt-1 grid grid-cols-1 gap-x-6 gap-y-1 text-muted md:grid-cols-3">
          <li>
            <b className="text-fg">1. Suscripción:</b> la persona elige temas y confirma con el enlace que le llega. Solo los confirmados reciben correos.
          </li>
          <li>
            <b className="text-fg">2. Edición:</b> escribes el texto, eliges los temas y te mandas una prueba para ver cómo llega.
          </li>
          <li>
            <b className="text-fg">3. Envío:</b> la mandas ya o la programas. Cada correo lleva su botón de baja y la edición queda en /boletin.
          </li>
        </ul>
      </div>

      {data === null ? (
        <div className="h-48 animate-pulse rounded-2xl bg-line/40" />
      ) : tab === "subs" ? (
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-[320px_minmax(0,1fr)]">
          <section className="rounded-2xl border border-line bg-surface p-4 shadow-sm xl:self-start">
            <p className="text-xs font-bold uppercase tracking-wide text-muted">Confirmados por tema</p>
            <ul className="mt-3 space-y-2">
              {Object.entries(data.by_topic).map(([t, n]) => (
                <li key={t} className="flex items-center justify-between gap-2 text-sm">
                  <span>{tLabel(t)}</span>
                  <b>{n}</b>
                </li>
              ))}
            </ul>
            <p className="mt-3 text-xs text-muted">«Estado del servicio» son quienes pidieron avisos de interrupciones; no reciben el boletín salvo que también lo eligieran.</p>
          </section>
          <section className="min-w-0 rounded-2xl border border-line bg-surface shadow-sm">
            <p className="border-b border-line px-4 py-3 text-sm font-semibold">Suscriptores confirmados ({data.confirmed.length})</p>
            {data.confirmed.length === 0 ? (
              <p className="p-6 text-sm text-muted">Aún nadie confirmó su suscripción.</p>
            ) : (
              <ul className="max-h-[520px] divide-y divide-line overflow-y-auto">
                {data.confirmed.map((c) => (
                  <li key={c.email} className="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm">
                    <span className="min-w-0 flex-1 truncate font-medium">{c.email}</span>
                    <span className="flex flex-wrap gap-1">
                      {c.topics_json.map((t) => (
                        <Chip key={t} tone={t === "status" ? "na" : "brand"}>
                          {tLabel(t)}
                        </Chip>
                      ))}
                    </span>
                    <span className="text-xs text-muted">{new Date(c.confirmed_at).toLocaleDateString("es-PE", { timeZone: "America/Lima" })}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      ) : issues.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-line bg-surface p-10 text-center">
          <span className="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-brand-soft text-brand-strong">
            <Icon name="mail" size={22} />
          </span>
          <p className="mt-3 font-semibold">Todavía no hay ediciones</p>
          <p className="mt-1 text-sm text-muted">Crea la primera con «Nueva edición».</p>
        </div>
      ) : (
        <ul className="grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">
          {issues.map((i) => (
            <li key={i.id} className="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
              <div className="p-4">
                <span className="flex flex-wrap gap-1.5">
                  <Chip tone={STATUS[i.status][1]} dot>
                    {STATUS[i.status][0]}
                  </Chip>
                  {i.topics_json.map((t) => (
                    <Chip key={t} tone="na">
                      {tLabel(t)}
                    </Chip>
                  ))}
                </span>
                <b className="mt-1.5 block font-display text-[15px] leading-snug">{i.subject}</b>
                <span className="block text-xs text-muted">
                  {i.status === "sent" && i.sent_at && `Enviada el ${when(i.sent_at)}`}
                  {i.status === "scheduled" && i.scheduled_at && `Sale el ${when(i.scheduled_at)}`}
                  {i.status === "draft" && "Aún no se envía"}
                </span>
                {i.status === "sent" && (
                  <div className="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                    <span className="rounded-lg bg-ok-soft p-2">
                      <b className="block text-base text-ok">{i.recipients_count}</b>recibieron
                    </span>
                    <span className="rounded-lg bg-bad-soft p-2">
                      <b className="block text-base text-bad">{i.unsubscribes_count}</b>bajas
                    </span>
                    <span className="rounded-lg bg-bg p-2">
                      <b className="block text-base">{i.failed}</b>fallidos
                    </span>
                  </div>
                )}
              </div>
              <div className="mt-auto flex flex-wrap gap-1 border-t border-line bg-bg/50 px-3 py-2">
                {i.status === "draft" && (
                  <Button size="sm" variant="ghost" icon="pen" onClick={() => setEditing(i)}>
                    Editar
                  </Button>
                )}
                {i.status !== "sent" && (
                  <Button size="sm" variant="ghost" icon="mail" onClick={() => act(i, "test")}>
                    Enviarme prueba
                  </Button>
                )}
                {owner && i.status === "draft" && (
                  <Button size="sm" variant="ghost" icon="play" onClick={() => setSending(i)}>
                    Enviar
                  </Button>
                )}
                {owner && i.status === "scheduled" && (
                  <Button size="sm" variant="ghost" onClick={() => act(i, "cancel")}>
                    Cancelar envío
                  </Button>
                )}
                {i.status === "sent" && (
                  <a href={`/boletin/${i.slug}`} target="_blank" rel="noopener" className="inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-sm font-semibold text-brand hover:bg-brand-soft">
                    <Icon name="globe" size={14} /> Ver
                  </a>
                )}
                {i.status === "draft" && (
                  <Button size="sm" variant="ghost" onClick={() => act(i, "delete")}>
                    Eliminar
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {editing && (
        <IssueModal
          row={editing === "new" ? null : editing}
          topics={topics}
          onClose={() => setEditing(null)}
          onDone={(t) => {
            setEditing(null);
            setToast({ text: t, tone: "ok" });
            load();
          }}
        />
      )}
      {sending && (
        <SendModal
          issue={sending}
          tLabel={tLabel}
          confirmed={data?.by_topic ?? {}}
          onClose={() => setSending(null)}
          onDone={(t) => {
            setSending(null);
            setToast({ text: t, tone: "ok" });
            load();
          }}
        />
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function IssueModal({ row, topics, onClose, onDone }: { row: Issue | null; topics: Record<string, string>; onClose: () => void; onDone: (t: string) => void }) {
  const [f, setF] = useState({ subject: row?.subject ?? "", preheader: row?.preheader ?? "", body: row?.body ?? "", topics: row?.topics_json ?? Object.keys(topics), slug: row?.slug ?? "" });
  const [tab, setTab] = useState<"write" | "preview">("write");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const toggle = (t: string) => setF({ ...f, topics: f.topics.includes(t) ? f.topics.filter((x) => x !== t) : [...f.topics, t] });

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api(row ? `/internal/site/newsletter/issues/${row.id}` : "/internal/site/newsletter/issues", { method: row ? "PATCH" : "POST", body: { ...f, slug: f.slug || null, preheader: f.preheader || null } });
      onDone(row ? "Edición guardada." : "Edición creada como borrador. Envíate una prueba antes de mandarla.");
    } catch (x) {
      if (x instanceof ApiError) setErrors(x.errors ?? { subject: [x.detail ?? x.message] });
      setSaving(false);
    }
  }

  return (
    <Modal
      title={row ? "Editar edición" : "Nueva edición"}
      icon="mail"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="nl-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="nl-form" onSubmit={save} className="space-y-4" noValidate>
        <FormField label="Asunto del correo" error={errors.subject?.[0]} counter={`${f.subject.length}/140`}>
          <input className="input" maxLength={140} value={f.subject} onChange={(e) => setF({ ...f, subject: e.target.value })} placeholder="Cambios en el reglamento de SST: lo que debes saber" autoFocus />
        </FormField>
        <FormField label="Texto de vista previa (opcional)" error={errors.preheader?.[0]} hint="Lo que se ve junto al asunto en la bandeja de entrada.">
          <input className="input" maxLength={150} value={f.preheader} onChange={(e) => setF({ ...f, preheader: e.target.value })} />
        </FormField>
        <div>
          <p className="text-sm font-semibold">¿A quién va?</p>
          <div className="mt-2 flex flex-wrap gap-2">
            {Object.entries(topics).map(([k, l]) => (
              <button
                key={k}
                type="button"
                onClick={() => toggle(k)}
                aria-pressed={f.topics.includes(k)}
                className={`rounded-full border px-3 py-1.5 text-sm font-semibold ${f.topics.includes(k) ? "border-brand bg-brand-soft text-brand-strong" : "border-line text-muted"}`}
              >
                {f.topics.includes(k) && "✓ "}
                {l}
              </button>
            ))}
          </div>
          <p className="mt-1 text-xs text-muted">{errors.topics?.[0] ? <span className="font-medium text-bad">● {errors.topics[0]}</span> : "Llega a los suscriptores que eligieron al menos uno de estos temas."}</p>
        </div>
        <div>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm font-semibold">Contenido</p>
            <div className="inline-flex rounded-lg border border-line p-0.5 text-xs font-semibold">
              {(["write", "preview"] as const).map((t) => (
                <button key={t} type="button" onClick={() => setTab(t)} className={`rounded-md px-3 py-1 ${tab === t ? "bg-brand text-white" : "text-muted"}`}>
                  {t === "write" ? "Escribir" : "Vista previa"}
                </button>
              ))}
            </div>
          </div>
          {tab === "write" ? (
            <textarea
              className="input mt-2 min-h-64 font-mono text-[13px] leading-relaxed"
              maxLength={30000}
              value={f.body}
              onChange={(e) => setF({ ...f, body: e.target.value })}
              placeholder={"Resumen en dos líneas de lo más importante.\n\n## Qué cambió\n\n- Punto 1\n- Punto 2\n\n## Qué debes hacer\n\nExplica la acción concreta."}
            />
          ) : (
            <div className="mt-2 min-h-64 rounded-xl border border-line bg-white p-4">
              <p className="mb-3 font-display text-lg font-bold">{f.subject || "Asunto"}</p>
              {f.body.trim() ? <HelpBody body={f.body} compact /> : <p className="text-sm text-muted">Escribe algo para ver cómo queda.</p>}
            </div>
          )}
          {errors.body ? (
            <p className="mt-1 text-xs font-medium text-bad">● {errors.body[0]}</p>
          ) : (
            <p className="mt-1 text-xs text-muted">
              Deja una <b>línea vacía</b> entre párrafos. Usa <code className="rounded bg-bg px-1">## </code> para un subtítulo y <code className="rounded bg-bg px-1">- </code> para cada punto. Al final se agrega solo el aviso «información referencial» y el enlace de baja.
            </p>
          )}
        </div>
        <FormField label="Dirección en el sitio (opcional)" error={errors.slug?.[0]} hint="Después de enviarla se puede leer en /boletin/dirección. Si la dejas vacía, se arma con el asunto.">
          <input className="input font-mono text-sm" maxLength={80} value={f.slug} onChange={(e) => setF({ ...f, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, "-") })} />
        </FormField>
      </form>
    </Modal>
  );
}

function SendModal({ issue, tLabel, confirmed, onClose, onDone }: { issue: Issue; tLabel: (t: string) => string; confirmed: Record<string, number>; onClose: () => void; onDone: (t: string) => void }) {
  const [mode, setMode] = useState<"now" | "later">("now");
  const [at, setAt] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function send(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const r = await api<{ data: { recipients_count: number; sent?: number } }>(`/internal/site/newsletter/issues/${issue.id}/send`, {
        method: "POST",
        body: { at: mode === "later" && at ? new Date(at).toISOString() : null },
      });
      onDone(mode === "now" ? `Enviada a ${r.data.sent ?? 0} suscriptor${r.data.sent === 1 ? "" : "es"}.` : `Programada. Saldrá sola a ${r.data.recipients_count} suscriptor${r.data.recipients_count === 1 ? "" : "es"}.`);
    } catch (x) {
      setError(errText(x));
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Enviar edición"
      subtitle={issue.subject}
      icon="play"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="nl-send" icon="check" loading={saving} disabled={mode === "later" && !at}>
            {mode === "now" ? "Enviar ahora" : "Programar"}
          </Button>
        </>
      }
    >
      <form id="nl-send" onSubmit={send} className="space-y-4">
        <p className="rounded-xl bg-bg p-3 text-sm">
          Va a los suscriptores confirmados de: <b>{issue.topics_json.map(tLabel).join(", ")}</b>
          <span className="block text-xs text-muted">Por tema: {issue.topics_json.map((t) => `${tLabel(t)} ${confirmed[t] ?? 0}`).join(" · ")} (quien eligió varios temas recibe un solo correo).</span>
        </p>
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
          {(["now", "later"] as const).map((m) => (
            <label key={m} className={`flex cursor-pointer items-center gap-2 rounded-xl border p-3 text-sm font-semibold ${mode === m ? "border-brand bg-brand-soft text-brand-strong" : "border-line"}`}>
              <input type="radio" className="accent-[var(--color-brand)]" checked={mode === m} onChange={() => setMode(m)} />
              {m === "now" ? "Enviar ahora" : "Programar fecha y hora"}
            </label>
          ))}
        </div>
        {mode === "later" && (
          <FormField label="Fecha y hora (hora de Lima)">
            <input className="input" type="datetime-local" value={at} onChange={(e) => setAt(e.target.value)} />
          </FormField>
        )}
        <p className="text-xs text-muted">Después de enviarla ya no se puede editar. ¿Te enviaste una prueba para revisarla?</p>
        {error && <p className="text-sm font-medium text-bad">● {error}</p>}
      </form>
    </Modal>
  );
}
