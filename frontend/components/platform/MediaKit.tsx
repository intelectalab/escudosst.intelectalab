"use client";

// Piezas de la biblioteca de medios (SCR-WEB-65/66): tipos, vista en miniatura, formulario de carga y el selector que
// se abre desde cualquier editor (por ahora, la galería de los casos de éxito).

import { FormEvent, useCallback, useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { api } from "@/lib/api";
import { session } from "@/lib/session";
import { assetUrl } from "@/lib/site";

export type MediaKind = "image" | "icon" | "document" | "video";
export type Media = {
  id: string;
  file_id: string;
  kind: MediaKind;
  title: string;
  alt_text: string | null;
  width: number | null;
  height: number | null;
  size_bytes: number | null;
  folder: string | null;
  tags: string[];
  is_example: boolean;
  usage_count: number;
  mime_type: string;
  original_name: string | null;
  created_at: string;
};

export const KIND_LABEL: Record<MediaKind, [string, string]> = {
  image: ["Imagen", "eye"],
  icon: ["Ícono", "star"],
  document: ["Documento", "file"],
  video: ["Video", "play"],
};

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export const bytes = (b: number | null) => (b == null ? "–" : b === 0 ? "0 KB" : b > 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

/** Miniatura: la imagen real o un ícono según el tipo. */
export function MediaThumb({ m, className = "" }: { m: Media; className?: string }) {
  if (m.kind === "image" || m.kind === "icon") {
    // eslint-disable-next-line @next/next/no-img-element
    return <img src={assetUrl(m.file_id) ?? ""} alt={m.alt_text ?? ""} loading="lazy" className={`h-full w-full ${m.kind === "icon" ? "object-contain p-4" : "object-cover"} ${className}`} />;
  }
  return (
    <span className={`grid h-full w-full place-items-center bg-gradient-to-br ${m.kind === "video" ? "from-side to-brand text-white" : "from-bg to-brand-soft text-brand-strong"} ${className}`}>
      <span className="flex flex-col items-center gap-1 text-xs font-semibold">
        <Icon name={KIND_LABEL[m.kind][1]} size={26} />
        {m.mime_type.split("/")[1]?.toUpperCase()}
      </span>
    </span>
  );
}

/** Subir un archivo (multipart). Devuelve el medio creado o lanza con los errores por campo. */
export async function sendMedia(path: string, fd: FormData): Promise<Media> {
  const res = await fetch(`${API_URL}/api/v1${path}`, { method: "POST", headers: { Accept: "application/json", Authorization: `Bearer ${session.getToken()}` }, body: fd });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw Object.assign(new Error(json.detail ?? "No se pudo subir."), { errors: json.errors ?? {} });
  return json.data as Media;
}

/** Formulario para subir un archivo nuevo a la biblioteca. */
export function MediaUploadForm({ folders, defaultFolder, onDone, onCancel, imagesOnly = false }: { folders: string[]; defaultFolder?: string; onDone: (m: Media) => void; onCancel: () => void; imagesOnly?: boolean }) {
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [f, setF] = useState({ title: "", alt_text: "", folder: defaultFolder ?? "", tags: "", is_example: false, as_icon: false });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const isImage = !!file && file.type.startsWith("image/");

  useEffect(() => {
    if (!file || !file.type.startsWith("image/")) return setPreview(null);
    const u = URL.createObjectURL(file);
    setPreview(u);
    return () => URL.revokeObjectURL(u);
  }, [file]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!file) return setErrors({ file: ["Elige un archivo."] });
    setSaving(true);
    setErrors({});
    const fd = new FormData();
    fd.append("file", file);
    fd.append("title", f.title);
    if (f.alt_text) fd.append("alt_text", f.alt_text);
    if (f.folder) fd.append("folder", f.folder);
    f.tags
      .split(",")
      .map((t) => t.trim())
      .filter(Boolean)
      .forEach((t) => fd.append("tags[]", t));
    fd.append("is_example", f.is_example ? "1" : "0");
    if (f.as_icon) fd.append("as_icon", "1");
    try {
      onDone(await sendMedia("/internal/site/media", fd));
    } catch (x) {
      setErrors((x as { errors?: Record<string, string[]> }).errors ?? { file: [(x as Error).message] });
      setSaving(false);
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      <label className={`flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed p-5 text-center transition-colors ${errors.file ? "border-bad" : "border-line hover:border-brand"}`}>
        {preview ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={preview} alt="" className="max-h-40 rounded-lg object-contain" />
        ) : (
          <span className="grid h-12 w-12 place-items-center rounded-full bg-brand-soft text-brand-strong">
            <Icon name="plus" size={22} />
          </span>
        )}
        <span className="text-sm font-semibold">{file ? file.name : "Elige o arrastra un archivo"}</span>
        <span className="text-xs text-muted">{imagesOnly ? "PNG, JPG, WEBP o AVIF, hasta 5 MB." : "Imágenes PNG/JPG/WEBP/AVIF (5 MB), PDF (15 MB) o video MP4/WEBM (50 MB)."}</span>
        <input
          type="file"
          className="sr-only"
          accept={imagesOnly ? "image/png,image/jpeg,image/webp,image/avif" : "image/png,image/jpeg,image/webp,image/avif,application/pdf,video/mp4,video/webm"}
          onChange={(e) => {
            const x = e.target.files?.[0] ?? null;
            setFile(x);
            if (x && !f.title) setF((p) => ({ ...p, title: x.name.replace(/\.[^.]+$/, "").replace(/[-_]+/g, " ") }));
          }}
        />
      </label>
      {errors.file && <p className="text-xs font-medium text-bad">● {errors.file[0]}</p>}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="Título" error={errors.title?.[0]}>
          <input className="input" maxLength={120} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} />
        </FormField>
        <FormField label="Carpeta (opcional)" error={errors.folder?.[0]}>
          <input className="input" list="media-folders" maxLength={60} value={f.folder} onChange={(e) => setF({ ...f, folder: e.target.value })} placeholder="Casos, Portada…" />
          <datalist id="media-folders">
            {folders.map((x) => (
              <option key={x} value={x} />
            ))}
          </datalist>
        </FormField>
      </div>
      {(isImage || !file) && (
        <FormField label="Texto alternativo" error={errors.alt_text?.[0]} hint="Describe lo que se ve, como si se lo contaras a alguien que no puede verla. Es obligatorio en imágenes.">
          <input className="input" maxLength={250} value={f.alt_text} onChange={(e) => setF({ ...f, alt_text: e.target.value })} placeholder="Supervisor revisando un permiso de trabajo en altura" />
        </FormField>
      )}
      <FormField label="Etiquetas (opcional)" hint="Separadas por comas: obra, EPP, capacitación">
        <input className="input" maxLength={200} value={f.tags} onChange={(e) => setF({ ...f, tags: e.target.value })} />
      </FormField>
      <SwitchRow label="Es un ejemplo" hint="Ilustración o captura con datos ficticios." checked={f.is_example} onChange={(v) => setF({ ...f, is_example: v })} />
      {isImage && !imagesOnly && <SwitchRow label="Guardar como ícono" hint="Para íconos o logos pequeños (PNG o WEBP)." checked={f.as_icon} onChange={(v) => setF({ ...f, as_icon: v })} />}
      <div className="flex justify-end gap-2">
        <Button type="button" onClick={onCancel}>
          Cancelar
        </Button>
        <Button type="submit" variant="primary" icon="check" loading={saving}>
          Subir
        </Button>
      </div>
    </form>
  );
}

/**
 * Selector de medios (SCR-WEB-66): buscar, filtrar y elegir uno o varios archivos de la biblioteca, o subir uno nuevo
 * sin salir del editor.
 */
export function MediaPicker({ onClose, onPick, kind, multiple = false, exclude = [] }: { onClose: () => void; onPick: (m: Media[]) => void; kind?: MediaKind; multiple?: boolean; exclude?: string[] }) {
  const [rows, setRows] = useState<Media[] | null>(null);
  const [folders, setFolders] = useState<string[]>([]);
  const [q, setQ] = useState("");
  const [folder, setFolder] = useState<string | null>(null);
  const [picked, setPicked] = useState<Media[]>([]);
  const [uploading, setUploading] = useState(false);
  const timer = useRef<number | undefined>(undefined);

  const load = useCallback(() => {
    const p = new URLSearchParams();
    if (kind) p.set("kind", kind);
    if (folder !== null) p.set("folder", folder);
    if (q) p.set("q", q);
    api<{ data: Media[]; folders: string[] }>(`/internal/site/media?${p}`).then((r) => {
      setRows(r.data.filter((m) => !exclude.includes(m.id)));
      setFolders(r.folders);
    });
    // exclude es estable mientras el selector está abierto
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [kind, folder, q]);

  useEffect(() => {
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(load, 200);
    return () => window.clearTimeout(timer.current);
  }, [load]);

  const toggle = (m: Media) => setPicked((p) => (p.some((x) => x.id === m.id) ? p.filter((x) => x.id !== m.id) : multiple ? [...p, m] : [m]));

  return (
    <Modal
      title={uploading ? "Subir a la biblioteca" : "Elegir de la biblioteca de medios"}
      icon="eye"
      onClose={onClose}
      footer={
        !uploading && (
          <>
            <span className="mr-auto text-sm text-muted">{picked.length ? `${picked.length} elegido${picked.length === 1 ? "" : "s"}` : ""}</span>
            <Button onClick={onClose}>Cancelar</Button>
            <Button variant="primary" icon="check" disabled={!picked.length} onClick={() => onPick(picked)}>
              {multiple ? "Agregar" : "Elegir"}
            </Button>
          </>
        )
      }
    >
      {uploading ? (
        <MediaUploadForm
          folders={folders}
          imagesOnly={kind === "image"}
          onCancel={() => setUploading(false)}
          onDone={(m) => {
            setUploading(false);
            setPicked((p) => (multiple ? [...p, m] : [m]));
            load();
          }}
        />
      ) : (
        <div className="space-y-3">
          <div className="flex flex-wrap gap-2">
            <span className="relative min-w-[12rem] flex-1">
              <Icon name="search" size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
              <input className="input pl-9" placeholder="Buscar por título, descripción o etiqueta" value={q} onChange={(e) => setQ(e.target.value)} />
            </span>
            <select className="input w-44" value={folder ?? "__all"} onChange={(e) => setFolder(e.target.value === "__all" ? null : e.target.value)} aria-label="Carpeta">
              <option value="__all">Todas las carpetas</option>
              <option value="">Sin carpeta</option>
              {folders.map((x) => (
                <option key={x} value={x}>
                  {x}
                </option>
              ))}
            </select>
            <Button icon="plus" onClick={() => setUploading(true)}>
              Subir nuevo
            </Button>
          </div>
          {rows === null ? (
            <div className="h-48 animate-pulse rounded-xl bg-line/40" />
          ) : rows.length === 0 ? (
            <p className="rounded-xl border border-dashed border-line p-8 text-center text-sm text-muted">No hay archivos aquí. Usa «Subir nuevo».</p>
          ) : (
            <ul className="grid max-h-[55vh] grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-3 lg:grid-cols-4">
              {rows.map((m) => {
                const on = picked.some((x) => x.id === m.id);
                return (
                  <li key={m.id}>
                    <button type="button" onClick={() => toggle(m)} aria-pressed={on} className={`group relative block w-full overflow-hidden rounded-xl border text-left transition-all ${on ? "border-brand ring-2 ring-brand" : "border-line hover:border-brand/50"}`}>
                      <span className="block aspect-[4/3] bg-bg">
                        <MediaThumb m={m} />
                      </span>
                      <span className="block truncate px-2 py-1.5 text-xs font-semibold">{m.title}</span>
                      {on && (
                        <span className="absolute right-1.5 top-1.5 grid h-6 w-6 place-items-center rounded-full bg-brand text-white shadow">
                          <Icon name="check" size={14} />
                        </span>
                      )}
                      {m.is_example && (
                        <span className="absolute left-1.5 top-1.5">
                          <Chip tone="amber">Ejemplo</Chip>
                        </span>
                      )}
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      )}
    </Modal>
  );
}
