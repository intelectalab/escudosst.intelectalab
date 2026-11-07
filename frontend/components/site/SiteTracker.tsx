"use client";

// Registra visitas y clics importantes del sitio público (solo con consentimiento analítico):
//  - una visita por página;
//  - clics en WhatsApp (wa.me), teléfono (tel:) y en cualquier botón o enlace con data-track="nombre".

import { usePathname } from "next/navigation";
import { useEffect } from "react";
import { rememberUtm, track } from "@/lib/track";

export function SiteTracker() {
  const pathname = usePathname();

  useEffect(() => {
    rememberUtm();
    track("page_view", undefined, pathname);
  }, [pathname]);

  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      const el = (e.target as HTMLElement | null)?.closest<HTMLElement>("a, button");
      if (!el) return;
      const href = el instanceof HTMLAnchorElement ? el.href : "";
      if (href.includes("wa.me/") || href.includes("api.whatsapp.com")) return track("whatsapp_click", el.dataset.track ?? "whatsapp");
      if (href.startsWith("tel:")) return track("phone_click", el.dataset.track ?? "telefono");
      if (el.dataset.track) track("cta_click", el.dataset.track);
    };
    document.addEventListener("click", onClick, { capture: true });
    return () => document.removeEventListener("click", onClick, { capture: true });
  }, []);

  return null;
}
