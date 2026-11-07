"use client";

// Sitio web › Biblioteca de medios (SCR-WEB-65): imágenes, íconos, documentos y videos del sitio en un solo lugar.
// Carpetas y etiquetas, texto alternativo obligatorio en imágenes, «Dónde se usa», reemplazar el archivo conservando
// sus usos y eliminar solo si no se usa (RN-WEB-82).

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useRef, useState } from "react";
import { bytes, KIND_LABEL, type Media, type MediaKind, MediaThumb, MediaUploadForm, sendMedia } from "@/components/platform/MediaKit";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { assetUrl } from "@/lib/site";

type Usage = { label: string; name: string | null; href: string | null };

export default function MediosPage() {
  const me = usePlatformUser();
  const [rows, setRows] = useState<Media[] | null>(null);
  const [folders, setFolders] = useState<string[]>([]);
  const [kind, setKind] = useState<MediaKind | null>(null);
  const [folder, setFolder] = useState<string | null>(null);
  const [q, setQ] = useState("");
  const [view, setView] = useState<"grid" | "list">("grid");
  const [uploading, setUploading] = useState(false);
  const [open, setOpen] = useState<Media | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: Media[]; folders: string[] }>("/internal/site/media").then((r) => {
      setRows(r.data);
      setFolders(r.folders);
    });
  }, []);
  useEffect(load, [load]);

  const all = rows ?? [];
  const list = all.filter(
    (m) =>
      (!kind || m.kind === kind) &&
      (folder === null || (m.folder ?? "") === folder) &&
      (!q || [m.title, m.alt_text ?? "", ...m.tags].join(" ").toLowerCase().includes(q.toLowerCase())),
  );
  const count = (k: MediaKind) => all.filter((m) => m.kind === k).length;
  const total = all.reduce((a, m) => a + (m.size_bytes ?? 0), 0);

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Biblioteca de medios"]]}
        icon="eye"
        title="Biblioteca de medios"
        description="Las imágenes, íconos, documentos y videos del sitio. Se suben una vez y se eligen desde los editores (por ejemplo, la galería de un caso)."
        aside={
          <Button variant="primary" icon="plus" onClick={() => setUploading(true)}>
            Subir archivo
          </Button>
        }
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {(Object.keys(KIND_LABEL) as MediaKind[]).map((k, i) => (
          <StatCard
            key={k}
            label={{ image: "Imágenes", icon: "Íconos", document: "Documentos", video: "Videos" }[k]}
            value={rows ? count(k) : "–"}
            icon={KIND_LABEL[k][1]}
            tone={(["brand", "amber", "ok", "na"] as const)[i]}
            hint={k === "image" ? `${bytes(total)} en total` : undefined}
            active={kind === k}
            onClick={() => setKind((x) => (x === k ? null : k))}
          />
        ))}
      </div>

      <div className="grid grid-cols-1 items-start gap-5 xl:grid-cols-[13rem_minmax(0,1fr)]">
        <nav aria-label="Carpetas" className="rounded-2xl border border-line bg-surface p-2 shadow-sm">
          <p className="px-2.5 pb-1 pt-1 text-[10.5px] font-bold uppercase tracking-[0.12em] text-muted">Carpetas</p>
          {[[null, "Todo"], ["", "Sin carpeta"], ...folders.map((x) => [x, x])].map(([value, label]) => {
            const on = folder === value;
            const n = value === null ? all.length : all.filter((m) => (m.folder ?? "") === value).length;
            return (
              <button
                key={label ?? "all"}
                type="button"
                onClick={() => setFolder(value)}
                className={`flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-sm ${on ? "bg-brand-soft font-semibold text-brand-strong" : "hover:bg-bg"}`}
              >
                <Icon name={value === null ? "grid" : "box"} size={15} className={on ? "text-brand" : "text-muted"} />
                <span className="flex-1 truncate">{label}</span>
                <span className="text-xs text-muted">{n}</span>
              </button>
            );
          })}
        </nav>

        <section className="space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <span className="relative min-w-[14rem] flex-1">
              <Icon name="search" size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
              <input className="input pl-9" placeholder="Buscar por título, descripción o etiqueta" value={q} onChange={(e) => setQ(e.target.value)} />
            </span>
            <div className="flex rounded-lg border border-line bg-surface p-0.5" role="tablist" aria-label="Vista">
              {(
                [
                  ["grid", "Cuadrícula", "grid"],
                  ["list", "Lista", "menu"],
                ] as const
              ).map(([k, l, ic]) => (
                <button key={k} type="button" role="tab" aria-selected={view === k} title={l} onClick={() => setView(k)} className={`grid h-8 w-9 place-items-center rounded-md ${view === k ? "bg-brand text-white" : "text-muted hover:text-fg"}`}>
                  <Icon name={ic} size={15} />
                </button>
              ))}
            </div>
          </div>

          {rows === null ? (
            <div className="h-64 animate-pulse rounded-2xl bg-line/40" />
          ) : list.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-line bg-surface p-10 text-center">
              <span className="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-brand-soft text-brand-strong">
                <Icon name="eye" size={22} />
              </span>
              <p className="mt-3 font-semibold">{all.length ? "Nada con estos filtros" : "La biblioteca está vacía"}</p>
              <p className="mt-1 text-sm text-muted">{all.length ? "Prueba con otra carpeta o tipo." : "Sube fotos de obras, capturas del sistema o documentos para usarlos en el sitio."}</p>
            </div>
          ) : view === "grid" ? (
            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6">
              {list.map((m) => (
                <li key={m.id}>
                  <button type="button" onClick={() => setOpen(m)} className="group block w-full overflow-hidden rounded-2xl border border-line bg-surface text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-md">
                    <span className="relative block aspect-[4/3] bg-bg">
                      <MediaThumb m={m} />
                      <span className="absolute left-2 top-2 flex gap-1">
                        {m.is_example && <Chip tone="amber">Ejemplo</Chip>}
                      </span>
                      {m.usage_count > 0 && (
                        <span className="absolute bottom-2 right-2 rounded-full bg-side/85 px-2 py-0.5 text-[11px] font-bold text-white" title="Lugares donde se usa">
                          En uso · {m.usage_count}
                        </span>
                      )}
                    </span>
                    <span className="block px-3 py-2">
                      <b className="block truncate text-sm group-hover:text-brand-strong">{m.title}</b>
                      <span className="block truncate text-xs text-muted">
                        {KIND_LABEL[m.kind][0]} · {m.width ? `${m.width}×${m.height} · ` : ""}
                        {bytes(m.size_bytes)}
                      </span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <div className="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
              <ul className="divide-y divide-line">
                {list.map((m) => (
                  <li key={m.id}>
                    <button type="button" onClick={() => setOpen(m)} className="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-bg">
                      <span className="h-12 w-16 shrink-0 overflow-hidden rounded-lg border border-line bg-bg">
                        <MediaThumb m={m} />
                      </span>
                      <span className="min-w-0 flex-1">
                        <b className="block truncate text-sm">{m.title}</b>
                        <span className="block truncate text-xs text-muted">{m.alt_text ?? m.original_name}</span>
                      </span>
                      <span className="hidden w-28 text-xs text-muted sm:block">{m.folder ?? "Sin carpeta"}</span>
                      <span className="w-24 text-right text-xs tabular-nums text-muted">{bytes(m.size_bytes)}</span>
                      <span className="w-20 text-right">{m.usage_count > 0 ? <Chip tone="brand">En uso</Chip> : <span className="text-xs text-muted">Sin usar</span>}</span>
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </section>
      </div>

      {uploading && (
        <Modal title="Subir a la biblioteca" icon="plus" onClose={() => setUploading(false)}>
          <MediaUploadForm
            folders={folders}
            defaultFolder={folder ?? undefined}
            onCancel={() => setUploading(false)}
            onDone={(m) => {
              setUploading(false);
              setToast({ text: `«${m.title}» quedó en la biblioteca.`, tone: "ok" });
              load();
            }}
          />
        </Modal>
      )}
      {open && (
        <MediaDetail
          m={open}
          folders={folders}
          canDelete={me.platform_role !== "support"}
          onClose={() => setOpen(null)}
          onChanged={(t, closeIt) => {
            setToast({ text: t, tone: "ok" });
            if (closeIt) setOpen(null);
            load();
          }}
        />
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function MediaDetail({ m, folders, canDelete, onClose, onChanged }: { m: Media; folders: string[]; canDelete: boolean; onClose: () => void; onChanged: (t: string, close?: boolean) => void }) {
  const [f, setF] = useState({ title: m.title, alt_text: m.alt_text ?? "", folder: m.folder ?? "", tags: m.tags.join(", "), is_example: m.is_example });
  const [usages, setUsages] = useState<{ count: number; items: Usage[] } | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState<string | null>(null);
  const [current, setCurrent] = useState(m);
  const replaceRef = useRef<HTMLInputElement>(null);
  const url = assetUrl(current.file_id) ?? "";

  useEffect(() => {
    api<{ data: { count: number; items: Usage[] } }>(`/internal/site/media/${m.id}/usages`).then((r) => setUsages(r.data));
  }, [m.id]);

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy("save");
    setErrors({});
    try {
      await api(`/internal/site/media/${m.id}`, {
        method: "PATCH",
        body: { title: f.title, alt_text: f.alt_text || null, folder: f.folder || null, tags: f.tags.split(",").map((t) => t.trim()).filter(Boolean), is_example: f.is_example },
      });
      onChanged("Datos guardados.", true);
    } catch (x) {
      if (x instanceof ApiError) setErrors(x.errors ?? {});
      setBusy(null);
    }
  }

  async function replace(file: File) {
    setBusy("replace");
    setErrors({});
    const fd = new FormData();
    fd.append("file", file);
    try {
      setCurrent(await sendMedia(`/internal/site/media/${m.id}/replace`, fd));
      onChanged("Archivo reemplazado. Donde se usaba, ya se ve el nuevo.");
    } catch (x) {
      setErrors((x as { errors?: Record<string, string[]> }).errors ?? { file: [(x as Error).message] });
    } finally {
      setBusy(null);
    }
  }

  async function remove() {
    if (!window.confirm(`¿Eliminar «${m.title}» de la biblioteca?`)) return;
    setBusy("delete");
    try {
      await api(`/internal/site/media/${m.id}`, { method: "DELETE" });
      onChanged("Archivo eliminado.", true);
    } catch (x) {
      setErrors({ file: [x instanceof ApiError ? (x.detail ?? x.message) : "No se pudo eliminar."] });
      setBusy(null);
    }
  }

  return (
    <Modal
      title={m.title}
      icon={KIND_LABEL[m.kind][1]}
      onClose={onClose}
      footer={
        <>
          {canDelete && (
            <Button variant="danger-ghost" icon="close" loading={busy === "delete"} disabled={(usages?.count ?? 0) > 0} title={(usages?.count ?? 0) > 0 ? "Se usa en el sitio: quítalo de ahí primero" : undefined} onClick={remove}>
              Eliminar
            </Button>
          )}
          <span className="mr-auto" />
          <Button onClick={onClose}>Cerrar</Button>
          <Button variant="primary" type="submit" form="media-meta" icon="check" loading={busy === "save"}>
            Guardar
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div className="space-y-3">
          <div className="overflow-hidden rounded-xl border border-line bg-bg">
            {current.kind === "video" ? (
              <video src={url} controls className="max-h-64 w-full" />
            ) : current.kind === "document" ? (
              <a href={url} target="_blank" rel="noopener" className="flex h-48 items-center justify-center gap-2 font-semibold text-brand hover:underline">
                <Icon name="file" size={20} /> Abrir el PDF
              </a>
            ) : (
              <span className="block aspect-[4/3]">
                <MediaThumb m={current} />
              </span>
            )}
          </div>
          <dl className="grid grid-cols-[max-content_1fr] gap-x-3 gap-y-1 text-xs">
            <dt className="text-muted">Archivo</dt>
            <dd className="truncate">{current.original_name}</dd>
            <dt className="text-muted">Tipo</dt>
            <dd>
              {KIND_LABEL[current.kind][0]} · {current.mime_type}
            </dd>
            {current.width && (
              <>
                <dt className="text-muted">Tamaño</dt>
                <dd>
                  {current.width} × {current.height} px · {bytes(current.size_bytes)}
                </dd>
              </>
            )}
            <dt className="text-muted">Dirección</dt>
            <dd className="truncate">
              <button type="button" onClick={() => navigator.clipboard?.writeText(url)} className="text-brand hover:underline" title={url}>
                Copiar enlace
              </button>
            </dd>
          </dl>
          <input ref={replaceRef} type="file" className="hidden" onChange={(e) => e.target.files?.[0] && replace(e.target.files[0])} />
          <Button size="sm" icon="refresh" loading={busy === "replace"} onClick={() => replaceRef.current?.click()}>
            Reemplazar archivo
          </Button>
          <p className="text-xs text-muted">Reemplazar conserva el registro: donde se usaba, se ve el archivo nuevo. Debe ser del mismo tipo.</p>
          {errors.file && <p className="text-xs font-medium text-bad">● {errors.file[0]}</p>}

          <div className="rounded-xl border border-line p-3">
            <p className="flex items-center gap-1.5 text-sm font-semibold">
              <Icon name="globe" size={14} className="text-brand" /> Dónde se usa
            </p>
            {usages === null ? (
              <p className="mt-1 text-xs text-muted">Revisando…</p>
            ) : usages.items.length === 0 ? (
              <p className="mt-1 text-xs text-muted">En ningún lado. Se puede eliminar.</p>
            ) : (
              <ul className="mt-1.5 space-y-1 text-xs">
                {usages.items.map((u, i) => (
                  <li key={i}>
                    {u.href ? (
                      <Link href={u.href} className="text-brand hover:underline">
                        {u.label}
                        {u.name ? `: ${u.name}` : ""}
                      </Link>
                    ) : (
                      <span>
                        {u.label}
                        {u.name ? `: ${u.name}` : ""}
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>

        <form id="media-meta" onSubmit={save} className="space-y-3" noValidate>
          <FormField label="Título" error={errors.title?.[0]}>
            <input className="input" maxLength={120} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} />
          </FormField>
          {(m.kind === "image" || m.kind === "icon") && (
            <FormField label="Texto alternativo" error={errors.alt_text?.[0]} hint="Lo leen los lectores de pantalla y Google.">
              <textarea className="input min-h-20" maxLength={250} value={f.alt_text} onChange={(e) => setF({ ...f, alt_text: e.target.value })} />
            </FormField>
          )}
          <FormField label="Carpeta" error={errors.folder?.[0]}>
            <input className="input" list="media-folders-edit" maxLength={60} value={f.folder} onChange={(e) => setF({ ...f, folder: e.target.value })} />
            <datalist id="media-folders-edit">
              {folders.map((x) => (
                <option key={x} value={x} />
              ))}
            </datalist>
          </FormField>
          <FormField label="Etiquetas" hint="Separadas por comas.">
            <input className="input" maxLength={200} value={f.tags} onChange={(e) => setF({ ...f, tags: e.target.value })} />
          </FormField>
          <SwitchRow label="Es un ejemplo" hint="Ilustración o captura con datos ficticios." checked={f.is_example} onChange={(v) => setF({ ...f, is_example: v })} />
        </form>
      </div>
    </Modal>
  );
}
