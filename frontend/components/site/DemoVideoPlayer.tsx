"use client";

// Reproductor de la sección «Demostración» de la portada. El video lo elige el dueño en Sitio web › Video de la portada:
//  - subido a la Biblioteca de medios (MP4/WEBM): se reproduce aquí mismo con los controles del navegador;
//  - enlace de YouTube o Vimeo: se usa VideoBlock, que respeta las cookies de marketing.
// Sin video, el reproductor se ve igual pero al tocarlo no pasa nada (ni error, ni otra página, ni un recuadro vacío).

import { useState } from "react";
import { VideoBlock } from "@/components/site/VideoBlock";
import { assetUrl, type HomeVideo } from "@/lib/site";

export function DemoVideoPlayer({ video }: { video: HomeVideo | null }) {
  const [playing, setPlaying] = useState(false);
  const src = video?.provider === "upload" ? assetUrl(video.file_id) : null;
  const ready = !!video && (video.provider === "upload" ? !!src : !!video.url);

  if (playing && video && ready) {
    if (src) {
      return (
        <div className="overflow-hidden rounded-3xl bg-black shadow-2xl ring-1 ring-black/10">
          <video src={src} controls autoPlay playsInline preload="metadata" className="aspect-video w-full bg-black" aria-label="Recorrido del producto EscudoSST" />
        </div>
      );
    }
    return <VideoBlock url={video.url!} title="Recorrido del producto EscudoSST" />;
  }

  return (
    <div className="space-y-2">
      <div className="relative aspect-video w-full overflow-hidden rounded-3xl bg-gradient-to-br from-[#0d3b6e] via-[#0b3260] to-[#071f3d] shadow-2xl ring-1 ring-black/10">
        {/* Barra superior */}
        <div className="absolute inset-x-0 top-0 flex items-center gap-2 px-4 py-3 sm:px-5">
          <span className="flex gap-1" aria-hidden="true">
            <i className="h-2 w-2 rounded-full bg-white/25" />
            <i className="h-2 w-2 rounded-full bg-white/25" />
            <i className="h-2 w-2 rounded-full bg-white/25" />
          </span>
          <span className="truncate text-[11px] font-semibold tracking-wide text-white/75 sm:text-xs">EscudoSST · Recorrido del producto</span>
        </div>

        {/* Bloques que imitan la pantalla del sistema */}
        <div className="absolute inset-x-4 bottom-12 top-11 flex gap-3 sm:inset-x-5 sm:bottom-14" aria-hidden="true">
          <div className="hidden w-[18%] flex-col gap-2 rounded-xl bg-white/[0.06] p-2.5 sm:flex">
            <i className="h-2.5 w-3/4 rounded bg-white/25" />
            {[0, 1, 2, 3, 4].map((k) => (
              <i key={k} className="h-2 rounded bg-white/10" style={{ width: `${85 - k * 9}%` }} />
            ))}
          </div>
          <div className="flex flex-1 flex-col gap-3">
            <div className="grid grid-cols-3 gap-2 sm:gap-3">
              {["bg-[#F4B90F]/70", "bg-[#3fb27f]/70", "bg-[#1b6fb7]/80"].map((c) => (
                <div key={c} className="space-y-1.5 rounded-xl bg-white/[0.08] p-2 sm:p-2.5">
                  <i className={`block h-1.5 w-1/2 rounded ${c}`} />
                  <i className="block h-3 w-3/4 rounded bg-white/20 sm:h-4" />
                </div>
              ))}
            </div>
            <div className="flex flex-1 items-end gap-1.5 rounded-xl bg-white/[0.06] p-3 sm:gap-2">
              {[45, 70, 55, 85, 60, 92, 74, 66].map((h, k) => (
                <i key={k} className="flex-1 rounded-t bg-white/15" style={{ height: `${h}%` }} />
              ))}
            </div>
          </div>
        </div>

        {/* Botón de reproducir */}
        <button
          type="button"
          onClick={() => ready && setPlaying(true)}
          aria-label={ready ? "Reproducir el recorrido del producto" : "El recorrido en video estará disponible muy pronto"}
          aria-disabled={!ready}
          className={`group absolute inset-0 grid place-items-center ${ready ? "cursor-pointer" : "cursor-default"}`}
        >
          <span
            className={`grid h-16 w-16 place-items-center rounded-full bg-[#F4B90F] text-[#0d3b6e] shadow-[0_10px_30px_rgba(0,0,0,0.35)] ring-8 ring-white/15 transition sm:h-20 sm:w-20 ${
              ready ? "group-hover:scale-105 group-focus-visible:scale-105" : ""
            }`}
          >
            <svg viewBox="0 0 24 24" className="ml-1 h-7 w-7 sm:h-9 sm:w-9" fill="currentColor" aria-hidden="true">
              <path d="M7 4.5v15a1 1 0 0 0 1.5.86l12-7.5a1 1 0 0 0 0-1.72l-12-7.5A1 1 0 0 0 7 4.5z" />
            </svg>
          </span>
        </button>

        {/* Barra de avance */}
        <div className="pointer-events-none absolute inset-x-0 bottom-0 flex items-center gap-3 px-4 pb-3.5 sm:px-5 sm:pb-4" aria-hidden="true">
          <span className="text-[10px] font-semibold tabular-nums text-white/70 sm:text-xs">0:00</span>
          <span className="relative h-1.5 flex-1 overflow-hidden rounded-full bg-white/20">
            <span className="absolute inset-y-0 left-0 w-[8%] rounded-full bg-[#F4B90F]" />
          </span>
        </div>
      </div>
      {!ready && <p className="text-center text-sm text-[var(--site-text-2)]">Muy pronto el recorrido en video</p>}
    </div>
  );
}
