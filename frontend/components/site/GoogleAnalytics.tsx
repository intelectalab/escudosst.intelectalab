"use client";

// Google Analytics 4 solo con consentimiento analítico (aviso de cookies, Ley 29733). Sin consentimiento no se carga
// nada de Google. Si la persona acepta después en el banner, se carga en ese momento.

import { useEffect } from "react";
import { cookieConsent } from "@/components/site/CookieBanner";

declare global {
  interface Window {
    dataLayer?: unknown[];
  }
}

export function GoogleAnalytics({ id }: { id: string | null }) {
  useEffect(() => {
    if (!id || !/^G-[A-Z0-9]+$/.test(id)) return;
    let loaded = false;
    const load = () => {
      if (loaded || !cookieConsent()?.categories.analytics) return;
      loaded = true;
      const s = document.createElement("script");
      s.async = true;
      s.src = `https://www.googletagmanager.com/gtag/js?id=${id}`;
      document.head.appendChild(s);
      window.dataLayer = window.dataLayer || [];
      // gtag necesita el objeto «arguments» tal cual (así lo pide Google).
      // eslint-disable-next-line @typescript-eslint/no-unused-vars
      function gtag(...args: unknown[]) {
        // eslint-disable-next-line prefer-rest-params
        window.dataLayer!.push(arguments);
      }
      gtag("js", new Date());
      gtag("config", id, { anonymize_ip: true });
    };
    load();
    const t = window.setInterval(load, 2000);   // por si acepta las cookies en esta misma visita
    window.addEventListener("storage", load);
    return () => {
      window.clearInterval(t);
      window.removeEventListener("storage", load);
    };
  }, [id]);
  return null;
}
