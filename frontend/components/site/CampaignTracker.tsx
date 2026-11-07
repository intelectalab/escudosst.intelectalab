"use client";

// Landing de campaña: si la persona llegó sin UTM (por ejemplo, el anuncio no los agregó), se usan los de la
// campaña para que sus formularios y visitas cuenten para ella. Luego registra la visita (si aceptó las métricas).

import { useEffect } from "react";
import { track } from "@/lib/track";

export function CampaignTracker({ utm }: { utm: { utm_source: string | null; utm_medium: string | null; utm_campaign: string | null } }) {
  useEffect(() => {
    try {
      const q = new URLSearchParams(window.location.search);
      if (!q.get("utm_campaign") && !sessionStorage.getItem("escudo.utm") && utm.utm_campaign) {
        const u = Object.fromEntries(Object.entries(utm).filter(([, v]) => v));
        if (document.referrer) Object.assign(u, { referrer: document.referrer.slice(0, 500) });
        sessionStorage.setItem("escudo.utm", JSON.stringify(u));
      }
    } catch {
      /* sin sessionStorage, se mide sin UTM */
    }
    track("campaign_view");
  }, [utm]);
  return null;
}
