// Métricas propias del sitio (RN-WEB-65): solo se envían si la persona aceptó las cookies analíticas.
// No se guarda nada que identifique a la persona: la API arma un hash con una sal que cambia cada día.

import { cookieConsent } from "@/components/site/CookieBanner";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
const UTM_KEY = "escudo.utm";

export type TrackType =
  | "page_view"
  | "cta_click"
  | "form_view"
  | "form_submit"
  | "whatsapp_click"
  | "phone_click"
  | "demo_booked"
  | "calculator_used"
  | "assessment_completed"
  | "resource_download"
  | "chatbot_open"
  | "chatbot_lead";

type Utm = { utm_source?: string; utm_medium?: string; utm_campaign?: string; referrer?: string };

/** UTM y origen de la visita: se toman al entrar y se mantienen en la pestaña (sessionStorage). */
export function rememberUtm(): Utm {
  if (typeof window === "undefined") return {};
  try {
    const saved = sessionStorage.getItem(UTM_KEY);
    if (saved) return JSON.parse(saved) as Utm;
    const q = new URLSearchParams(window.location.search);
    const u: Utm = {};
    for (const k of ["utm_source", "utm_medium", "utm_campaign"] as const) {
      const v = q.get(k)?.slice(0, 100);
      if (v) u[k] = v;
    }
    if (document.referrer) u.referrer = document.referrer.slice(0, 500);
    sessionStorage.setItem(UTM_KEY, JSON.stringify(u));
    return u;
  } catch {
    return {};
  }
}

export function track(type: TrackType, key?: string, path?: string) {
  if (typeof window === "undefined" || !cookieConsent()?.categories.analytics) return;
  const body = JSON.stringify({ type, key: key?.slice(0, 80), path: (path ?? window.location.pathname).slice(0, 300), ...rememberUtm() });
  try {
    fetch(`${API}/api/v1/public/events`, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body, keepalive: true }).catch(() => {});
  } catch {
    /* las métricas nunca rompen el sitio */
  }
}
