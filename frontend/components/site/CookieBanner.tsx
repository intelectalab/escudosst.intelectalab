"use client";

// Banner y centro de preferencias de cookies (SCR-WEB-16).
// - «Aceptar todas», «Rechazar no necesarias» y «Configurar» con el mismo peso visual (sin empujar a aceptar).
// - Las necesarias siempre activas; el resto apagado hasta que la persona decida.
// - La elección se guarda en el navegador y en la base (prueba del consentimiento); se reabre desde el pie.
// - Mientras el banner está abierto, el botón de WhatsApp se oculta para no taparlo.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Icon } from "@/components/ui/Icon";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
const STORE = "escudo.cookies";
const VISITOR = "escudo.visitor";
export const OPEN_COOKIES_EVENT = "escudo:open-cookies";

type Cats = { preferences: boolean; analytics: boolean; marketing: boolean };
type Saved = { categories: Cats; at: string };

const CATEGORIES: [keyof Cats | "necessary", string, string][] = [
  ["necessary", "Necesarias", "Para que el sitio funcione. Siempre activas."],
  ["preferences", "Preferencias", "Recuerdan tus opciones."],
  ["analytics", "Estadísticas", "Cuentan visitas para mejorar el sitio."],
  ["marketing", "Marketing", "Miden anuncios y permiten videos de YouTube."],
];

/** Preferencia guardada (o null si aún no decidió). Úsala antes de cargar analítica, píxeles o videos de terceros. */
export function cookieConsent(): Saved | null {
  try {
    return JSON.parse(localStorage.getItem(STORE) ?? "null");
  } catch {
    return null;
  }
}

function visitorId(): string {
  let id = localStorage.getItem(VISITOR);
  if (!id) {
    id = crypto.randomUUID();
    localStorage.setItem(VISITOR, id);
  }
  return id;
}

export function CookieBanner() {
  const [open, setOpen] = useState(false);
  const [config, setConfig] = useState(false);
  const [cats, setCats] = useState<Cats>({ preferences: false, analytics: false, marketing: false });

  useEffect(() => {
    const saved = cookieConsent();
    if (saved) setCats(saved.categories);
    else setOpen(true);
    const reopen = () => {
      setCats(cookieConsent()?.categories ?? { preferences: false, analytics: false, marketing: false });
      setConfig(true);
      setOpen(true);
    };
    window.addEventListener(OPEN_COOKIES_EVENT, reopen);
    return () => window.removeEventListener(OPEN_COOKIES_EVENT, reopen);
  }, []);

  useEffect(() => {
    document.documentElement.dataset.cookieBanner = open ? "open" : "closed";
  }, [open]);

  const save = useCallback((action: "accept_all" | "reject_all" | "custom", chosen: Cats) => {
    const categories = action === "accept_all" ? { preferences: true, analytics: true, marketing: true } : action === "reject_all" ? { preferences: false, analytics: false, marketing: false } : chosen;
    localStorage.setItem(STORE, JSON.stringify({ categories, at: new Date().toISOString() } satisfies Saved));
    setCats(categories);
    setOpen(false);
    setConfig(false);
    // Prueba del consentimiento en la base; si falla la red, la elección igual se respeta en el navegador.
    fetch(`${API_URL}/api/v1/public/cookie-consent`, {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify({ visitor_id: visitorId(), action, categories }),
    }).catch(() => undefined);
  }, []);

  if (!open) return null;

  const btn = "site-btn site-btn-outline flex-1 !px-4 text-sm";
  return (
    <div role="dialog" aria-modal="false" aria-labelledby="cookies-title" className="fixed inset-x-3 bottom-3 z-50 sm:inset-x-auto sm:bottom-5 sm:left-5 sm:w-[30rem]">
      <div className="max-h-[85vh] overflow-y-auto rounded-2xl border border-[var(--site-border)] bg-white p-5 shadow-[0_18px_50px_-12px_rgba(13,59,110,.35)]">
        <div className="flex items-start gap-3">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[var(--site-surface)] text-[var(--site-action)]">
            <Icon name="shield" size={20} />
          </span>
          <div>
            <h2 id="cookies-title" className="font-display text-lg font-bold text-[var(--site-navy-title)]">
              {config ? "Elige qué cookies aceptas" : "Usamos cookies"}
            </h2>
            <p className="mt-1 text-sm leading-relaxed text-[var(--site-text-2)]">
              Solo usamos las necesarias, salvo que aceptes las demás.{" "}
              <Link href="/legal/politica-de-cookies" className="font-semibold text-[var(--site-action)] underline">
                Política de cookies
              </Link>
            </p>
          </div>
        </div>

        {config && (
          <ul className="mt-4 space-y-2">
            {CATEGORIES.map(([key, title, text]) => {
              const on = key === "necessary" ? true : cats[key];
              return (
                <li key={key} className="flex items-center justify-between gap-3 rounded-xl border border-[var(--site-border)] bg-[var(--site-surface)] px-3 py-2.5">
                  <span>
                    <b className="block text-sm text-[var(--site-navy-title)]">{title}</b>
                    <span className="text-xs leading-snug text-[var(--site-text-2)]">{text}</span>
                  </span>
                  <button
                    type="button"
                    role="switch"
                    aria-checked={on}
                    aria-label={title}
                    disabled={key === "necessary"}
                    onClick={() => key !== "necessary" && setCats((c) => ({ ...c, [key]: !c[key] }))}
                    className={`relative h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-60 ${on ? "bg-[var(--site-action)]" : "bg-[#c3ccd8]"}`}
                  >
                    <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${on ? "left-[1.375rem]" : "left-0.5"}`} />
                  </button>
                </li>
              );
            })}
          </ul>
        )}

        <div className="mt-4 flex flex-col gap-2 sm:flex-row">
          <button type="button" className={btn} onClick={() => save("accept_all", cats)}>
            Aceptar todas
          </button>
          <button type="button" className={btn} onClick={() => save("reject_all", cats)}>
            Rechazar no necesarias
          </button>
          {config ? (
            <button type="button" className={btn} onClick={() => save("custom", cats)}>
              Guardar
            </button>
          ) : (
            <button type="button" className={btn} onClick={() => setConfig(true)}>
              Configurar
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

/** Enlace del pie para volver a abrir las preferencias. */
export function CookiePrefsLink() {
  return (
    <button type="button" onClick={() => window.dispatchEvent(new Event(OPEN_COOKIES_EVENT))} className="text-left text-white/85 hover:text-white hover:underline">
      Preferencias de cookies
    </button>
  );
}
