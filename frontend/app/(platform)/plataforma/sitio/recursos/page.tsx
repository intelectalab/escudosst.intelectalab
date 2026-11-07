"use client";

// Sitio web › Recursos descargables (SCR-WEB-43): plantillas, guías, listas y formatos que se descargan en /recursos.
// «Libre» se descarga sin datos; «Con registro» pide nombre, correo y empresa (la persona entra a «Interesados»).
// El archivo y la portada se eligen de la biblioteca de medios.

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { type Media, MediaPicker, MediaThumb } from "@/components/platform/MediaKit";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { assetUrl, STORY_SECTORS } from "@/lib/site";

type Item = {
  id: string;
  title: string;
  slug: string;
  description: string | null;
  resource_type: string;
  sector: string | null;
  is_gated: boolean;
  status: "draft" | "published" | "archived";
  published_at: string | null;
  download_count: number;
  leads: number;
  last_download_at: string | null;
  seo_title: string | null;
  seo_description: string | null;
  file_id: string | null;
  cover_file_id: string | null;
  file_media_id: string | null;
  file_title: string | null;
  file_mime: string | null;
  cover_media_id: string | null;
};

const STATUS = { draft: ["Borrador", "amber"], published: ["Publicado", "ok"], archived: ["Quitado del sitio", "na"] } as const;

export default function RecursosPanelPage() {
  const me = usePlatformUser();
  const owner = me.platform_role !== "support";
  const [rows, setRows] = useState<Item[] | null>(null);
  const [types, setTypes] = useState<Record<string, string>>({});
  const [editing, setEditing] = useState<Item | "new" | null>(null);
  const [filter, setFilter] = useState<"all" | "published" | "draft" | "gated">("all");
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: Item[]; types: Record<string, string> }>("/internal/site/resources").then((r) => {
      setRows(r.data);
      setTypes(r.types);
    });
  }, []);
  useEffect(load, [load]);

  async function act(r: Item, action: "publish" | "archive") {
    try {
      await api(`/internal/site/resources/${r.id}/${action}`, { method: "POST" });
      setToast({ text: action === "publish" ? "Publicado: ya se ve en /recursos." : "Quitado del sitio.", tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: e instanceof ApiError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : "No se pudo.", tone: "bad" });
    }
  }

  const all = rows ?? [];
  const list = all.filter((r) => (filter === "published" ? r.status === "published" : filter === "draft" ? r.status === "draft" : filter === "gated" ? r.is_gated : true));
  const toggle = (f: typeof filter) => setFilter((x) => (x === f ? "all" : f));

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Recursos descargables"]]}
        icon="file"
        title="Recursos descargables"
        description="Plantillas, guías y formatos SST que la gente descarga en /recursos. Atraen visitas desde Google y, si piden registro, suman interesados."
        aside={
          <Button variant="primary" icon="plus" onClick={() => setEditing("new")}>
            Nuevo recurso
          </Button>
        }
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Publicados" value={rows ? all.filter((r) => r.status === "published").length : "–"} icon="globe" tone="ok" hint="Se ven en /recursos" active={filter === "published"} onClick={() => toggle("published")} />
        <StatCard label="Borradores" value={rows ? all.filter((r) => r.status === "draft").length : "–"} icon="pen" tone="amber" hint="Falta publicarlos" active={filter === "draft"} onClick={() => toggle("draft")} />
        <StatCard label="Con registro" value={rows ? all.filter((r) => r.is_gated).length : "–"} icon="people" tone="brand" hint="Piden datos antes de descargar" active={filter === "gated"} onClick={() => toggle("gated")} />
        <StatCard label="Descargas" value={rows ? all.reduce((a, r) => a + r.download_count, 0) : "–"} icon="refresh" tone="na" hint={`${all.reduce((a, r) => a + Number(r.leads), 0)} con datos de contacto`} />
      </div>

      {rows === null ? (
        <div className="h-48 animate-pulse rounded-2xl bg-line/40" />
      ) : list.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-line bg-surface p-10 text-center">
          <span className="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-brand-soft text-brand-strong">
            <Icon name="file" size={22} />
          </span>
          <p className="mt-3 font-semibold">{all.length ? "Ninguno en este filtro" : "Todavía no hay recursos"}</p>
          <p className="mt-1 text-sm text-muted">Sube el PDF a la biblioteca de medios y crea el recurso con «Nuevo recurso».</p>
        </div>
      ) : (
        <ul className="grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">
          {list.map((r) => (
            <li key={r.id} className="flex flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
              <div className="flex gap-3 p-4">
                <span className="grid h-16 w-14 shrink-0 place-items-center overflow-hidden rounded-lg border border-line bg-gradient-to-br from-brand-soft to-bg text-brand-strong">
                  {r.cover_file_id ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={assetUrl(r.cover_file_id) ?? ""} alt="" className="h-full w-full object-cover" />
                  ) : (
                    <Icon name="file" size={22} />
                  )}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="flex flex-wrap gap-1.5">
                    <Chip tone={STATUS[r.status][1]} dot>
                      {STATUS[r.status][0]}
                    </Chip>
                    <Chip tone={r.is_gated ? "brand" : "ok"}>{r.is_gated ? "Con registro" : "Libre"}</Chip>
                  </span>
                  <b className="mt-1 block font-display text-[15px] leading-snug">{r.title}</b>
                  <span className="block text-xs text-muted">
                    {types[r.resource_type] ?? r.resource_type}
                    {r.sector && ` · ${STORY_SECTORS[r.sector] ?? r.sector}`} · {r.download_count} descarga{r.download_count === 1 ? "" : "s"}
                  </span>
                  {!r.file_id && <span className="mt-1 block text-xs font-semibold text-bad">Falta el archivo</span>}
                </span>
              </div>
              <div className="mt-auto flex flex-wrap gap-1 border-t border-line bg-bg/50 px-3 py-2">
                <Button size="sm" variant="ghost" icon="pen" onClick={() => setEditing(r)}>
                  Editar
                </Button>
                {r.status === "published" && (
                  <a href={`/recursos#${r.slug}`} target="_blank" rel="noopener" className="inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-sm font-semibold text-brand hover:bg-brand-soft">
                    <Icon name="globe" size={14} /> Ver
                  </a>
                )}
                {owner && r.status !== "published" && (
                  <Button size="sm" variant="ghost" icon="globe" onClick={() => act(r, "publish")}>
                    Publicar
                  </Button>
                )}
                {owner && r.status === "published" && (
                  <Button size="sm" variant="ghost" onClick={() => act(r, "archive")}>
                    Quitar del sitio
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      <p className="text-xs text-muted">
        Quienes descargan un recurso «con registro» aparecen en{" "}
        <Link href="/plataforma/sitio/interesados" className="font-semibold text-brand hover:underline">
          Interesados
        </Link>{" "}
        con el origen «Recurso». Cada descarga se registra; el enlace de descarga vence a los 10 minutos.
      </p>

      {editing && (
        <ResourceModal
          row={editing === "new" ? null : editing}
          types={types}
          onClose={() => setEditing(null)}
          onDone={(t) => {
            setEditing(null);
            setToast({ text: t, tone: "ok" });
            load();
          }}
        />
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function ResourceModal({ row, types, onClose, onDone }: { row: Item | null; types: Record<string, string>; onClose: () => void; onDone: (t: string) => void }) {
  const [f, setF] = useState({
    title: row?.title ?? "",
    slug: row?.slug ?? "",
    description: row?.description ?? "",
    resource_type: row?.resource_type ?? "template",
    sector: row?.sector ?? "",
    is_gated: row?.is_gated ?? true,
    seo_title: row?.seo_title ?? "",
    seo_description: row?.seo_description ?? "",
  });
  const [file, setFile] = useState<{ id: string; title: string; file_id: string; kind: string } | null>(row?.file_media_id ? { id: row.file_media_id, title: row.file_title ?? "Archivo", file_id: row.file_id ?? "", kind: row.file_mime?.startsWith("image/") ? "image" : "document" } : null);
  const [cover, setCover] = useState<{ id: string; file_id: string } | null>(row?.cover_media_id ? { id: row.cover_media_id, file_id: row.cover_file_id ?? "" } : null);
  const [picking, setPicking] = useState<"file" | "cover" | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    const body = { ...f, slug: f.slug || null, sector: f.sector || null, media_id: file?.id ?? null, cover_media_id: cover?.id ?? null };
    try {
      await api(row ? `/internal/site/resources/${row.id}` : "/internal/site/resources", { method: row ? "PATCH" : "POST", body });
      onDone(row ? "Recurso guardado." : "Recurso creado como borrador. Publícalo cuando esté listo.");
    } catch (x) {
      if (x instanceof ApiError) setErrors(x.errors ?? { title: [x.detail ?? x.message] });
      setSaving(false);
    }
  }

  const pick = (ms: Media[]) => {
    const m = ms[0];
    if (picking === "file") setFile({ id: m.id, title: m.title, file_id: m.file_id, kind: m.kind });
    else setCover({ id: m.id, file_id: m.file_id });
    setPicking(null);
  };

  return (
    <Modal
      title={row ? "Editar recurso" : "Nuevo recurso"}
      icon="file"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="resource-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="resource-form" onSubmit={save} className="space-y-4" noValidate>
        <FormField label="Título" error={errors.title?.[0]}>
          <input className="input" maxLength={140} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} placeholder="Plantilla de matriz IPERC" autoFocus />
        </FormField>
        <FormField label="Descripción (opcional)" error={errors.description?.[0]} hint="Qué trae y para quién sirve. Sale en la tarjeta.">
          <textarea className="input min-h-20" maxLength={500} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} />
        </FormField>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Tipo" error={errors.resource_type?.[0]}>
            <select className="input" value={f.resource_type} onChange={(e) => setF({ ...f, resource_type: e.target.value })}>
              {Object.entries(types).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Rubro (opcional)" error={errors.sector?.[0]}>
            <select className="input" value={f.sector} onChange={(e) => setF({ ...f, sector: e.target.value })}>
              <option value="">Todos los rubros</option>
              {Object.entries(STORY_SECTORS).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="rounded-xl border border-line p-3">
            <p className="text-sm font-semibold">Archivo que se descarga</p>
            {file ? (
              <p className="mt-1 flex items-center gap-2 text-sm">
                <Icon name="file" size={15} className="text-brand" /> <span className="truncate">{file.title}</span>
              </p>
            ) : (
              <p className="mt-1 text-xs text-muted">PDF o imagen de la biblioteca.</p>
            )}
            {errors.media_id && <p className="mt-1 text-xs font-medium text-bad">● {errors.media_id[0]}</p>}
            <Button size="sm" variant="soft" className="mt-2" icon="plus" onClick={() => setPicking("file")}>
              {file ? "Cambiar" : "Elegir de la biblioteca"}
            </Button>
          </div>
          <div className="rounded-xl border border-line p-3">
            <p className="text-sm font-semibold">Portada (opcional)</p>
            {cover ? (
              <span className="mt-1 block h-16 w-24 overflow-hidden rounded-lg border border-line">
                <MediaThumb m={{ kind: "image", file_id: cover.file_id, alt_text: "" } as Media} />
              </span>
            ) : (
              <p className="mt-1 text-xs text-muted">Una imagen de la primera página.</p>
            )}
            {errors.cover_media_id && <p className="mt-1 text-xs font-medium text-bad">● {errors.cover_media_id[0]}</p>}
            <div className="mt-2 flex gap-1">
              <Button size="sm" variant="soft" icon="plus" onClick={() => setPicking("cover")}>
                {cover ? "Cambiar" : "Elegir"}
              </Button>
              {cover && (
                <Button size="sm" variant="ghost" onClick={() => setCover(null)}>
                  Quitar
                </Button>
              )}
            </div>
          </div>
        </div>

        <SwitchRow label="Pedir datos antes de descargar («Con registro»)" hint="La persona deja nombre, correo y empresa; aparece en Interesados. Si lo apagas, se descarga libre." checked={f.is_gated} onChange={(v) => setF({ ...f, is_gated: v })} />

        <details className="rounded-xl border border-line p-3">
          <summary className="cursor-pointer text-sm font-semibold">Google y dirección (opcional)</summary>
          <p className="mt-2 rounded-lg bg-bg px-3 py-2 text-xs text-muted">
            <b className="text-fg">Puedes dejarlo vacío.</b> La <b className="text-fg">dirección</b> es el final del enlace del recurso (escudosst.com/recursos#<i>dirección</i>). El <b className="text-fg">título</b> y la <b className="text-fg">descripción en Google</b> son el texto azul y gris que aparece cuando alguien lo encuentra buscando en Google; si están vacíos se usan el título y la descripción del recurso.
          </p>
          <div className="mt-3 space-y-3">
            <FormField label="Dirección" error={errors.slug?.[0]} hint="Si la dejas vacía, se arma con el título.">
              <input className="input font-mono text-sm" maxLength={80} value={f.slug} onChange={(e) => setF({ ...f, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, "-") })} />
            </FormField>
            <FormField label="Título en Google" counter={`${f.seo_title.length}/70`}>
              <input className="input" maxLength={70} value={f.seo_title} onChange={(e) => setF({ ...f, seo_title: e.target.value })} />
            </FormField>
            <FormField label="Descripción en Google" counter={`${f.seo_description.length}/170`}>
              <textarea className="input min-h-16" maxLength={170} value={f.seo_description} onChange={(e) => setF({ ...f, seo_description: e.target.value })} />
            </FormField>
          </div>
        </details>
      </form>
      {picking && <MediaPicker kind={picking === "cover" ? "image" : undefined} onClose={() => setPicking(null)} onPick={pick} />}
    </Modal>
  );
}
