"use client";

// Menú del usuario en la barra superior: datos, Mi cuenta, avisos, tema y salir.

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { getTheme, setTheme, type Theme } from "@/lib/theme";

const initials = (name: string) =>
  name
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();

export function UserMenu({ name, email, role, accountHref, noticesHref, onLogout }: { name: string; email: string; role: string; accountHref: string; noticesHref?: string; onLogout: () => void }) {
  const [open, setOpen] = useState(false);
  const [theme, setThemeState] = useState<Theme>("auto");
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => setThemeState(getTheme()), []);
  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => !ref.current?.contains(e.target as Node) && setOpen(false);
    const esc = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", close);
    document.addEventListener("keydown", esc);
    return () => {
      document.removeEventListener("mousedown", close);
      document.removeEventListener("keydown", esc);
    };
  }, [open]);

  const item = "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm hover:bg-bg";

  return (
    <div ref={ref} className="relative">
      <button type="button" onClick={() => setOpen((o) => !o)} aria-expanded={open} aria-haspopup="menu" className="flex items-center gap-2.5 rounded-xl py-1 pl-2 pr-1.5 transition-colors hover:bg-bg">
        <span className="hidden text-right leading-tight sm:block">
          <b className="block text-sm">{name}</b>
          <span className="text-xs text-muted">{role}</span>
        </span>
        <span className="grid h-9 w-9 place-items-center rounded-full bg-brand font-bold text-white shadow-sm">{initials(name)}</span>
        <Icon name="chevron" size={14} className={`text-muted transition-transform ${open ? "-rotate-90" : "rotate-90"}`} />
      </button>

      {open && (
        <div role="menu" className="absolute right-0 top-12 z-50 w-72 overflow-hidden rounded-2xl border border-line bg-surface shadow-xl">
          <div className="flex items-center gap-3 bg-gradient-to-br from-side to-brand px-4 py-4 text-white">
            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-white/15 font-bold ring-1 ring-white/30">{initials(name)}</span>
            <span className="min-w-0">
              <b className="block truncate">{name}</b>
              <span className="block truncate text-xs text-white/80">{email}</span>
              <span className="block truncate text-xs text-white/80">{role}</span>
            </span>
          </div>
          <div className="space-y-0.5 p-2">
            <Link href={accountHref} role="menuitem" className={item} onClick={() => setOpen(false)}>
              <Icon name="user" size={17} className="text-muted" /> Mi cuenta
            </Link>
            {noticesHref && (
              <Link href={noticesHref} role="menuitem" className={item} onClick={() => setOpen(false)}>
                <Icon name="bell" size={17} className="text-muted" /> Mis avisos
              </Link>
            )}
          </div>
          <div className="border-t border-line px-4 py-3">
            <p className="mb-2 text-xs font-semibold text-muted">Tema</p>
            <div role="radiogroup" aria-label="Tema" className="grid grid-cols-3 rounded-xl bg-bg p-1">
              {(
                [
                  ["light", "Claro"],
                  ["dark", "Oscuro"],
                  ["auto", "Automático"],
                ] as [Theme, string][]
              ).map(([t, l]) => (
                <button
                  key={t}
                  type="button"
                  role="radio"
                  aria-checked={theme === t}
                  onClick={() => {
                    setTheme(t);
                    setThemeState(t);
                  }}
                  className={`rounded-lg py-1.5 text-xs font-semibold transition-colors ${theme === t ? "bg-surface text-fg shadow-sm" : "text-muted hover:text-fg"}`}
                >
                  {l}
                </button>
              ))}
            </div>
          </div>
          <div className="border-t border-line p-2">
            <button type="button" role="menuitem" onClick={onLogout} className={`${item} w-full text-bad hover:bg-bad-soft`}>
              <Icon name="logout" size={17} /> Cerrar sesión
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
