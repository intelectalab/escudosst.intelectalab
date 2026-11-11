"use client";

// Menú del usuario en la cabecera: su nombre (con iniciales) abre una lista con su cuenta, su firma, los accesos de
// soporte (solo administrador / Jefe SST), cambiar de empresa y salir.

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { Icon } from "@/components/ui/Icon";

export function UserMenu({ name, email, role, company, canSeeSupport, canSwitch, onLogout }: {
  name: string;
  email?: string | null;
  role: string;
  company: string;
  canSeeSupport: boolean;
  canSwitch: boolean;
  onLogout: () => void;
}) {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => !box.current?.contains(e.target as Node) && setOpen(false);
    const esc = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", close);
    document.addEventListener("keydown", esc);
    return () => {
      document.removeEventListener("mousedown", close);
      document.removeEventListener("keydown", esc);
    };
  }, [open]);

  const item = "flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-bg";
  return (
    <div ref={box} className="relative">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        aria-haspopup="menu"
        aria-expanded={open}
        className={`flex h-10 items-center gap-2 rounded-xl border pl-1 pr-2 transition sm:pr-3 ${open ? "border-brand/40 bg-brand-soft" : "border-line bg-surface hover:bg-bg"}`}
      >
        <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-[#0d3b6e] to-[#1f7fd1] text-xs font-bold text-white">{initials}</span>
        <span className="hidden max-w-[11rem] truncate text-sm font-semibold sm:block">{name}</span>
        <Icon name="chevronDown" size={15} className={`hidden text-muted transition-transform sm:block ${open ? "rotate-180" : ""}`} />
      </button>
      {open && (
        <div role="menu" className="absolute right-0 z-50 mt-2 w-72 overflow-hidden rounded-2xl border border-line bg-surface shadow-xl">
          <div className="flex items-center gap-3 border-b border-line bg-gradient-to-r from-brand-soft to-surface p-4">
            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-[#0d3b6e] to-[#1f7fd1] text-sm font-bold text-white">{initials}</span>
            <div className="min-w-0">
              <p className="truncate font-semibold">{name}</p>
              {email && <p className="truncate text-xs text-muted">{email}</p>}
              <p className="truncate text-xs text-muted">{role} · {company}</p>
            </div>
          </div>
          <div className="p-2" onClick={() => setOpen(false)}>
            <Link href="/cuenta" role="menuitem" className={item}><Icon name="user" size={17} className="text-muted" /> Mi cuenta y seguridad</Link>
            <Link href="/cuenta#firma" role="menuitem" className={item}><Icon name="pen" size={17} className="text-muted" /> Mi firma</Link>
            {canSeeSupport && <Link href="/soporte" role="menuitem" className={item}><Icon name="shield" size={17} className="text-muted" /> Accesos de soporte</Link>}
            {canSwitch && <Link href="/select-company" role="menuitem" className={item}><Icon name="swap" size={17} className="text-muted" /> Cambiar de empresa</Link>}
          </div>
          <div className="border-t border-line p-2">
            <button type="button" role="menuitem" onClick={onLogout} className={`${item} font-semibold text-bad hover:bg-bad-soft`}>
              <Icon name="logout" size={17} /> Cerrar sesión
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
