"use client";

// Panel › Asistente IA: el equipo de EscudoSST entrena al asistente de las empresas (sin IA de pago).
// Cada tema tiene palabras clave (cómo lo escribiría una persona) y sus sugerencias. Las preguntas que el asistente
// no supo responder aparecen abajo para enseñarle con un clic.

import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Item = { group: string; title: string; detail: string };
type Topic = { id?: string; feature: string; title: string; keywords: string[]; items: Item[]; status: string; uses?: number; miss_id?: string };
type Miss = { id: string; feature: string; question: string; times: number; last_at: string };
const FEATURES: Record<string, string> = { controls: "Controles para un peligro", causes: "Guía de investigación", talk: "Charla de 5 minutos" };
const empty = (feature = "controls"): Topic => ({ feature, title: "", keywords: [], items: [{ group: "", title: "", detail: "" }], status: "active" });

export default function AsistentePage() {
  const [data, setData] = useState<{ topics: Topic[]; misses: Miss[] } | null>(null);
  const [edit, setEdit] = useState<Topic | null>(null);
  const [filter, setFilter] = useState("controls");
  const [tryText, setTryText] = useState("");
  const [tryRes, setTryRes] = useState<string | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: { topics: Topic[]; misses: Miss[] } }>("/internal/assistant").then((r) => setData(r.data));
  }, []);
  useEffect(load, [load]);

  const tryIt = () =>
    api<{ data: { topic: string | null } }>("/internal/assistant/try", { method: "POST", body: { feature: filter, text: tryText } })
      .then((r) => setTryRes(r.data.topic ? `Respondería con el tema «${r.data.topic}».` : "No encuentra tema: daría la guía general."));

  return (
    <div className="w-full space-y-6">
      <PageHeader crumbs={[["Panel de la plataforma"], ["Asistente IA"]]} icon="sparkle" title="Asistente IA"
        description="Entrena al asistente que usan las empresas: cada tema tiene palabras clave y sus sugerencias. No usa IA de pago." />

      <section className="space-y-3 rounded-2xl border border-line bg-surface p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex flex-wrap gap-2">
            {Object.entries(FEATURES).map(([k, l]) => (
              <button key={k} onClick={() => setFilter(k)} className={`rounded-full px-4 py-2 text-sm font-semibold ${filter === k ? "bg-brand text-white" : "border border-line hover:border-brand"}`}>{l}</button>
            ))}
          </div>
          <Button variant="primary" icon="plus" onClick={() => setEdit(empty(filter))}>Nuevo tema</Button>
        </div>
        <div className="flex flex-wrap items-end gap-2 rounded-xl bg-bg p-3 text-sm">
          <label className="min-w-[16rem] flex-1">Probar una pregunta<input className="input mt-1" value={tryText} onChange={(e) => setTryText(e.target.value)} placeholder="Ej.: soldadura en taller" /></label>
          <Button variant="secondary" onClick={tryIt} disabled={tryText.trim().length < 2}>Probar</Button>
          {tryRes && <p className="w-full text-muted">{tryRes}</p>}
        </div>
        {!data ? <p className="text-sm text-muted">Cargando…</p> : (
          <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {data.topics.filter((t) => t.feature === filter).map((t) => (
              <li key={t.id}>
                <button onClick={() => setEdit(t)} className="w-full space-y-2 rounded-xl border border-line p-4 text-left text-sm hover:border-brand">
                  <div className="flex items-center justify-between gap-2"><b>{t.title}</b>{t.status === "active" ? <Chip tone="ok">Activo</Chip> : <Chip tone="na">Apagado</Chip>}</div>
                  <p className="text-xs text-muted">Palabras: {t.keywords.join(", ")}</p>
                  <p className="text-xs text-muted">{t.items.length} sugerencias · usado {t.uses ?? 0} {t.uses === 1 ? "vez" : "veces"}</p>
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="space-y-3 rounded-2xl border border-line bg-surface p-5 shadow-sm">
        <h2 className="font-display text-lg font-semibold">Preguntas sin respuesta</h2>
        <p className="text-sm text-muted">Lo que preguntaron las empresas y el asistente no supo. Enséñale creando el tema con un clic.</p>
        {data && data.misses.length === 0 && <p className="text-sm text-ok">No hay preguntas pendientes.</p>}
        <ul className="space-y-2">
          {data?.misses.map((m) => (
            <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-bg px-3 py-2 text-sm">
              <span><b>«{m.question}»</b> <span className="text-xs text-muted">· {FEATURES[m.feature]} · {m.times} {m.times === 1 ? "vez" : "veces"}</span></span>
              <span className="flex gap-2">
                <Button size="sm" variant="secondary" onClick={() => setEdit({ ...empty(m.feature), title: m.question.slice(0, 60), keywords: m.question.split(/\s+/).filter((w) => w.length > 3).slice(0, 4), miss_id: m.id })}>Enseñar</Button>
                <Button size="sm" variant="ghost" onClick={() => api(`/internal/assistant/misses/${m.id}`, { method: "POST", body: { status: "ignored" } }).then(load)}>Ignorar</Button>
              </span>
            </li>
          ))}
        </ul>
      </section>

      {edit && <TopicEditor topic={edit} onClose={() => setEdit(null)} onSaved={(t) => { setEdit(null); setToast(t); load(); }} />}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function TopicEditor({ topic, onClose, onSaved }: { topic: Topic; onClose: () => void; onSaved: (msg: string) => void }) {
  const [t, setT] = useState<Topic>(topic);
  const [kw, setKw] = useState(topic.keywords.join(", "));
  const [error, setError] = useState<string | null>(null);
  const setItem = (i: number, patch: Partial<Item>) => setT({ ...t, items: t.items.map((x, k) => (k === i ? { ...x, ...patch } : x)) });
  const save = () => {
    const body = { ...t, keywords: kw.split(",").map((s) => s.trim()).filter(Boolean), items: t.items.filter((i) => i.title.trim()) };
    (t.id ? api(`/internal/assistant/topics/${t.id}`, { method: "PUT", body }) : api("/internal/assistant/topics", { method: "POST", body }))
      .then(() => onSaved(t.id ? "Tema actualizado." : "Tema creado: el asistente ya lo usa."))
      .catch((e: ApiError) => setError(Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title));
  };
  const remove = () => t.id && api(`/internal/assistant/topics/${t.id}`, { method: "DELETE" }).then(() => onSaved("Tema borrado."));
  return (
    <Modal title={t.id ? "Editar tema" : "Nuevo tema"} icon="sparkle" onClose={onClose} size="lg"
      footer={<div className="flex w-full flex-wrap justify-between gap-2">{t.id ? <Button variant="danger-ghost" onClick={remove}>Borrar</Button> : <span />}<span className="flex gap-2"><Button onClick={onClose}>Cancelar</Button><Button variant="primary" onClick={save}>Guardar</Button></span></div>}>
      <div className="space-y-3 text-sm">
        <div className="grid gap-3 sm:grid-cols-2">
          <FormField label="Tipo">
            <select className="input" value={t.feature} onChange={(e) => setT({ ...t, feature: e.target.value })}>
              {Object.entries(FEATURES).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
          </FormField>
          <FormField label="Tema"><input className="input" value={t.title} onChange={(e) => setT({ ...t, title: e.target.value })} placeholder="Ej.: Soldadura" /></FormField>
        </div>
        <FormField label="Palabras clave (separadas por coma)" hint="Cómo lo escribiría una persona: soldar, soldadura, arco eléctrico…"><input className="input" value={kw} onChange={(e) => setKw(e.target.value)} /></FormField>
        <p className="font-semibold">Sugerencias</p>
        {t.items.map((it, i) => (
          <div key={i} className="grid gap-2 rounded-lg bg-bg p-2 sm:grid-cols-[10rem_1fr]">
            <input className="input" placeholder="Grupo (ej.: Ingeniería)" value={it.group} onChange={(e) => setItem(i, { group: e.target.value })} />
            <input className="input" placeholder="Sugerencia" value={it.title} onChange={(e) => setItem(i, { title: e.target.value })} />
            <input className="input sm:col-span-2" placeholder="Detalle (opcional)" value={it.detail} onChange={(e) => setItem(i, { detail: e.target.value })} />
          </div>
        ))}
        <Button size="sm" variant="ghost" icon="plus" onClick={() => setT({ ...t, items: [...t.items, { group: "", title: "", detail: "" }] })}>Agregar sugerencia</Button>
        {t.id && (
          <label className="flex items-center gap-2"><input type="checkbox" checked={t.status === "active"} onChange={(e) => setT({ ...t, status: e.target.checked ? "active" : "disabled" })} /> Activo (el asistente lo usa)</label>
        )}
        {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-bad">{error}</p>}
      </div>
    </Modal>
  );
}
