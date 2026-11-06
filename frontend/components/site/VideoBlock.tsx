"use client";

// Video de YouTube o Vimeo (RN-WEB-11): esos servicios ponen cookies, así que el video solo se incrusta si la persona
// aceptó las cookies de marketing. Si no, se ve un recuadro con un enlace para verlo en el sitio del proveedor.

import { useEffect, useState } from "react";
import { cookieConsent, OPEN_COOKIES_EVENT } from "@/components/site/CookieBanner";
import { Icon } from "@/components/ui/Icon";
import { videoEmbed } from "@/lib/pages";

export function VideoBlock({ url, title }: { url: string; title: string }) {
  const [allowed, setAllowed] = useState(false);
  const v = videoEmbed(url);

  useEffect(() => {
    const check = () => setAllowed(!!cookieConsent()?.categories.marketing);
    check();
    window.addEventListener("storage", check);
    const t = window.setInterval(check, 1500);   // por si la persona acepta en el banner de esta misma página
    return () => {
      window.removeEventListener("storage", check);
      window.clearInterval(t);
    };
  }, []);

  if (!v) return null;
  if (allowed) {
    return (
      <div className="aspect-video overflow-hidden rounded-2xl shadow-lg">
        <iframe src={v.embed} title={title} className="h-full w-full" allow="encrypted-media; picture-in-picture; fullscreen" loading="lazy" />
      </div>
    );
  }
  return (
    <div className="grid aspect-video place-items-center rounded-2xl bg-[var(--site-navy)] p-6 text-center text-white">
      <div className="space-y-3">
        <span className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-white/15">
          <Icon name="play" size={24} />
        </span>
        <p className="text-sm text-white/80">Este video es de {v.provider}, que usa cookies de marketing.</p>
        <div className="flex flex-col justify-center gap-2 sm:flex-row">
          <a href={url} target="_blank" rel="noopener noreferrer" className="site-btn site-btn-light !py-2 text-sm">
            Verlo en {v.provider}
          </a>
          <button type="button" onClick={() => window.dispatchEvent(new Event(OPEN_COOKIES_EVENT))} className="site-btn site-btn-ghost-light !py-2 text-sm">
            Cambiar mis cookies
          </button>
        </div>
      </div>
    </div>
  );
}
