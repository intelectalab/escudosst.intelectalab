"use client";

// Sitio web › Contenido (SCR-WEB-23): preguntas frecuentes, testimonios y otros productos del grupo.
// Lo publicado/aprobado/visible se ve en el sitio en menos de un minuto.

import { FormEvent, useCallback, useEffect, useState } from "react";
import { SitePanelTabs } from "@/components/site/SitePanelTabs";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone, EmptyState, PageHeader } from "@/components/ui/Display";
import { ChoiceCards, FormField, FormSection, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { FAQ_TOPICS, topicName } from "@/lib/faq";

type Kind = "faqs" | "testimonials" | "products";
type Row = Record<string, unknown> & { id: string };


const TABS: [Kind, string, string, string][] = [
  ["faqs", "Preguntas frecuentes", "search", "Dudas comunes con su respuesta. Salen en la portada y en la página de preguntas frecuentes."],
  ["testimonials", "Testimonios", "star", "Comentarios de clientes. Solo se publican con su permiso (Ley 29733) o marcados como «ejemplo»."],
  ["products", "Otros productos", "grid", "Otros productos del grupo que se muestran en el pie del sitio."],
];

const STATUS: Record<string, [string, ChipTone]> = {
  draft: ["Borrador", "amber"],
  published: ["Publicada", "ok"],
  archived: ["Archivada", "na"],
  pending: ["Por aprobar", "amber"],
  approved: ["Publicado", "ok"],
  hidden: ["Oculto", "na"],
};

export default function ContenidoPage() {
  const [kind, setKind] = useState<Kind>("faqs");
  const [rows, setRows] = useState<Row[] | null>(null);
  const [editing, setEditing] = useState<Row | "new" | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    setRows(null);
    api<{ data: Row[] }>(`/internal/site/${kind}`)
      .then((r) => setRows(r.data))
      .catch(() => setRows([]));
  }, [kind]);
  useEffect(load, [load]);

  const tab = TABS.find(([k]) => k === kind)!;

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Contenido"]]} icon="globe" title="Sitio web" description="Preguntas frecuentes, testimonios y otros productos que se muestran en el sitio." />
      <SitePanelTabs />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div role="tablist" className="flex flex-wrap gap-1 rounded-xl bg-surface p-1 shadow-sm ring-1 ring-line">
          {TABS.map(([k, label, icon]) => (
            <button
              key={k}
              role="tab"
              aria-selected={kind === k}
              onClick={() => setKind(k)}
              className={`inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors ${kind === k ? "bg-brand-soft text-brand-strong" : "text-muted hover:text-fg"}`}
            >
              <Icon name={icon} size={15} /> {label}
            </button>
          ))}
        </div>
        <Button variant="primary" icon="plus" onClick={() => setEditing("new")}>
          {kind === "faqs" ? "Nueva pregunta" : kind === "testimonials" ? "Nuevo testimonio" : "Nuevo producto"}
        </Button>
      </div>
      <p className="-mt-2 text-sm text-muted">{tab[3]}</p>

      {rows === null ? (
        <p className="text-sm text-muted">Cargando…</p>
      ) : rows.length === 0 ? (
        <EmptyState icon={tab[2]} text={kind === "faqs" ? "Aún no hay preguntas. Agrega las dudas que más te hacen los clientes." : kind === "testimonials" ? "Aún no hay testimonios. Cuando un cliente te dé su comentario y su permiso, agrégalo aquí." : "No hay productos."} />
      ) : (
        <ul className="grid grid-cols-1 gap-3 md:grid-cols-2">
          {rows.map((r) => (
            <li key={r.id}>
              <button type="button" onClick={() => setEditing(r)} className="group flex h-full w-full flex-col gap-2 rounded-2xl border border-line bg-surface p-5 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-md">
                <span className="flex flex-wrap items-center gap-2">
                  {kind === "faqs" && <Chip tone="na">{topicName(String(r.topic))}</Chip>}
                  {kind !== "products" && (
                    <Chip tone={STATUS[String(r.status)][1]} dot>
                      {STATUS[String(r.status)][0]}
                    </Chip>
                  )}
                  {kind === "testimonials" && r.is_example ? <Chip tone="brand">Ejemplo</Chip> : null}
                  {kind === "products" && (
                    <Chip tone={r.is_visible ? "ok" : "na"} dot>
                      {r.is_visible ? "Visible" : "Oculto"}
                    </Chip>
                  )}
                </span>
                <b className="font-display text-[15px] group-hover:text-brand-strong">
                  {kind === "faqs" ? String(r.question) : kind === "testimonials" ? `«${String(r.quote).slice(0, 110)}${String(r.quote).length > 110 ? "…" : ""}»` : String(r.name)}
                </b>
                <span className="text-sm text-muted">
                  {kind === "faqs"
                    ? String(r.answer).slice(0, 120)
                    : kind === "testimonials"
                      ? [r.author_name, r.author_role, r.company_name].filter(Boolean).join(" · ")
                      : (r.description as string | null) ?? "Sin descripción"}
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}

      {editing && (
        <EditModal
          kind={kind}
          row={editing === "new" ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={(msg) => {
            setEditing(null);
            setToast(msg);
            load();
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function EditModal({ kind, row, onClose, onSaved }: { kind: Kind; row: Row | null; onClose: () => void; onSaved: (msg: string) => void }) {
  const init: Record<string, unknown> =
    kind === "faqs"
      ? { topic: "general", question: "", answer: "", status: "published", sort_order: 0 }
      : kind === "testimonials"
        ? { author_name: "", author_role: "", company_name: "", quote: "", rating: null, publication_consent: false, consent_evidence: "", is_example: false, status: "pending", sort_order: 0 }
        : { name: "", description: "", url: "", is_visible: true, sort_order: 0 };
  const [f, setF] = useState<Record<string, unknown>>(() => (row ? Object.fromEntries(Object.keys(init).map((k) => [k, row[k] ?? init[k]])) : init));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const set = (k: string, v: unknown) => setF((x) => ({ ...x, [k]: v }));
  const str = (k: string) => (f[k] as string | null) ?? "";
  const err = (k: string) => errors[k]?.[0];

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setErrors({});
    try {
      await api(`/internal/site/${kind}${row ? `/${row.id}` : ""}`, { method: row ? "PATCH" : "POST", body: f });
      onSaved(row ? "Cambios guardados." : "Agregado.");
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.errors ?? {});
        if (!e.errors) setError(e.message);
      } else setError("No se pudo guardar.");
    } finally {
      setSaving(false);
    }
  }

  async function remove() {
    if (!row || !window.confirm("¿Quitar del sitio? Esta acción no se puede deshacer desde el panel.")) return;
    await api(`/internal/site/${kind}/${row.id}`, { method: "DELETE" });
    onSaved("Eliminado.");
  }

  const title = kind === "faqs" ? "Pregunta frecuente" : kind === "testimonials" ? "Testimonio" : "Otro producto";

  return (
    <Modal
      title={row ? `Editar ${title.toLowerCase()}` : title}
      icon={kind === "faqs" ? "search" : kind === "testimonials" ? "star" : "grid"}
      onClose={onClose}
      footer={
        <>
          {row && (
            <Button variant="danger-ghost" icon="close" onClick={remove} className="sm:mr-auto">
              Eliminar
            </Button>
          )}
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="content-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="content-form" onSubmit={save} className="space-y-4" noValidate>
        {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error}</p>}

        {kind === "faqs" && (
          <FormSection icon="search" title="Pregunta y respuesta">
            <FormField label="Tema" error={err("topic")}>
              <select className="input" value={str("topic")} onChange={(e) => set("topic", e.target.value)}>
                {FAQ_TOPICS.map(([k, l]) => (
                  <option key={k} value={k}>
                    {l}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Pregunta" error={err("question")} counter={`${str("question").length}/200`}>
              <input className="input" maxLength={200} placeholder="¿Necesito instalar algo?" value={str("question")} onChange={(e) => set("question", e.target.value)} />
            </FormField>
            <FormField label="Respuesta" error={err("answer")} hint="Corta y clara. Sin promesas que no se puedan cumplir.">
              <textarea className="input min-h-[120px]" maxLength={2000} value={str("answer")} onChange={(e) => set("answer", e.target.value)} />
            </FormField>
            <ChoiceCards
              label="Estado"
              value={str("status")}
              onChange={(v) => set("status", v)}
              columns={3}
              options={[
                { value: "published", title: "Publicada", detail: "Se ve en el sitio." },
                { value: "draft", title: "Borrador", detail: "Solo en el panel." },
                { value: "archived", title: "Archivada", detail: "Ya no se usa." },
              ]}
            />
          </FormSection>
        )}

        {kind === "testimonials" && (
          <>
            <FormSection icon="user" title="Quién lo dice">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <FormField label="Nombre o iniciales" error={err("author_name")}>
                  <input className="input" maxLength={80} placeholder="Rosa P." value={str("author_name")} onChange={(e) => set("author_name", e.target.value)} />
                </FormField>
                <FormField label="Cargo (opcional)" error={err("author_role")}>
                  <input className="input" maxLength={80} value={str("author_role")} onChange={(e) => set("author_role", e.target.value)} />
                </FormField>
                <FormField label="Empresa (opcional)" error={err("company_name")} hint="Solo si la empresa también lo autorizó.">
                  <input className="input" maxLength={120} value={str("company_name")} onChange={(e) => set("company_name", e.target.value)} />
                </FormField>
              </div>
              <FormField label="Comentario" error={err("quote")} counter={`${str("quote").length}/600`}>
                <textarea className="input min-h-[100px]" maxLength={600} value={str("quote")} onChange={(e) => set("quote", e.target.value)} />
              </FormField>
              <div>
                <p className="mb-1.5 text-sm font-semibold">Calificación (opcional)</p>
                <div className="flex gap-1">
                  {[1, 2, 3, 4, 5].map((n) => (
                    <button key={n} type="button" onClick={() => set("rating", f.rating === n ? null : n)} aria-label={`${n} estrellas`} className={`text-2xl leading-none ${Number(f.rating ?? 0) >= n ? "text-[#F4B90F]" : "text-line"}`}>
                      ★
                    </button>
                  ))}
                </div>
              </div>
            </FormSection>
            <FormSection icon="shield" title="Permiso para publicar" description="La Ley 29733 exige el permiso de la persona (y de la empresa si se usa su nombre).">
              <SwitchRow label="Tengo su permiso por escrito" hint="Correo, formulario o contrato donde acepta que se publique." checked={!!f.publication_consent} onChange={(v) => set("publication_consent", v)} />
              {!!f.publication_consent && (
                <FormField label="¿Cómo lo obtuviste?" error={err("consent_evidence")}>
                  <input className="input" maxLength={300} placeholder="Correo del 05/11/2026 de rosa@empresa.pe" value={str("consent_evidence")} onChange={(e) => set("consent_evidence", e.target.value)} />
                </FormField>
              )}
              <SwitchRow label="Es un ejemplo" hint="Testimonio de muestra: el sitio lo rotula «Ejemplo»." checked={!!f.is_example} onChange={(v) => set("is_example", v)} />
              <ChoiceCards
                label="Estado"
                value={str("status")}
                onChange={(v) => set("status", v)}
                columns={3}
                options={[
                  { value: "approved", title: "Publicar", detail: "Se ve en el sitio.", disabled: !f.publication_consent && !f.is_example },
                  { value: "pending", title: "Por aprobar", detail: "Solo en el panel." },
                  { value: "hidden", title: "Oculto", detail: "No se muestra." },
                ]}
              />
              {err("status") && <p className="text-xs font-medium text-bad">● {err("status")}</p>}
            </FormSection>
          </>
        )}

        {kind === "products" && (
          <FormSection icon="grid" title="Producto">
            <FormField label="Nombre" error={err("name")}>
              <input className="input" maxLength={60} value={str("name")} onChange={(e) => set("name", e.target.value)} />
            </FormField>
            <FormField label="Descripción corta (opcional)" error={err("description")} counter={`${str("description").length}/200`}>
              <input className="input" maxLength={200} value={str("description")} onChange={(e) => set("description", e.target.value)} />
            </FormField>
            <FormField label="Enlace (opcional)" error={err("url")} hint="Debe empezar con https://. Se abre en otra pestaña.">
              <input className="input" maxLength={250} placeholder="https://" value={str("url")} onChange={(e) => set("url", e.target.value.trim())} />
            </FormField>
            <SwitchRow label="Mostrar en el sitio" hint="Aparece en «Otros productos» del pie de página." checked={!!f.is_visible} onChange={(v) => set("is_visible", v)} />
          </FormSection>
        )}
      </form>
    </Modal>
  );
}
