"use client";

// Sitio web → Políticas (SCR-WEB-24). Cada documento legal se edita por secciones como borrador; se publica con
// el nombre del abogado que lo revisó (o confirmando que se publica sin revisión). Lo publicado no se edita:
// se crea una versión nueva. Mientras no haya versión publicada, el sitio muestra el borrador como «en preparación».

import { useCallback, useEffect, useMemo, useState } from "react";
import { LegalBody } from "@/components/LegalBody";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { formatDate } from "@/lib/accounts";
import { diffLines } from "@/lib/diff";
import { buildLegal, type LegalDoc, parseLegal, TEST_MARK } from "@/lib/legalText";

type Version = {
  id: string;
  version_number: number;
  version_label: string;
  status: "draft" | "approved" | "published" | "superseded";
  content: string;
  summary_of_changes: string | null;
  effective_from: string | null;
  requires_reacceptance: boolean;
  legal_reviewed_by: string | null;
  published_at: string | null;
  has_test_text: boolean;
  acceptances: number;
};
type Doc = { id: string; doc_type: string; title: string; slug: string; requires_acceptance_on_signup: boolean; applies_to: string; versions: Version[] };

const APPLIES: Record<string, string> = { all: "Todos", customers: "Clientes", visitors: "Visitantes del sitio" };
const STATUS: Record<string, { label: string; tone: "ok" | "amber" | "na" | "brand" }> = {
  draft: { label: "Borrador", tone: "amber" },
  approved: { label: "Aprobado", tone: "brand" },
  published: { label: "Publicado", tone: "ok" },
  superseded: { label: "Reemplazado", tone: "na" },
};

export default function PoliticasPage() {
  const [docs, setDocs] = useState<Doc[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [openId, setOpenId] = useState<string | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);
  const closeToast = useCallback(() => setToast(null), []);

  const load = useCallback(() => {
    api<{ data: Doc[] }>("/internal/site/legal")
      .then((r) => setDocs(r.data))
      .catch((e) => setError(e instanceof ApiError ? e.message : "No pudimos cargar las políticas."));
  }, []);
  useEffect(load, [load]);

  if (error) return <p className="rounded-xl border border-bad/30 bg-bad-soft p-4 text-bad">{error}</p>;
  if (!docs) return <div className="h-96 w-full animate-pulse rounded-2xl bg-line/40" aria-label="Cargando" />;

  const open = docs.find((d) => d.id === openId);
  const published = docs.filter((d) => d.versions.some((v) => v.status === "published")).length;
  const withTest = docs.filter((d) => d.versions.some((v) => v.status === "draft" && v.has_test_text)).length;

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Políticas"]]}
        icon="file"
        title="Políticas"
        description="Textos legales del sitio: se editan aquí y se publican cuando el abogado los valide."
      />

      {open ? (
        <Editor
          doc={open}
          onBack={() => setOpenId(null)}
          onChanged={(text, tone = "ok") => {
            setToast({ text, tone });
            load();
          }}
        />
      ) : (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <Stat icon="check" tone="bg-ok-soft text-ok" value={`${published}/${docs.length}`} label="Publicados" />
            <Stat icon="pen" tone="bg-amber-soft text-amber-ink" value={withTest} label="Con texto de prueba" />
            <Stat icon="shield" tone="bg-brand-soft text-brand-strong" value={docs.filter((d) => d.requires_acceptance_on_signup).length} label="Se aceptan al registrarse" />
          </div>
          <section className="rounded-2xl border border-line bg-surface p-5 shadow-sm">
            <h2 className="font-display font-semibold">Cómo funcionan las versiones</h2>
            <ol className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
              {(
                [
                  ["pen", "1. Borrador", "Se edita y guarda las veces que quieras. En el sitio sale como «en preparación»."],
                  ["lock", "2. Publicada", "Rige en el sitio y ya no se edita: así queda prueba del texto exacto que aceptó cada persona."],
                  ["plus", "3. Versión nueva", "Al abrir una política ya publicada, arriba a la derecha aparece «Crear versión nueva» (en lugar de «Guardar borrador»). Copia el texto vigente en un borrador 2.0; la 1.0 queda en el historial y en «Versiones» aparece «Comparar con la 1.0»."],
                ] as const
              ).map(([icon, title, text]) => (
                <li key={title} className="flex items-start gap-3 rounded-xl bg-bg/60 p-3.5">
                  <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong">
                    <Icon name={icon} size={17} />
                  </span>
                  <span>
                    <b className="block text-sm">{title}</b>
                    <span className="text-xs text-muted">{text}</span>
                  </span>
                </li>
              ))}
            </ol>
            {withTest > 0 && (
              <p className="mt-3 flex items-start gap-2 rounded-xl bg-amber-soft px-4 py-3 text-sm text-amber-ink">
                <Icon name="shield" size={16} className="mt-0.5 shrink-0" />
                Los textos incluyen contenido de prueba. Reemplácelo con la versión aprobada antes de publicar.
              </p>
            )}
          </section>
          <ul className="grid grid-cols-1 gap-4 md:grid-cols-2">
            {docs.map((d) => {
              const current = d.versions.find((v) => v.status === "published");
              const draft = d.versions.find((v) => v.status === "draft");
              return (
                <li key={d.id}>
                  <button type="button" onClick={() => setOpenId(d.id)} className="group flex h-full w-full flex-col rounded-2xl border border-line bg-surface p-5 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-md">
                    <div className="flex items-start justify-between gap-3">
                      <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong">
                        <Icon name="file" size={20} />
                      </span>
                      <span className="flex flex-wrap justify-end gap-1.5">
                        {current && <Chip tone="ok" dot>{`Vigente ${current.version_label}`}</Chip>}
                        {draft && <Chip tone="amber" dot>{`Borrador ${draft.version_label}`}</Chip>}
                      </span>
                    </div>
                    <p className="mt-3 font-display text-lg font-semibold group-hover:text-brand-strong">{d.title}</p>
                    <p className="mt-1 text-sm text-muted">
                      Para: {APPLIES[d.applies_to] ?? d.applies_to}
                      {d.requires_acceptance_on_signup && " · se acepta al registrarse"}
                    </p>
                    <p className="mt-3 flex flex-wrap items-center gap-2 text-xs text-muted">
                      {draft?.has_test_text && <Chip tone="amber">Tiene texto de prueba</Chip>}
                      {current && !current.legal_reviewed_by && <Chip tone="amber">Sin revisión legal registrada</Chip>}
                      {current && <span>{current.acceptances} aceptaciones</span>}
                      <span className="ml-auto inline-flex items-center gap-1 font-semibold text-brand">
                        Editar <Icon name="chevron" size={14} />
                      </span>
                    </p>
                  </button>
                </li>
              );
            })}
          </ul>
        </>
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={closeToast} />}
    </div>
  );
}

function Stat({ icon, tone, value, label }: { icon: string; tone: string; value: string | number; label: string }) {
  return (
    <div className="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 shadow-sm">
      <span className={`grid h-11 w-11 place-items-center rounded-xl ${tone}`}>
        <Icon name={icon} size={20} />
      </span>
      <span>
        <span className="block font-display text-2xl font-bold leading-none">{value}</span>
        <span className="text-sm font-semibold">{label}</span>
      </span>
    </div>
  );
}

function Editor({ doc, onBack, onChanged }: { doc: Doc; onBack: () => void; onChanged: (msg: string, tone?: "ok" | "bad") => void }) {
  const draft = doc.versions.find((v) => v.status === "draft");
  const current = doc.versions.find((v) => v.status === "published");
  const [content, setContent] = useState<LegalDoc>(() => parseLegal(draft?.content ?? current?.content ?? ""));
  const [summary, setSummary] = useState(draft?.summary_of_changes ?? "");
  const [view, setView] = useState<"edit" | "preview">("edit");
  const [saving, setSaving] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [comparing, setComparing] = useState<[Version, Version] | null>(null);
  const [dirty, setDirty] = useState(false);
  const text = useMemo(() => buildLegal(content), [content]);
  const hasTest = text.includes(TEST_MARK);
  const editable = !!draft;

  const update = (next: LegalDoc) => {
    setContent(next);
    setDirty(true);
  };
  const setSection = (i: number, patch: Partial<LegalDoc["sections"][number]>) => update({ ...content, sections: content.sections.map((s, k) => (k === i ? { ...s, ...patch } : s)) });
  const move = (i: number, dir: -1 | 1) => {
    const s = [...content.sections];
    [s[i], s[i + dir]] = [s[i + dir], s[i]];
    update({ ...content, sections: s });
  };

  async function save(): Promise<boolean> {
    if (!draft) return false;
    setSaving(true);
    try {
      await api(`/internal/site/legal/versions/${draft.id}`, { method: "PATCH", body: { content: text, summary_of_changes: summary.trim() || null } });
      setDirty(false);
      onChanged("Borrador guardado. El sitio lo muestra como «en preparación».");
      return true;
    } catch (e) {
      onChanged(e instanceof ApiError ? e.message : "No se pudo guardar.", "bad");
      return false;
    } finally {
      setSaving(false);
    }
  }

  async function newVersion() {
    try {
      await api(`/internal/site/legal/${doc.id}/versions`, { method: "POST" });
      onChanged("Versión nueva creada como borrador, con el texto vigente para editar.");
    } catch (e) {
      onChanged(e instanceof ApiError ? e.message : "No se pudo crear la versión.", "bad");
    }
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Button variant="ghost" icon="back" onClick={onBack}>
          Todas las políticas
        </Button>
        <div className="flex flex-wrap gap-2">
          <a href={`/legal/${doc.slug}`} target="_blank" rel="noopener" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold shadow-sm hover:border-brand">
            <Icon name="eye" size={16} /> Ver en el sitio
          </a>
          {editable ? (
            <>
              {dirty && draft && (
                <Button variant="ghost" icon="swap" onClick={() => setComparing([{ ...draft, version_label: "lo guardado" }, { ...draft, content: text, version_label: "tus cambios" }])}>
                  Ver cambios sin guardar
                </Button>
              )}
              <Button variant="secondary" icon="check" loading={saving} onClick={save} disabled={!dirty}>
                Guardar borrador
              </Button>
              <Button icon="globe" onClick={() => setPublishing(true)} title={hasTest ? "Primero reemplaza el texto de prueba" : undefined}>
                Publicar
              </Button>
            </>
          ) : (
            <Button icon="plus" onClick={newVersion}>
              Crear versión nueva
            </Button>
          )}
        </div>
      </div>

      <section className="rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="font-display text-xl font-semibold">{doc.title}</h2>
            <p className="mt-1 text-sm text-muted">
              {editable
                ? `Borrador ${draft!.version_label}: puedes editarlo y guardarlo cuantas veces quieras. Al publicarlo queda fijo y aquí arriba aparecerá «Crear versión nueva».`
                : `Versión ${current?.version_label} publicada: no se edita. Para cambiarla, pulsa «Crear versión nueva».`}
            </p>
          </div>
          <div className="flex rounded-lg bg-bg p-1" role="tablist">
            {(
              [
                ["edit", "Editar", "pen"],
                ["preview", "Vista previa", "eye"],
              ] as const
            ).map(([k, l, ic]) => (
              <button key={k} role="tab" aria-selected={view === k} onClick={() => setView(k)} className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-semibold ${view === k ? "bg-surface text-brand-strong shadow-sm" : "text-muted"}`}>
                <Icon name={ic} size={14} /> {l}
              </button>
            ))}
          </div>
        </div>
        {!editable && current && (
          <p className="mt-4 flex items-start gap-2 rounded-lg border-l-4 border-brand bg-brand-soft px-3 py-2 text-sm text-brand-strong">
            <Icon name="lock" size={15} className="mt-0.5 shrink-0" />
            <span>
              <b>Lo publicado no se edita</b>: así queda prueba de qué texto aceptó cada persona. Para cambiarlo, usa «Crear versión nueva»: copia este texto en un borrador
              {` ${current.version_number + 1}.0 `}para que lo edites y lo publiques. La versión {current.version_label} queda guardada en el historial.
            </span>
          </p>
        )}
        {hasTest && editable && (
          <p className="mt-4 flex items-start gap-2 rounded-lg border-l-4 border-amber-ink bg-amber-soft px-3 py-2 text-sm text-amber-ink">
            <Icon name="pen" size={15} className="mt-0.5 shrink-0" />
            Quedan partes de prueba (marcadas «{TEST_MARK}»). Reemplácelas para poder publicar.
          </p>
        )}
      </section>

      {view === "preview" ? (
        <section className="rounded-2xl border border-line bg-white p-6 shadow-sm sm:p-10">
          <h1 className="mb-6 font-display text-3xl font-bold text-[#00254D]">{doc.title}</h1>
          <LegalBody doc={content} />
        </section>
      ) : (
        <div className="space-y-4">
          <FormField label="Nota al inicio (opcional)" hint="Se muestra destacada antes de la sección 1. Empieza la línea con «>» para que salga como aviso.">
            <textarea className="input min-h-20" disabled={!editable} value={content.intro} onChange={(e) => update({ ...content, intro: e.target.value })} />
          </FormField>
          {content.sections.map((s, i) => (
            <section key={i} className={`rounded-2xl border bg-surface p-4 shadow-sm sm:p-5 ${s.body.includes(TEST_MARK) || s.title.includes(TEST_MARK) ? "border-amber-ink/40" : "border-line"}`}>
              <div className="mb-3 flex items-center gap-2">
                <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand text-sm font-bold text-white">{i + 1}</span>
                <input className="input font-semibold" aria-label={`Título de la sección ${i + 1}`} disabled={!editable} value={s.title} onChange={(e) => setSection(i, { title: e.target.value })} />
                {editable && (
                  <span className="flex shrink-0 gap-1">
                    <button type="button" disabled={i === 0} onClick={() => move(i, -1)} className="grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-bg disabled:opacity-30" aria-label="Subir sección">
                      <Icon name="chevron" size={15} className="-rotate-90" />
                    </button>
                    <button type="button" disabled={i === content.sections.length - 1} onClick={() => move(i, 1)} className="grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-bg disabled:opacity-30" aria-label="Bajar sección">
                      <Icon name="chevron" size={15} className="rotate-90" />
                    </button>
                    <button type="button" onClick={() => update({ ...content, sections: content.sections.filter((_, k) => k !== i) })} className="grid h-9 w-9 place-items-center rounded-lg text-bad hover:bg-bad-soft" aria-label="Quitar sección">
                      <Icon name="close" size={15} />
                    </button>
                  </span>
                )}
              </div>
              <textarea
                className="input min-h-32 leading-relaxed"
                aria-label={`Texto de la sección ${i + 1}`}
                disabled={!editable}
                value={s.body}
                onChange={(e) => setSection(i, { body: e.target.value })}
              />
              {s.body.includes(TEST_MARK) && <p className="mt-1.5 text-xs font-semibold text-amber-ink">Texto de prueba: reemplázalo con la redacción del abogado.</p>}
            </section>
          ))}
          {editable && (
            <Button variant="secondary" icon="plus" onClick={() => update({ ...content, sections: [...content.sections, { title: "Nueva sección", body: "" }] })}>
              Agregar sección
            </Button>
          )}
          {editable && (
            <FormField
              label="Nota interna de la versión (opcional)"
              hint="Solo la ve el equipo en este panel, para recordar qué se cambió. Ej.: «Texto revisado por el abogado» o «Se amplió el plazo de respuesta». En el sitio solo se ve el número de versión y su fecha."
              counter={`${summary.length}/300`}
            >
              <input
                className="input"
                maxLength={300}
                value={summary}
                onChange={(e) => {
                  setSummary(e.target.value);
                  setDirty(true);
                }}
              />
            </FormField>
          )}
        </div>
      )}

      <section className="rounded-2xl border border-line bg-surface p-5 shadow-sm">
        <h3 className="font-display font-semibold">Versiones</h3>
        <p className="mb-3 text-sm text-muted">Cada publicación queda guardada. La vigente es la que ve el público; las notas solo se ven aquí.</p>
        <ul className="divide-y divide-line text-sm">
          {doc.versions.map((v, i) => (
            <li key={v.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
              <span>
                <b>Versión {v.version_label}</b>
                {v.summary_of_changes && <span className="text-muted"> · {v.summary_of_changes}</span>}
              </span>
              <span className="flex flex-wrap items-center gap-2 text-muted">
                {v.published_at && <span>Publicada {formatDate(v.published_at)}</span>}
                {v.legal_reviewed_by && <span>· revisó {v.legal_reviewed_by}</span>}
                {v.status === "published" && <span>· {v.acceptances} aceptaciones</span>}
                <Chip tone={STATUS[v.status].tone} dot>
                  {STATUS[v.status].label}
                </Chip>
                {doc.versions[i + 1] && (
                  <button type="button" onClick={() => setComparing([doc.versions[i + 1], v])} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-brand hover:bg-brand-soft">
                    <Icon name="swap" size={13} /> Comparar con la {doc.versions[i + 1].version_label}
                  </button>
                )}
              </span>
            </li>
          ))}
        </ul>
      </section>

      {comparing && <CompareModal before={comparing[0]} after={comparing[1]} onClose={() => setComparing(null)} />}

      {publishing && draft && (
        <PublishModal
          doc={doc}
          version={draft}
          hasTest={hasTest}
          dirty={dirty}
          onSave={save}
          onClose={() => setPublishing(false)}
          onDone={() => {
            setPublishing(false);
            onChanged(`${doc.title} publicado: ya rige en el sitio.`);
          }}
        />
      )}
    </div>
  );
}

function PublishModal({
  doc,
  version,
  hasTest,
  dirty,
  onSave,
  onClose,
  onDone,
}: {
  doc: Doc;
  version: Version;
  hasTest: boolean;
  dirty: boolean;
  onSave: () => Promise<boolean>;
  onClose: () => void;
  onDone: () => void;
}) {
  const [reviewer, setReviewer] = useState("");
  const [reaccept, setReaccept] = useState(false);
  const [from, setFrom] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  async function publish() {
    setBusy(true);
    setErrors({});
    try {
      if (dirty && !(await onSave())) return;
      await api(`/internal/site/legal/versions/${version.id}/publish`, {
        method: "POST",
        body: { legal_reviewed_by: reviewer.trim() || null, requires_reacceptance: reaccept, effective_from: from || null },
      });
      onDone();
    } catch (e) {
      setErrors(e instanceof ApiError ? (e.errors ?? { _: [e.message] }) : { _: ["No se pudo publicar."] });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title={`Publicar ${doc.title}`}
      subtitle={`Versión ${version.version_label}`}
      icon="globe"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button icon="globe" loading={busy} disabled={hasTest} onClick={publish}>
            Publicar versión
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {hasTest ? (
          <p className="rounded-lg border-l-4 border-amber-ink bg-amber-soft px-3 py-2 text-sm text-amber-ink">
            Todavía hay contenido de prueba (marcado «{TEST_MARK}»). Reemplácelo antes de publicar.
          </p>
        ) : (
          <p className="text-sm">
            Al publicar, esta versión <b>rige en el sitio</b> y <b>queda fija</b>: ya no se edita. Para cambiarla más adelante, crearás una versión nueva a partir de ella.
          </p>
        )}
        {(errors._ || errors.content) && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{(errors._ ?? errors.content)[0]}</p>}
        <FormField label="Abogado que lo revisó (opcional)" hint="Nombre y colegiatura, por ejemplo «Dra. Ana Torres (CAL 12345)». Queda en la bitácora." error={errors.legal_reviewed_by?.[0]}>
          <input className="input" maxLength={120} value={reviewer} onChange={(e) => setReviewer(e.target.value)} />
        </FormField>
        {!reviewer.trim() && !hasTest && (
          <p className="flex items-start gap-2 rounded-lg border-l-4 border-amber-ink bg-amber-soft px-3 py-2 text-sm text-amber-ink">
            <Icon name="shield" size={15} className="mt-0.5 shrink-0" />
            Se publicará sin revisión legal registrada. Este aviso solo se ve en el panel.
          </p>
        )}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Rige desde (opcional)" hint="Vacío: desde ahora." error={errors.effective_from?.[0]}>
            <input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </FormField>
          <SwitchRow label="Pedir aceptarla otra vez" hint="Los usuarios la aceptan de nuevo al entrar." checked={reaccept} onChange={setReaccept} />
        </div>
      </div>
    </Modal>
  );
}

/** Qué cambió entre dos versiones: en verde lo agregado y en rojo lo quitado (línea por línea). */
function CompareModal({ before, after, onClose }: { before: Version; after: Version; onClose: () => void }) {
  const lines = useMemo(() => diffLines(before.content, after.content), [before, after]);
  const added = lines.filter((l) => l.type === "added").length;
  const removed = lines.filter((l) => l.type === "removed").length;
  return (
    <Modal title={/^\d/.test(before.version_label) ? `Versión ${before.version_label} → ${after.version_label}` : `Comparar ${before.version_label} con ${after.version_label}`} subtitle={`${added} ${added === 1 ? "línea agregada" : "líneas agregadas"} · ${removed} ${removed === 1 ? "quitada" : "quitadas"}`} icon="swap" size="lg" onClose={onClose}>
      <div className="mb-3 flex flex-wrap gap-3 text-xs">
        <span className="inline-flex items-center gap-1.5"><span className="h-3 w-3 rounded bg-ok-soft ring-1 ring-ok/40" /> Agregado en {/^\d/.test(after.version_label) ? `la ${after.version_label}` : after.version_label}</span>
        <span className="inline-flex items-center gap-1.5"><span className="h-3 w-3 rounded bg-bad-soft ring-1 ring-bad/40" /> Quitado de {/^\d/.test(before.version_label) ? `la ${before.version_label}` : before.version_label}</span>
      </div>
      {added + removed === 0 ? (
        <p className="rounded-lg bg-bg px-4 py-3 text-sm text-muted">Las dos versiones tienen el mismo texto.</p>
      ) : (
        <div className="overflow-hidden rounded-xl border border-line text-sm leading-relaxed">
          {lines.map((l, k) =>
            l.text.trim() === "" && l.type === "same" ? (
              <div key={k} className="h-2" />
            ) : (
              <div
                key={k}
                className={`whitespace-pre-wrap px-3 py-1 ${l.type === "added" ? "bg-ok-soft text-fg" : l.type === "removed" ? "bg-bad-soft text-fg line-through decoration-bad/50" : "text-muted"}`}
              >
                <span className="mr-2 inline-block w-3 select-none font-mono font-bold">{l.type === "added" ? "+" : l.type === "removed" ? "−" : ""}</span>
                {l.text.startsWith("## ") ? <b className="text-fg">{l.text.slice(3)}</b> : l.text.replace(/^> /, "")}
              </div>
            ),
          )}
        </div>
      )}
    </Modal>
  );
}
