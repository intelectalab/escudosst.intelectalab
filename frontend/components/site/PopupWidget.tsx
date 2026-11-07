"use client";

// Ventana emergente del sitio (SCR-WEB-71): una oferta útil que aparece al intentar salir, al bajar un % de la página
// o tras unos segundos. Diálogo accesible (Esc, clic fuera, «No, gracias», devuelve el foco); en celular es una hoja
// inferior que no tapa más de la mitad. Como máximo las que diga el panel por visita y no se repite antes de N días.
// La API ya excluye el Libro de Reclamaciones, las políticas y los formularios.

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { assetUrl } from "@/lib/site";
import { track } from "@/lib/track";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
type Popup = { id: string; trigger_type: "exit_intent" | "scroll_percent" | "time_on_page"; trigger_value: number | null; title: string; body: string | null; cta_label: string | null; image_file_id: string | null; image_alt_text: string | null; frequency_days: number; href: string };

const SEEN = (id: string) => `escudo.popup.${id}`;
const VISIT = "escudo.popups.visit";

function canShow(p: Popup): boolean {
  try {
    const last = Number(localStorage.getItem(SEEN(p.id)) ?? 0);
    return Date.now() - last > p.frequency_days * 86400000;
  } catch {
    return false;
  }
}

export function PopupWidget() {
  const path = usePathname();
  const [open, setOpen] = useState<Popup | null>(null);
  const dialog = useRef<HTMLDivElement>(null);
  const previous = useRef<HTMLElement | null>(null);

  useEffect(() => {
    if (!path || path.startsWith("/plataforma") || path.startsWith("/cotizacion")) return;
    const device = window.matchMedia("(max-width: 767px)").matches ? "mobile" : "desktop";
    let cleanup: (() => void) | null = null;
    let cancelled = false;
    fetch(`${API}/api/v1/public/popups?path=${encodeURIComponent(path)}&device=${device}`, { headers: { Accept: "application/json" } })
      .then((r) => (r.ok ? r.json() : null))
      .then((j: { data: { max_per_visit: number; popups: Popup[] } } | null) => {
        if (cancelled || !j) return;
        const shown = Number(sessionStorage.getItem(VISIT) ?? 0);
        if (shown >= (j.data.max_per_visit || 1)) return;
        const p = j.data.popups.find((x) => canShow(x) && !(x.trigger_type === "exit_intent" && device === "mobile"));
        if (!p) return;
        const show = () => {
          if (document.querySelector('[role="dialog"]')) return;   // no encima de otro diálogo (cookies, chatbot…)
          cleanup?.();
          previous.current = document.activeElement as HTMLElement | null;
          setOpen(p);
          try {
            localStorage.setItem(SEEN(p.id), String(Date.now()));
            sessionStorage.setItem(VISIT, String(shown + 1));
          } catch {
            /* sin almacenamiento: se muestra igual una vez */
          }
          track("popup_view", p.id);
        };
        if (p.trigger_type === "time_on_page") {
          const t = window.setTimeout(show, (p.trigger_value ?? 10) * 1000);
          cleanup = () => window.clearTimeout(t);
        } else if (p.trigger_type === "scroll_percent") {
          const onScroll = () => {
            const h = document.documentElement.scrollHeight - window.innerHeight;
            if (h > 0 && (window.scrollY / h) * 100 >= (p.trigger_value ?? 50)) show();
          };
          window.addEventListener("scroll", onScroll, { passive: true });
          cleanup = () => window.removeEventListener("scroll", onScroll);
        } else {
          const onLeave = (e: MouseEvent) => {
            if (e.clientY <= 0 && !e.relatedTarget) show();
          };
          document.addEventListener("mouseout", onLeave);
          cleanup = () => document.removeEventListener("mouseout", onLeave);
        }
      })
      .catch(() => undefined);
    return () => {
      cancelled = true;
      cleanup?.();
    };
  }, [path]);

  useEffect(() => {
    if (!open) return;
    dialog.current?.querySelector<HTMLElement>("a, button")?.focus();
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && close();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  function close() {
    if (open) track("popup_close", open.id);
    setOpen(null);
    previous.current?.focus?.();
  }

  if (!open) return null;
  const img = assetUrl(open.image_file_id);
  return (
    <div className="fixed inset-0 z-[60] flex items-end justify-center bg-[#0b1f36]/55 sm:items-center sm:p-4" onMouseDown={(e) => e.target === e.currentTarget && close()}>
      <div
        ref={dialog}
        role="dialog"
        aria-modal="true"
        aria-labelledby="popup-title"
        className="relative max-h-[50vh] w-full overflow-y-auto rounded-t-3xl bg-white shadow-2xl sm:max-h-[85vh] sm:max-w-md sm:rounded-3xl"
      >
        <button type="button" onClick={close} aria-label="Cerrar" className="absolute right-2 top-2 z-10 grid h-11 w-11 place-items-center rounded-full bg-white/90 text-[var(--site-text-2)] hover:text-[var(--site-navy-title)]">
          <Icon name="close" size={20} />
        </button>
        {img && (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={img} alt={open.image_alt_text ?? ""} className="hidden max-h-44 w-full object-cover sm:block" />
        )}
        <div className="space-y-3 p-6 pr-12">
          <h2 id="popup-title" className="font-display text-xl font-bold text-[var(--site-navy-title)]">
            {open.title}
          </h2>
          {open.body && <p className="text-[15px] leading-relaxed text-[var(--site-text-2)]">{open.body}</p>}
          <div className="flex flex-col gap-2 pt-1 sm:flex-row sm:items-center">
            <Link
              href={open.href}
              onClick={() => {
                track("popup_click", open.id);
                setOpen(null);
              }}
              className="site-btn site-btn-primary"
            >
              {open.cta_label || "Ver más"}
            </Link>
            <button type="button" onClick={close} className="px-3 py-2 text-sm font-semibold text-[var(--site-text-2)] underline-offset-2 hover:underline">
              No, gracias
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
