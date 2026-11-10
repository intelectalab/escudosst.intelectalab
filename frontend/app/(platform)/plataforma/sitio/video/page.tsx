"use client";

// Sitio web › Video de la portada: el video del reproductor de la sección «Demostración» de la portada.
// Un video de la Biblioteca de medios (MP4/WEBM) o un enlace de YouTube o Vimeo. Sin video, la portada muestra el
// reproductor de muestra, que no hace nada al tocarlo.

import { useCallback, useEffect, useState } from "react";
import { type Media, MediaPicker } from "@/components/platform/MediaKit";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { videoEmbed } from "@/lib/pages";
import { assetUrl, type HomeVideo } from "@/lib/site";

type Draft = { media: Media } | { url: string } | null;

const linkError = (url: string) => (url.trim() && (!/^https:\/\//i.test(url.trim()) || !videoEmbed(url.trim())) ? "Pega un enlace de YouTube o de Vimeo" : null);

export default function VideoPortadaPage() {
  const [saved, setSaved] = useState<HomeVideo | null | undefined>(undefined);
  const [draft, setDraft] = useState<Draft>(null);
  const [url, setUrl] = useState("");
  const [picking, setPicking] = useState(false);
  const [confirmRemove, setConfirmRemove] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: { home_video: HomeVideo | null } }>("/internal/site/settings").then((r) => setSaved(r.data.home_video ?? null));
  }, []);
  useEffect(load, [load]);

  const urlError = linkError(url);
  const canSave = !busy && ((draft && "media" in draft) || (url.trim() !== "" && !urlError));

  async function save() {
    setBusy(true);
    setError(null);
    try {
      const body = draft && "media" in draft ? { media_id: draft.media.id } : { url: url.trim() };
      await api("/internal/site/settings/home-video", { method: "PUT", body });
      setToast({ text: "Guardado. La portada lo mostrará en menos de un minuto.", tone: "ok" });
      setDraft(null);
      setUrl("");
      load();
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.url?.[0] ?? err.errors?.media_id?.[0] ?? err.detail ?? "No se pudo guardar.");
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    setBusy(true);
    try {
      await api("/internal/site/settings/home-video", { method: "DELETE" });
      setToast({ text: "Video quitado. La portada vuelve al reproductor de muestra.", tone: "ok" });
      setConfirmRemove(false);
      load();
    } catch {
      setToast({ text: "No se pudo quitar el video.", tone: "bad" });
    } finally {
      setBusy(false);
    }
  }

  // Lo que se previsualiza: lo elegido sin guardar o, si no hay, lo guardado.
  const preview: { kind: "upload"; src: string; title: string } | { kind: "link"; url: string } | null =
    draft && "media" in draft
      ? { kind: "upload", src: assetUrl(draft.media.file_id)!, title: draft.media.title }
      : url.trim() && !urlError
        ? { kind: "link", url: url.trim() }
        : saved?.provider === "upload" && saved.file_id
          ? { kind: "upload", src: assetUrl(saved.file_id)!, title: saved.title ?? "Video de la biblioteca" }
          : saved?.url
            ? { kind: "link", url: saved.url }
            : null;
  const pending = !!draft || (url.trim() !== "" && !urlError);

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Video de la portada"]]}
        icon="video"
        title="Video de la portada"
        description="El recorrido en video que se ve en la sección «Demostración» de la portada, al lado de «Agenda una demo»."
      />

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_1.2fr]">
        <section className="space-y-5 rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6">
          <div>
            <h2 className="font-display text-lg font-semibold">Elige el video</h2>
            <p className="text-sm text-muted">Sube un MP4 o WEBM a la Biblioteca de medios y elígelo aquí, o pega un enlace de YouTube o de Vimeo.</p>
          </div>

          <div className="space-y-2">
            <Button
              variant="secondary"
              icon="image"
              onClick={() => setPicking(true)}
              disabled={busy}
            >
              Elegir de la Biblioteca de medios
            </Button>
            {draft && "media" in draft && (
              <p className="flex items-center gap-2 text-sm">
                <Icon name="video" size={15} /> <b className="truncate">{draft.media.title}</b>
                <button type="button" className="text-xs font-semibold text-bad hover:underline" onClick={() => setDraft(null)}>
                  Cambiar
                </button>
              </p>
            )}
          </div>

          <div className="flex items-center gap-3 text-xs font-semibold uppercase tracking-wide text-muted">
            <span className="h-px flex-1 bg-line" /> o <span className="h-px flex-1 bg-line" />
          </div>

          <FormField label="Enlace de YouTube o Vimeo" hint="Por ejemplo: https://www.youtube.com/watch?v=… o https://vimeo.com/…" error={urlError ?? undefined}>
            <input
              className="input"
              type="url"
              inputMode="url"
              placeholder="https://www.youtube.com/watch?v=…"
              value={url}
              aria-invalid={!!urlError || undefined}
              onChange={(e) => {
                setUrl(e.target.value);
                if (e.target.value) setDraft(null);
                setError(null);
              }}
            />
          </FormField>

          {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm font-medium text-bad">{error}</p>}

          <div className="flex flex-wrap gap-2">
            <Button variant="primary" icon="check" onClick={save} disabled={!canSave} loading={busy && pending}>
              Guardar
            </Button>
            {pending && (
              <Button variant="ghost" onClick={() => { setDraft(null); setUrl(""); setError(null); }}>
                Descartar
              </Button>
            )}
          </div>
        </section>

        <section className="space-y-4 rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <h2 className="font-display text-lg font-semibold">Vista previa</h2>
              <p className="text-sm text-muted">
                {saved === undefined
                  ? "Cargando…"
                  : pending
                    ? "Así se verá cuando guardes."
                    : saved
                      ? saved.provider === "upload"
                        ? "Video de la biblioteca que se ve hoy en la portada."
                        : `Enlace de ${saved.provider === "youtube" ? "YouTube" : "Vimeo"} que se ve hoy en la portada.`
                      : "La portada no tiene video: muestra el reproductor de muestra y al tocarlo no pasa nada."}
              </p>
            </div>
            {saved && !pending && !confirmRemove && (
              <Button variant="danger-ghost" icon="trash" onClick={() => setConfirmRemove(true)} disabled={busy}>
                Quitar video
              </Button>
            )}
          </div>

          {confirmRemove && (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-bad/30 bg-bad-soft px-4 py-3 text-sm">
              <span>¿Quitar el video? La portada volverá al reproductor de muestra (el archivo sigue en la biblioteca).</span>
              <span className="flex gap-2">
                <Button variant="secondary" size="sm" onClick={() => setConfirmRemove(false)} disabled={busy}>
                  Cancelar
                </Button>
                <Button variant="danger" size="sm" onClick={remove} loading={busy}>
                  Sí, quitar
                </Button>
              </span>
            </div>
          )}

          {preview?.kind === "upload" ? (
            <video key={preview.src} src={preview.src} controls playsInline preload="metadata" className="aspect-video w-full rounded-2xl bg-black" aria-label={preview.title} />
          ) : preview?.kind === "link" ? (
            <div className="aspect-video overflow-hidden rounded-2xl bg-black">
              <iframe key={preview.url} src={videoEmbed(preview.url)!.embed} title="Vista previa del video" className="h-full w-full" allow="encrypted-media; picture-in-picture; fullscreen" />
            </div>
          ) : (
            <div className="grid aspect-video place-items-center rounded-2xl bg-gradient-to-br from-[#0d3b6e] to-[#071f3d] text-center text-white/80">
              <div className="space-y-2">
                <span className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-[#F4B90F] text-[#0d3b6e]">
                  <Icon name="play" size={24} />
                </span>
                <p className="text-sm">Muy pronto el recorrido en video</p>
              </div>
            </div>
          )}
        </section>
      </div>

      {picking && (
        <MediaPicker
          kind="video"
          onClose={() => setPicking(false)}
          onPick={(m) => {
            if (m[0]) {
              setDraft({ media: m[0] });
              setUrl("");
              setError(null);
            }
            setPicking(false);
          }}
        />
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}
