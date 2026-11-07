"use client";

// Sitio web › Autodiagnóstico Ley 29783 (SCR-WEB-42): versiones del cuestionario, editor de preguntas del borrador,
// publicar (la anterior se archiva) y los resultados que llegan. La versión publicada no se edita: se crea otra.

import { FormEvent, useCallback, useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Version = { id: string; title: string; version: number; status: "draft" | "published" | "archived"; to_validate: boolean; published_at: string | null; questions: number; submissions: number };
type Q = { section: string; question_text: string; help_text: string | null; weight: number | string; answer_type: "yes_no" | "yes_partial_no"; legal_reference: string | null };
type Detail = { id: string; title: string; description: string | null; to_validate: boolean; level_thresholds_json: string | { low_max: number; medium_max: number }; status: string; version: number; questions: Q[] };
type Data = {
  versions: Version[];
  levels: { level: "low" | "medium" | "high"; n: number; avg: string }[];
  recent: { created_at: string; score_total: string; level: string; full_name: string | null; company_name: string | null; version: number }[];
};

const STATUS = { draft: ["Borrador", "amber"], published: ["Publicado", "ok"], archived: ["Archivado", "na"] } as const;
const LEVEL = { low: ["Bajo", "bad"], medium: ["Medio", "amber"], high: ["Alto", "ok"] } as const;

export default function AutodiagnosticoPanelPage() {
  const me = usePlatformUser();
  const owner = me.platform_role !== "support";
  const [d, setD] = useState<Data | null>(null);
  const [open, setOpen] = useState<string | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: Data }>("/internal/site/self-assessment").then((r) => {
      setD(r.data);
      setOpen((o) => o ?? r.data.versions.find((v) => v.status === "draft")?.id ?? r.data.versions[0]?.id ?? null);
    });
  }, []);
  useEffect(load, [load]);

  if (!d) return <div className="h-64 animate-pulse rounded-2xl bg-line/40" />;
  const published = d.versions.find((v) => v.status === "published");
  const draft = d.versions.find((v) => v.status === "draft");
  const count = (l: string) => d.levels.find((x) => x.level === l)?.n ?? 0;

  async function newVersion() {
    if (!published) return;
    try {
      const r = await api<{ data: { id: string } }>(`/internal/site/self-assessment/${published.id}/copy`, { method: "POST" });
      setOpen(r.data.id);
      setToast({ text: "Versión nueva creada como borrador, con las mismas preguntas.", tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: e instanceof ApiError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : "No se pudo.", tone: "bad" });
    }
  }

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Autodiagnóstico"]]}
        icon="check"
        title="Autodiagnóstico Ley 29783"
        description="El cuestionario que responde la gente en /herramientas/autodiagnostico y sus resultados."
        aside={
          <div className="flex gap-2">
            <a href="/herramientas/autodiagnostico" target="_blank" rel="noopener" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold shadow-sm hover:border-brand">
              <Icon name="globe" size={15} /> Ver en el sitio
            </a>
            {published && !draft && (
              <Button variant="primary" icon="plus" onClick={newVersion}>
                Crear versión nueva
              </Button>
            )}
          </div>
        }
      />

      <section className="grid grid-cols-1 gap-3 rounded-2xl border border-line bg-surface p-4 shadow-sm md:grid-cols-4">
        {(
          [
            ["globe", "Qué es", "Un cuestionario gratis en el sitio: una empresa responde si cumple lo básico de la Ley 29783 (política, comité, IPERC, capacitaciones…)."],
            ["check", "Qué recibe la empresa", "Su porcentaje de cumplimiento, su nivel (bajo, medio o alto) y la lista de lo que le falta. También le llega por correo."],
            ["people", "Qué gana EscudoSST", "Cada persona que responde deja sus datos y aparece en Interesados. Las de nivel bajo son las que más necesitan el sistema."],
            ["pen", "Qué haces tú", "Un especialista SST revisa las preguntas del borrador; luego lo publicas. Después solo miras quién respondió."],
          ] as const
        ).map(([icon, t, txt]) => (
          <div key={t} className="flex gap-3">
            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong">
              <Icon name={icon} size={17} />
            </span>
            <span>
              <b className="block text-sm">{t}</b>
              <span className="text-xs text-muted">{txt}</span>
            </span>
          </div>
        ))}
      </section>

      {!published && (
        <p className="flex items-start gap-2 rounded-2xl border border-amber-ink/30 bg-amber-soft/70 px-4 py-3 text-sm text-amber-ink">
          <Icon name="clock" size={17} className="mt-0.5 shrink-0" />
          <span>
            <b>Aún no hay un cuestionario publicado</b>: el sitio muestra «en preparación». El borrador trae preguntas iniciales a validar por un especialista SST. Revísalas y publícalo.
          </span>
        </p>
      )}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Respuestas" value={d.levels.reduce((a, l) => a + Number(l.n), 0)} icon="people" tone="brand" hint="Están en Interesados" />
        <StatCard label="Nivel bajo" value={count("low")} icon="close" tone="bad" hint="Grandes brechas: buenos prospectos" />
        <StatCard label="Nivel medio" value={count("medium")} icon="clock" tone="amber" />
        <StatCard label="Nivel alto" value={count("high")} icon="check" tone="ok" />
      </div>

      <div className="grid grid-cols-1 items-start gap-5 2xl:grid-cols-[18rem_minmax(0,1fr)_22rem]">
        <section className="rounded-2xl border border-line bg-surface p-2 shadow-sm">
          <p className="px-2.5 pb-1 pt-1 text-[10.5px] font-bold uppercase tracking-[0.12em] text-muted">Versiones</p>
          {d.versions.map((v) => (
            <button key={v.id} type="button" onClick={() => setOpen(v.id)} className={`flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm ${open === v.id ? "bg-brand-soft" : "hover:bg-bg"}`}>
              <b className="w-10 shrink-0">v{v.version}</b>
              <span className="min-w-0 flex-1">
                <Chip tone={STATUS[v.status][1]} dot>
                  {STATUS[v.status][0]}
                </Chip>
                <span className="mt-0.5 block text-xs text-muted">
                  {v.questions} preguntas · {v.submissions} respuestas
                </span>
              </span>
            </button>
          ))}
        </section>

        {open && <Editor key={open} id={open} owner={owner} onToast={(t, tone) => setToast({ text: t, tone })} onChanged={load} />}

        <section className="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
          <h2 className="border-b border-line px-5 py-3 font-display font-semibold">Últimas respuestas</h2>
          {d.recent.length === 0 ? (
            <p className="p-5 text-sm text-muted">Todavía nadie lo respondió.</p>
          ) : (
            <ul className="max-h-[40rem] divide-y divide-line overflow-y-auto">
              {d.recent.map((r, i) => (
                <li key={i} className="flex items-center gap-3 px-5 py-2.5 text-sm">
                  <span className="w-12 font-display text-lg tabular-nums">{Math.round(Number(r.score_total))}%</span>
                  <span className="min-w-0 flex-1">
                    <b className="block truncate">{r.company_name ?? "—"}</b>
                    <span className="block truncate text-xs text-muted">
                      {r.full_name} · v{r.version} · {new Date(r.created_at).toLocaleDateString("es-PE")}
                    </span>
                  </span>
                  <Chip tone={LEVEL[r.level as keyof typeof LEVEL][1]}>{LEVEL[r.level as keyof typeof LEVEL][0]}</Chip>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function Editor({ id, owner, onToast, onChanged }: { id: string; owner: boolean; onToast: (t: string, tone: "ok" | "bad") => void; onChanged: () => void }) {
  const [v, setV] = useState<Detail | null>(null);
  const [qs, setQs] = useState<Q[]>([]);
  const [meta, setMeta] = useState({ title: "", description: "", to_validate: true, low_max: "40", medium_max: "70" });
  const [dirty, setDirty] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: Detail }>(`/internal/site/self-assessment/${id}`).then((r) => {
      const t = typeof r.data.level_thresholds_json === "string" ? JSON.parse(r.data.level_thresholds_json) : r.data.level_thresholds_json;
      setV(r.data);
      setQs(r.data.questions);
      setMeta({ title: r.data.title, description: r.data.description ?? "", to_validate: r.data.to_validate, low_max: String(t.low_max), medium_max: String(t.medium_max) });
    });
  }, [id]);

  if (!v) return <div className="h-64 animate-pulse rounded-2xl bg-line/40" />;
  const editable = v.status === "draft";
  const change = (next: Q[]) => (setQs(next), setDirty(true));
  const setQ = (i: number, p: Partial<Q>) => change(qs.map((q, j) => (j === i ? { ...q, ...p } : q)));
  const move = (i: number, dir: -1 | 1) => {
    const n = [...qs];
    [n[i], n[i + dir]] = [n[i + dir], n[i]];
    change(n);
  };

  async function save(e?: FormEvent) {
    e?.preventDefault();
    setBusy("save");
    setErrors({});
    try {
      await api(`/internal/site/self-assessment/${id}`, { method: "PATCH", body: { ...meta, low_max: Number(meta.low_max), medium_max: Number(meta.medium_max) } });
      await api(`/internal/site/self-assessment/${id}/questions`, { method: "PUT", body: { questions: qs } });
      setDirty(false);
      onToast("Borrador guardado.", "ok");
      onChanged();
      return true;
    } catch (x) {
      if (x instanceof ApiError) setErrors(x.errors ?? {});
      onToast("Revisa los campos marcados.", "bad");
      return false;
    } finally {
      setBusy(null);
    }
  }

  async function publish() {
    if (!window.confirm("¿Publicar esta versión? Reemplaza a la publicada y ya no se podrá editar.")) return;
    if (dirty && !(await save())) return;
    setBusy("publish");
    try {
      await api(`/internal/site/self-assessment/${id}/publish`, { method: "POST" });
      onToast("Publicado: ya se ve en el sitio.", "ok");
      onChanged();
      setV({ ...v!, status: "published" });
    } catch (x) {
      onToast(x instanceof ApiError ? (x.detail ?? x.message) : "No se pudo publicar.", "bad");
    } finally {
      setBusy(null);
    }
  }

  const qErr = (i: number) => Object.entries(errors).find(([k]) => k.startsWith(`questions.${i}.`))?.[1]?.[0];

  return (
    <form onSubmit={save} className="space-y-4" noValidate>
      <section className="space-y-3 rounded-2xl border border-line bg-surface p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="font-display text-lg font-semibold">
            Versión {v.version} <Chip tone={STATUS[v.status as keyof typeof STATUS][1]}>{STATUS[v.status as keyof typeof STATUS][0]}</Chip>
          </h2>
          {editable && (
            <span className="flex gap-2">
              <Button type="submit" icon="check" loading={busy === "save"} disabled={!dirty}>
                Guardar borrador
              </Button>
              {owner && (
                <Button type="button" variant="primary" icon="globe" loading={busy === "publish"} onClick={publish}>
                  Publicar
                </Button>
              )}
            </span>
          )}
        </div>
        {!editable && <p className="rounded-lg bg-bg px-3 py-2 text-xs text-muted">Esta versión ya no se edita. Para cambiar preguntas, usa «Crear versión nueva».</p>}
        <FormField label="Título" error={errors.title?.[0]}>
          <input className="input" disabled={!editable} maxLength={140} value={meta.title} onChange={(e) => (setMeta({ ...meta, title: e.target.value }), setDirty(true))} />
        </FormField>
        <FormField label="Introducción (opcional)">
          <textarea className="input min-h-16" disabled={!editable} maxLength={800} value={meta.description} onChange={(e) => (setMeta({ ...meta, description: e.target.value }), setDirty(true))} />
        </FormField>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <FormField label="Nivel bajo hasta (%)" error={errors.low_max?.[0]}>
            <input className="input" disabled={!editable} inputMode="numeric" value={meta.low_max} onChange={(e) => (setMeta({ ...meta, low_max: e.target.value.replace(/\D/g, "") }), setDirty(true))} />
          </FormField>
          <FormField label="Nivel medio hasta (%)" error={errors.medium_max?.[0]} hint="Más de eso es nivel alto.">
            <input className="input" disabled={!editable} inputMode="numeric" value={meta.medium_max} onChange={(e) => (setMeta({ ...meta, medium_max: e.target.value.replace(/\D/g, "") }), setDirty(true))} />
          </FormField>
        </div>
        <SwitchRow label="A validar por especialista SST" hint="Mientras esté activo, el sitio muestra «Cuestionario en validación»." checked={meta.to_validate} onChange={(x) => editable && (setMeta({ ...meta, to_validate: x }), setDirty(true))} />
      </section>

      <section className="space-y-2">
        <h3 className="font-display font-semibold">Preguntas ({qs.length})</h3>
        <p className="text-xs text-muted">El puntaje de cada sección es la suma de los pesos de las respuestas «Sí» (y la mitad en «En parte») sobre el total de la sección. Pregunta por la gestión de la empresa, nunca por personas ni datos de salud.</p>
        {qs.map((q, i) => (
          <div key={i} className="space-y-2 rounded-2xl border border-line bg-surface p-4 shadow-sm">
            <div className="flex flex-wrap items-center gap-2">
              <span className="grid h-7 w-7 place-items-center rounded-lg bg-brand text-xs font-bold text-white">{i + 1}</span>
              <input className="input w-56 text-sm" disabled={!editable} maxLength={80} placeholder="Sección" value={q.section} onChange={(e) => setQ(i, { section: e.target.value })} />
              <select className="input w-48 text-sm" disabled={!editable} value={q.answer_type} onChange={(e) => setQ(i, { answer_type: e.target.value as Q["answer_type"] })}>
                <option value="yes_partial_no">Sí / En parte / No</option>
                <option value="yes_no">Sí / No</option>
              </select>
              <label className="flex items-center gap-1 text-xs text-muted">
                Peso
                <input className="input w-20 text-sm" disabled={!editable} inputMode="decimal" value={q.weight} onChange={(e) => setQ(i, { weight: e.target.value.replace(/[^\d.]/g, "") })} />
              </label>
              {editable && (
                <span className="ml-auto flex gap-1">
                  <button type="button" disabled={i === 0} onClick={() => move(i, -1)} className="rounded-md px-2 py-1 text-xs text-muted hover:bg-bg disabled:opacity-30" aria-label="Subir">
                    ↑
                  </button>
                  <button type="button" disabled={i === qs.length - 1} onClick={() => move(i, 1)} className="rounded-md px-2 py-1 text-xs text-muted hover:bg-bg disabled:opacity-30" aria-label="Bajar">
                    ↓
                  </button>
                  <button type="button" onClick={() => change(qs.filter((_, j) => j !== i))} className="rounded-md px-2 py-1 text-xs font-semibold text-bad hover:bg-bad-soft">
                    Quitar
                  </button>
                </span>
              )}
            </div>
            <textarea className="input min-h-14 text-sm" disabled={!editable} maxLength={300} placeholder="Pregunta" value={q.question_text} onChange={(e) => setQ(i, { question_text: e.target.value })} />
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <input className="input text-sm" disabled={!editable} maxLength={500} placeholder="Ayuda (opcional)" value={q.help_text ?? ""} onChange={(e) => setQ(i, { help_text: e.target.value })} />
              <input className="input text-sm" disabled={!editable} maxLength={200} placeholder="Referencia legal (opcional)" value={q.legal_reference ?? ""} onChange={(e) => setQ(i, { legal_reference: e.target.value })} />
            </div>
            {qErr(i) && <p className="text-xs font-medium text-bad">● {qErr(i)}</p>}
          </div>
        ))}
        {editable && (
          <Button variant="soft" icon="plus" onClick={() => change([...qs, { section: qs[qs.length - 1]?.section ?? "", question_text: "", help_text: null, weight: 1, answer_type: "yes_partial_no", legal_reference: null }])}>
            Agregar pregunta
          </Button>
        )}
      </section>
    </form>
  );
}
