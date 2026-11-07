"use client";

// Sitio web › Chatbot (SCR-WEB-60 a 62): respuestas guiadas (palabras que la activan, texto y botón), ajustes
// (saludo, horario, WhatsApp, cuándo pedir datos), conversaciones y preguntas que quedaron sin respuesta.
// El modo con IA queda apagado mientras no se elija un proveedor.

import { useSearchParams } from "next/navigation";
import { FormEvent, Suspense, useCallback, useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Intent = { id: string; name: string; trigger_phrases_json: string[]; answer: string; cta_type: string; cta_url: string | null; sort_order: number; status: "draft" | "published" | "archived"; uses: number };
type Settings = { enabled: boolean; greeting: string; offline_message: string | null; handoff_whatsapp: boolean; ask_lead_after_messages: number; avatar_media_id: string | null; business_hours_json: Record<string, [string, string][] | string>; ai_ready: boolean };
type Conv = { id: string; started_at: string; path: string | null; message_count: number; handoff: string; rating: number | null; lead_id: string | null; lead_name: string | null; lead_email: string | null };
type Data = { settings: Settings; intents: Intent[]; conversations: Conv[]; unanswered: { body: string; n: number; last_at: string }[]; stats: { total: number; leads: number; whatsapp: number; rating: string | null } };

const STATUS = { draft: ["Borrador", "amber"], published: ["Publicada", "ok"], archived: ["Archivada", "na"] } as const;
const DAYS = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"];
const when = (d: string) => new Date(d).toLocaleString("es-PE", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: "America/Lima" });
const errText = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.message) : "No se pudo.");

export default function ChatbotPage() {
  return (
    <Suspense>
      <ChatbotPanel />
    </Suspense>
  );
}

function ChatbotPanel() {
  const me = usePlatformUser();
  const owner = me.platform_role !== "support";
  const params = useSearchParams();
  const [data, setData] = useState<Data | null>(null);
  const [ctas, setCtas] = useState<Record<string, string>>({});
  const [tab, setTab] = useState<"intents" | "learning" | "conversations" | "settings">(params.get("c") ? "conversations" : "intents");
  const [teach, setTeach] = useState<string | null>(null);
  const [edit, setEdit] = useState<Intent | "new" | { prefill: string } | null>(null);
  const [view, setView] = useState<string | null>(params.get("c"));
  const [phrase, setPhrase] = useState("");
  const [testHit, setTestHit] = useState<{ name: string; answer: string; status: string } | null | "none">(null);
  const [testAi, setTestAi] = useState<{ intent: string; intent_label: string; term: string; kind: string; reply: string } | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: Data; ctas: Record<string, string> }>("/internal/site/chatbot").then((r) => {
      setData(r.data);
      setCtas(r.ctas);
    });
  }, []);
  useEffect(load, [load]);

  async function act(i: Intent, action: "publish" | "archive" | "delete") {
    if (action === "delete" && !window.confirm(`¿Eliminar «${i.name}»?`)) return;
    try {
      await api(`/internal/site/chatbot/intents/${i.id}/${action}`, { method: "POST" });
      setToast({ text: action === "publish" ? "Publicada: el chatbot ya la usa." : action === "archive" ? "Archivada: el chatbot deja de usarla." : "Eliminada.", tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: errText(e), tone: "bad" });
    }
  }

  async function test(e: FormEvent) {
    e.preventDefault();
    const r = await api<{ data: { name: string; answer: string; status: string } | null; assistant: { intent: string; intent_label: string; term: string; kind: string; reply: string } }>("/internal/site/chatbot/intents/test", { method: "POST", body: { phrase } });
    setTestHit(r.data ?? "none");
    setTestAi(r.assistant);
  }

  const s = data?.stats;
  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Chatbot"]]}
        icon="help"
        title="Chatbot del sitio"
        description="Un asistente en el sitio que responde con lo que ya publicaste (planes, horarios, ayuda, recursos, preguntas frecuentes) y con las respuestas que tú escribes. Aprende sinónimos con tu aprobación. Sin inteligencia artificial externa."
        aside={
          data && (
            <Chip tone={data.settings.enabled ? "ok" : "na"} dot>
              {data.settings.enabled ? "Activo en el sitio" : "Apagado"}
            </Chip>
          )
        }
      />

      <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Conversaciones" value={s ? s.total : "–"} icon="help" tone="brand" hint="Últimos 30 días" />
        <StatCard label="Dejaron datos" value={s ? s.leads : "–"} icon="people" tone="ok" hint="Entran a Interesados" />
        <StatCard label="Pasaron a WhatsApp" value={s ? s.whatsapp : "–"} icon="mail" tone="na" hint="Derivados al equipo" />
        <StatCard label="Sin respuesta" value={data ? data.unanswered.length : "–"} icon="bell" tone="amber" hint="Preguntas para nuevas respuestas" onClick={() => setTab("conversations")} />
      </div>

      <div className="flex flex-wrap gap-1 rounded-xl border border-line bg-surface p-1 text-sm font-semibold sm:w-fit">
        {(
          [
            ["intents", "Respuestas"],
            ["learning", "Aprendizaje"],
            ["conversations", "Conversaciones"],
            ["settings", "Ajustes"],
          ] as const
        ).map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)} className={`flex-1 rounded-lg px-4 py-2 sm:flex-none ${tab === k ? "bg-brand text-white" : "text-muted hover:text-fg"}`}>
            {l}
          </button>
        ))}
      </div>

      {!data ? (
        <div className="h-64 animate-pulse rounded-2xl bg-line/40" />
      ) : tab === "intents" ? (
        <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
          <section className="min-w-0 space-y-3">
            <Button variant="primary" icon="plus" onClick={() => setEdit("new")}>
              Nueva respuesta
            </Button>
            <ul className="grid grid-cols-1 gap-3 lg:grid-cols-2">
              {data.intents.map((i) => (
                <li key={i.id} className="flex min-w-0 flex-col rounded-2xl border border-line bg-surface shadow-sm">
                  <div className="p-4">
                    <span className="flex flex-wrap gap-1.5">
                      <Chip tone={STATUS[i.status][1]} dot>
                        {STATUS[i.status][0]}
                      </Chip>
                      {i.cta_type !== "none" && <Chip tone="brand">Botón: {ctas[i.cta_type]}</Chip>}
                      {i.uses > 0 && <Chip tone="na">{i.uses} usos</Chip>}
                    </span>
                    <b className="mt-1.5 block font-display text-[15px]">{i.name}</b>
                    <p className="mt-1 line-clamp-2 text-sm text-muted">{i.answer}</p>
                    <p className="mt-2 flex flex-wrap gap-1">
                      {i.trigger_phrases_json.map((p) => (
                        <span key={p} className="rounded-md bg-bg px-1.5 py-0.5 text-xs">
                          {p}
                        </span>
                      ))}
                    </p>
                  </div>
                  <div className="mt-auto flex flex-wrap gap-1 border-t border-line bg-bg/50 px-3 py-2">
                    <Button size="sm" variant="ghost" icon="pen" onClick={() => setEdit(i)}>
                      Editar
                    </Button>
                    {owner && i.status !== "published" && (
                      <Button size="sm" variant="ghost" icon="globe" onClick={() => act(i, "publish")}>
                        Publicar
                      </Button>
                    )}
                    {owner && i.status === "published" && (
                      <Button size="sm" variant="ghost" onClick={() => act(i, "archive")}>
                        Archivar
                      </Button>
                    )}
                    {owner && i.status !== "published" && (
                      <Button size="sm" variant="ghost" onClick={() => act(i, "delete")}>
                        Eliminar
                      </Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          </section>
          <section className="rounded-2xl border border-line bg-surface p-4 shadow-sm xl:self-start">
            <p className="font-semibold">Probar una pregunta</p>
            <p className="text-xs text-muted">Escribe lo que preguntaría un visitante y mira qué respuesta daría.</p>
            <form onSubmit={test} className="mt-3 flex gap-2">
              <input className="input" value={phrase} onChange={(e) => setPhrase(e.target.value)} placeholder="¿Cuánto cuesta?" maxLength={500} />
              <Button type="submit" variant="soft" disabled={!phrase.trim()}>
                Probar
              </Button>
            </form>
            {testAi && (
              <div className="mt-3 rounded-xl border border-brand/20 bg-brand-soft/40 p-3 text-sm">
                <p className="text-xs text-muted">
                  Entendió: <b className="text-fg">{testAi.intent_label}</b>
                  {testAi.term && (
                    <>
                      {" "}· palabras clave «{testAi.term}»
                    </>
                  )}
                </p>
                <p className="mt-1">{testAi.reply}</p>
                {testAi.kind === "fallback" && <p className="mt-1 text-xs font-semibold text-amber-ink">No encontró nada publicado: enséñale en «Aprendizaje» o crea una respuesta.</p>}
              </div>
            )}
            {testHit === "none" && !testAi && <p className="mt-3 rounded-xl bg-amber-soft p-3 text-sm text-amber-ink">Ninguna respuesta coincide.</p>}
            {testHit && testHit !== "none" && testHit.status === "draft" && (
              <div className="mt-3 rounded-xl bg-bg p-3 text-sm">
                <b>{testHit.name}</b> <Chip tone="amber">borrador: aún no se usa</Chip>
                <p className="mt-1 text-muted">{testHit.answer}</p>
              </div>
            )}
            <p className="mt-4 text-xs text-muted">Cómo responde: 1) lo que aprendió, 2) tus respuestas publicadas si el mensaje contiene sus palabras, 3) el tema (precios, demo, multa, ayuda…) con datos reales del sitio, 4) una búsqueda en todo lo publicado.</p>
          </section>
        </div>
      ) : tab === "learning" ? (
        <LearningTab owner={owner} teach={teach} setTeach={setTeach} onToast={(t, ok) => setToast({ text: t, tone: ok ? "ok" : "bad" })} />
      ) : tab === "conversations" ? (
        <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
          <section className="min-w-0 rounded-2xl border border-line bg-surface shadow-sm">
            <p className="border-b border-line px-4 py-3 text-sm font-semibold">Conversaciones recientes</p>
            {data.conversations.length === 0 ? (
              <p className="p-6 text-sm text-muted">Todavía no hay conversaciones.</p>
            ) : (
              <ul className="divide-y divide-line">
                {data.conversations.map((c) => (
                  <li key={c.id}>
                    <button onClick={() => setView(c.id)} className="flex w-full flex-wrap items-center gap-2 px-4 py-3 text-left hover:bg-bg">
                      <span className="min-w-0 flex-1">
                        <b className="block truncate text-sm">{c.lead_name ?? "Visitante anónimo"}</b>
                        <span className="text-xs text-muted">
                          {when(c.started_at)} · {c.path ?? "/"} · {c.message_count} mensajes
                        </span>
                      </span>
                      {c.lead_id && <Chip tone="ok">Dejó datos</Chip>}
                      {c.handoff === "whatsapp" && <Chip tone="brand">WhatsApp</Chip>}
                      {c.rating && <Chip tone="amber">★ {c.rating}</Chip>}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>
          <section className="rounded-2xl border border-line bg-surface shadow-sm xl:self-start">
            <p className="border-b border-line px-4 py-3 text-sm font-semibold">Preguntas sin respuesta</p>
            {data.unanswered.length === 0 ? (
              <p className="p-4 text-sm text-muted">Nada pendiente.</p>
            ) : (
              <ul className="divide-y divide-line">
                {data.unanswered.map((u) => (
                  <li key={u.body} className="flex items-center gap-2 px-4 py-2.5">
                    <span className="min-w-0 flex-1 text-sm">
                      «{u.body}» <span className="text-xs text-muted">· {u.n} {u.n === 1 ? "vez" : "veces"}</span>
                    </span>
                    {owner && (
                      <Button
                        size="sm"
                        variant="ghost"
                        icon="star"
                        onClick={() => {
                          setTeach(u.body);
                          setTab("learning");
                        }}
                      >
                        Enseñar
                      </Button>
                    )}
                    <Button size="sm" variant="ghost" icon="plus" onClick={() => setEdit({ prefill: u.body })}>
                      Responder
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      ) : (
        <SettingsForm s={data.settings} owner={owner} onDone={(t) => (setToast({ text: t, tone: "ok" }), load())} />
      )}

      {edit && (
        <IntentModal
          row={edit === "new" || "prefill" in edit ? null : edit}
          prefill={typeof edit === "object" && "prefill" in edit ? edit.prefill : ""}
          ctas={ctas}
          onClose={() => setEdit(null)}
          onDone={() => {
            setEdit(null);
            setToast({ text: owner ? "Guardada. Publícala para que el chatbot la use." : "Guardada como borrador. El Dueño la publica.", tone: "ok" });
            load();
          }}
        />
      )}
      {view && <ConversationModal id={view} onClose={() => setView(null)} />}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

type Alias = { id: string; term: string; target_type: string; target_key: string; target_title: string; status: "suggested" | "active" | "disabled"; source: "visitor" | "owner"; uses: number; votes: number };
const AL_ST = { suggested: ["Sugerencia", "amber"], active: ["Activo", "ok"], disabled: ["Desactivado", "na"] } as const;
const TT: Record<string, string> = { intent: "Tema", faq: "Pregunta frecuente", help_article: "Centro de ayuda", resource: "Recurso", story: "Caso de éxito", page: "Página" };

function LearningTab({ owner, teach, setTeach, onToast }: { owner: boolean; teach: string | null; setTeach: (t: string | null) => void; onToast: (t: string, ok: boolean) => void }) {
  const [rows, setRows] = useState<Alias[] | null>(null);
  const [intents, setIntents] = useState<Record<string, string>>({});
  const [term, setTerm] = useState(teach ?? "");
  const [q, setQ] = useState("");
  const [targets, setTargets] = useState<{ type: string; key: string; title: string; label: string }[]>([]);
  const [target, setTarget] = useState<{ type: string; key: string; title: string } | null>(null);
  const load = useCallback(() => {
    api<{ data: Alias[]; intents: Record<string, string> }>("/internal/site/chatbot/aliases").then((r) => {
      setRows(r.data);
      setIntents(r.intents);
    });
  }, []);
  useEffect(load, [load]);
  useEffect(() => {
    if (teach) setTerm(teach);
  }, [teach]);
  useEffect(() => {
    const t = setTimeout(() => {
      api<{ data: typeof targets }>(`/internal/site/chatbot/targets?q=${encodeURIComponent(q)}`).then((r) => setTargets(r.data));
    }, 250);
    return () => clearTimeout(t);
  }, [q]);

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!target) return;
    try {
      await api("/internal/site/chatbot/aliases", { method: "POST", body: { term, target_type: target.type, target_key: target.key } });
      onToast(`Aprendido: «${term}» → ${target.title}.`, true);
      setTerm("");
      setTarget(null);
      setTeach(null);
      load();
    } catch (x) {
      onToast(errText(x), false);
    }
  }

  async function setStatus(a: Alias, status: "active" | "disabled") {
    try {
      await api(`/internal/site/chatbot/aliases/${a.id}`, { method: "PATCH", body: { status } });
      onToast(status === "active" ? "Aprobado: el asistente ya lo usa." : "Desactivado.", true);
      load();
    } catch (x) {
      onToast(errText(x), false);
    }
  }

  const pending = (rows ?? []).filter((r) => r.status === "suggested");
  return (
    <div className="grid grid-cols-1 gap-5 xl:grid-cols-[400px_minmax(0,1fr)]">
      <section className="space-y-3 rounded-2xl border border-line bg-surface p-4 shadow-sm xl:self-start">
        <p className="font-semibold">Enseñar una frase</p>
        <p className="text-xs text-muted">Cuando alguien pregunte esto, el asistente responderá con lo que elijas. Solo puedes elegir contenido publicado.</p>
        {owner ? (
          <form onSubmit={save} className="space-y-3">
            <FormField label="Lo que pregunta la gente">
              <input className="input" maxLength={200} value={term} onChange={(e) => setTerm(e.target.value)} placeholder="sacar reportes en hoja de cálculo" />
            </FormField>
            <div>
              <p className="mb-1 text-sm font-semibold">Debe responder con</p>
              {target ? (
                <div className="flex items-center gap-2 rounded-xl bg-brand-soft p-2 text-sm">
                  <span className="min-w-0 flex-1 truncate">
                    <b>{target.title}</b>
                  </span>
                  <button type="button" onClick={() => setTarget(null)} className="text-xs font-semibold text-brand">
                    Cambiar
                  </button>
                </div>
              ) : (
                <>
                  <select className="input mb-2" value="" onChange={(e) => e.target.value && setTarget({ type: "intent", key: e.target.value, title: intents[e.target.value] })}>
                    <option value="">Un tema (precios, demo, multa…)</option>
                    {Object.entries(intents).map(([k, l]) => (
                      <option key={k} value={k}>
                        {l}
                      </option>
                    ))}
                  </select>
                  <input className="input" value={q} onChange={(e) => setQ(e.target.value)} placeholder="…o busca una pregunta frecuente, guía, recurso o página" />
                  <ul className="mt-2 max-h-56 space-y-1 overflow-y-auto">
                    {targets.map((t) => (
                      <li key={`${t.type}-${t.key}`}>
                        <button type="button" onClick={() => setTarget(t)} className="w-full rounded-lg px-2 py-1.5 text-left text-sm hover:bg-bg">
                          <span className="text-[11px] text-muted">{t.label}</span>
                          <span className="block truncate">{t.title}</span>
                        </button>
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </div>
            <Button type="submit" variant="primary" icon="check" disabled={!term.trim() || !target}>
              Enseñar
            </Button>
          </form>
        ) : (
          <p className="text-sm text-muted">Solo el Dueño enseña y aprueba.</p>
        )}
      </section>

      <section className="min-w-0 space-y-4">
        <div className="rounded-2xl border border-brand/15 bg-gradient-to-r from-brand-soft/70 to-surface p-4 text-sm">
          <p className="font-semibold text-brand-strong">Cómo aprende</p>
          <p className="mt-1 text-muted">
            Cuando el asistente no entiende y ofrece opciones, lo que el visitante elige queda como <b className="text-fg">sugerencia</b>. Se activa sola si 3 conversaciones distintas eligen lo mismo, o al instante si tú la apruebas. Lo que enseñas tú se activa al momento.
          </p>
        </div>
        {rows === null ? (
          <div className="h-40 animate-pulse rounded-2xl bg-line/40" />
        ) : rows.length === 0 ? (
          <p className="rounded-2xl border border-dashed border-line bg-surface p-8 text-center text-sm text-muted">Aún no aprendió nada. Enseña una frase o espera a que los visitantes elijan opciones.</p>
        ) : (
          <div className="rounded-2xl border border-line bg-surface shadow-sm">
            <p className="border-b border-line px-4 py-3 text-sm font-semibold">
              Sinónimos ({rows.length}){pending.length > 0 && <span className="ml-2 text-amber-ink">· {pending.length} por revisar</span>}
            </p>
            <ul className="divide-y divide-line">
              {rows.map((a) => (
                <li key={a.id} className="flex flex-wrap items-center gap-2 px-4 py-3">
                  <span className="min-w-0 flex-1">
                    <b className="block text-sm">«{a.term}»</b>
                    <span className="text-xs text-muted">
                      → {TT[a.target_type] ?? a.target_type}: {a.target_title} · {a.source === "owner" ? "enseñado por el equipo" : `${a.votes} conversación${a.votes === 1 ? "" : "es"}`} · {a.uses} usos
                    </span>
                  </span>
                  <Chip tone={AL_ST[a.status][1]} dot>
                    {AL_ST[a.status][0]}
                  </Chip>
                  {owner && a.status !== "active" && (
                    <Button size="sm" variant="ghost" icon="check" onClick={() => setStatus(a, "active")}>
                      Aprobar
                    </Button>
                  )}
                  {owner && a.status !== "disabled" && (
                    <Button size="sm" variant="ghost" onClick={() => setStatus(a, "disabled")}>
                      Desactivar
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}
      </section>
    </div>
  );
}

function IntentModal({ row, prefill, ctas, onClose, onDone }: { row: Intent | null; prefill: string; ctas: Record<string, string>; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({ name: row?.name ?? "", phrases: (row?.trigger_phrases_json ?? (prefill ? [prefill.toLowerCase().slice(0, 60)] : [])).join(", "), answer: row?.answer ?? "", cta_type: row?.cta_type ?? "none", cta_url: row?.cta_url ?? "", sort_order: row?.sort_order ?? 0 });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api(row ? `/internal/site/chatbot/intents/${row.id}` : "/internal/site/chatbot/intents", {
        method: row ? "PATCH" : "POST",
        body: { ...f, trigger_phrases: f.phrases.split(",").map((p) => p.trim()).filter(Boolean), cta_url: f.cta_type === "link" ? f.cta_url : null },
      });
      onDone();
    } catch (x) {
      if (x instanceof ApiError) setErrors(x.errors ?? { name: [x.detail ?? x.message] });
      setSaving(false);
    }
  }

  return (
    <Modal
      title={row ? "Editar respuesta" : "Nueva respuesta"}
      icon="help"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="intent-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="intent-form" onSubmit={save} className="space-y-4" noValidate>
        <FormField label="Nombre (se ve como botón al abrir el chat)" error={errors.name?.[0]}>
          <input className="input" maxLength={80} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="Precios y planes" autoFocus />
        </FormField>
        <FormField label="Palabras que la activan" error={errors.trigger_phrases?.[0] ?? Object.entries(errors).find(([k]) => k.startsWith("trigger_phrases."))?.[1]?.[0]} hint="Separadas por comas. Si el mensaje contiene alguna, se responde con esta. Ej.: precio, costo, cuánto cuesta">
          <input className="input" value={f.phrases} onChange={(e) => setF({ ...f, phrases: e.target.value })} />
        </FormField>
        <FormField label="Respuesta" error={errors.answer?.[0]} counter={`${f.answer.length}/1000`} hint="Clara y sin prometer precios, plazos ni resultados que no estén definidos.">
          <textarea className="input min-h-24" maxLength={1000} value={f.answer} onChange={(e) => setF({ ...f, answer: e.target.value })} />
        </FormField>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Botón debajo de la respuesta" error={errors.cta_type?.[0]}>
            <select className="input" value={f.cta_type} onChange={(e) => setF({ ...f, cta_type: e.target.value })}>
              {Object.entries(ctas).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
          {f.cta_type === "link" && (
            <FormField label="Enlace" error={errors.cta_url?.[0]} hint="/planes o https://…">
              <input className="input" maxLength={300} value={f.cta_url} onChange={(e) => setF({ ...f, cta_url: e.target.value })} />
            </FormField>
          )}
        </div>
      </form>
    </Modal>
  );
}

function SettingsForm({ s, owner, onDone }: { s: Settings; owner: boolean; onDone: (t: string) => void }) {
  const hours0 = Object.fromEntries([1, 2, 3, 4, 5, 6, 7].map((d) => [d, (s.business_hours_json?.[String(d)] as [string, string][] | undefined)?.[0] ?? null]));
  const [f, setF] = useState({ enabled: s.enabled, greeting: s.greeting, offline_message: s.offline_message ?? "", handoff_whatsapp: s.handoff_whatsapp, ask_lead_after_messages: s.ask_lead_after_messages });
  const [hours, setHours] = useState<Record<number, [string, string] | null>>(hours0);
  const [allDay, setAllDay] = useState(!s.business_hours_json || Object.keys(s.business_hours_json).filter((k) => k !== "timezone").length === 0);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    const bh = allDay ? null : Object.fromEntries(Object.entries(hours).filter(([, v]) => v).map(([k, v]) => [k, [v]]));
    try {
      await api("/internal/site/chatbot/settings", { method: "PATCH", body: { ...f, offline_message: f.offline_message || null, business_hours: bh } });
      onDone("Ajustes del chatbot guardados.");
    } catch (x) {
      setError(errText(x));
    }
    setSaving(false);
  }

  return (
    <form onSubmit={save} className="max-w-3xl space-y-4 rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-6">
      <SwitchRow label="Chatbot activo en el sitio" hint="Aparece el botón azul en todas las páginas públicas." checked={f.enabled} onChange={(v) => owner && setF({ ...f, enabled: v })} />
      <p className="rounded-xl bg-bg p-3 text-xs text-muted">
        Responde con reglas propias y con lo que ya está publicado en el sitio (sin inteligencia artificial externa ni internet). Si no sabe algo, lo dice y ofrece dejar datos o WhatsApp.
      </p>
      <FormField label="Saludo">
        <textarea className="input min-h-16" disabled={!owner} maxLength={300} value={f.greeting} onChange={(e) => setF({ ...f, greeting: e.target.value })} />
      </FormField>
      <div>
        <SwitchRow label="Atiende las 24 horas" hint="Si lo apagas, define el horario de atención (hora de Lima)." checked={allDay} onChange={(v) => owner && setAllDay(v)} />
        {!allDay && (
          <ul className="mt-3 space-y-2">
            {[1, 2, 3, 4, 5, 6, 7].map((d) => (
              <li key={d} className="flex flex-wrap items-center gap-2 text-sm">
                <label className="flex w-24 items-center gap-2">
                  <input type="checkbox" checked={!!hours[d]} disabled={!owner} onChange={(e) => setHours({ ...hours, [d]: e.target.checked ? ["09:00", "18:00"] : null })} />
                  {DAYS[d - 1]}
                </label>
                {hours[d] && (
                  <>
                    <input className="input h-9 w-28" type="time" disabled={!owner} value={hours[d]![0]} onChange={(e) => setHours({ ...hours, [d]: [e.target.value, hours[d]![1]] })} />
                    <span className="text-muted">a</span>
                    <input className="input h-9 w-28" type="time" disabled={!owner} value={hours[d]![1]} onChange={(e) => setHours({ ...hours, [d]: [hours[d]![0], e.target.value] })} />
                  </>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
      <FormField label="Mensaje fuera de horario">
        <input className="input" disabled={!owner} maxLength={300} value={f.offline_message} onChange={(e) => setF({ ...f, offline_message: e.target.value })} />
      </FormField>
      <SwitchRow label="Ofrecer WhatsApp" hint="Muestra «Prefiero hablar por WhatsApp» (usa el número de Contacto y WhatsApp)." checked={f.handoff_whatsapp} onChange={(v) => owner && setF({ ...f, handoff_whatsapp: v })} />
      <FormField label="Pedir datos después de cuántos mensajes" hint="Entre 1 y 50. También se piden si el visitante lo elige.">
        <input className="input w-28" inputMode="numeric" disabled={!owner} value={String(f.ask_lead_after_messages)} onChange={(e) => setF({ ...f, ask_lead_after_messages: Math.min(50, Number(e.target.value.replace(/\D/g, "")) || 1) })} />
      </FormField>
      {error && <p className="text-sm font-medium text-bad">● {error}</p>}
      {owner && (
        <Button variant="primary" type="submit" icon="check" loading={saving}>
          Guardar ajustes
        </Button>
      )}
    </form>
  );
}

function ConversationModal({ id, onClose }: { id: string; onClose: () => void }) {
  const [d, setD] = useState<{ conversation: Conv & { started_at: string }; messages: { sender: string; body: string; occurred_at: string; intent: string | null; used_ai: boolean }[] } | null>(null);
  useEffect(() => {
    api<{ data: NonNullable<typeof d> }>(`/internal/site/chatbot/conversations/${id}`).then((r) => setD(r.data));
  }, [id]);
  return (
    <Modal title="Conversación" subtitle={d ? `${when(d.conversation.started_at)} · ${d.conversation.path ?? "/"}` : ""} icon="help" onClose={onClose} footer={<Button onClick={onClose}>Cerrar</Button>}>
      {!d ? (
        <div className="h-40 animate-pulse rounded-xl bg-line/40" />
      ) : (
        <div className="space-y-3">
          {d.conversation.lead_name && (
            <p className="flex items-center gap-2 rounded-xl bg-ok-soft p-3 text-sm">
              <Icon name="user" size={16} /> {d.conversation.lead_name} · {d.conversation.lead_email}
            </p>
          )}
          <ul className="space-y-2">
            {d.messages.map((m, i) => (
              <li key={i} className={`flex ${m.sender === "visitor" ? "justify-end" : ""}`}>
                <div className={`max-w-[85%] rounded-2xl px-3 py-2 text-sm ${m.sender === "visitor" ? "bg-brand text-white" : "bg-bg"}`}>
                  {m.body}
                  {m.sender === "bot" && <span className="mt-1 block text-[11px] text-muted">{m.intent ? `Respuesta: ${m.intent}` : m.used_ai ? "Respondido con IA" : "Sin respuesta configurada"}</span>}
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </Modal>
  );
}
