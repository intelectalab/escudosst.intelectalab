"use client";

// Core Web Vitals del sitio público (RN-WEB-10): LCP, INP y CLS medidos en el navegador de visitantes reales. Solo se envían si
// la persona aceptó las cookies analíticas (RN-WEB-65) y sin nada que la identifique: la ruta de la página y los valores.
// Se juntan las tres métricas de la página y se mandan una vez al ocultarla (cambio de pestaña o salida).

import { usePathname } from "next/navigation";
import { useReportWebVitals } from "next/web-vitals";
import { useEffect, useRef } from "react";
import { cookieConsent } from "@/components/site/CookieBanner";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
type Name = "LCP" | "INP" | "CLS";

export function WebVitals() {
  const pathname = usePathname();
  const pending = useRef<{ path: string; values: Partial<Record<Name, number>> }>({ path: pathname, values: {} });

  const flush = () => {
    const p = pending.current;
    const metrics = (Object.entries(p.values) as [Name, number][]).map(([name, value]) => ({ name, value }));
    pending.current = { path: p.path, values: {} };
    if (!metrics.length || typeof window === "undefined" || !cookieConsent()?.categories.analytics) return;
    try {
      fetch(`${API}/api/v1/public/web-vitals`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ path: p.path.slice(0, 300), metrics }),
        keepalive: true,
      }).catch(() => {});
    } catch {
      /* las métricas nunca rompen el sitio */
    }
  };

  useReportWebVitals((m: { name: string; value: number }) => {
    const name = m.name as Name;
    if (name === "LCP" || name === "INP" || name === "CLS") pending.current.values[name] = m.value;
  });

  // Al cambiar de página se envía lo medido en la anterior.
  useEffect(() => {
    if (pending.current.path !== pathname) {
      flush();
      pending.current = { path: pathname, values: {} };
    }
  }, [pathname]);

  useEffect(() => {
    const onHide = () => {
      if (document.visibilityState === "hidden") flush();
    };
    document.addEventListener("visibilitychange", onHide);
    window.addEventListener("pagehide", flush);
    return () => {
      document.removeEventListener("visibilitychange", onHide);
      window.removeEventListener("pagehide", flush);
    };
  }, []);

  return null;
}
