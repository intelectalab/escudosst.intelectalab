"use client";

// Menú lateral de la aplicación de empresa (docs/07 §2; estilo del prototipo, prototipo/src/app.js menu()):
// «Inicio» arriba; cada grupo es un módulo del menú con sus submódulos (los módulos del catálogo), que se abre y cierra.
// Un grupo con un solo submódulo se muestra como enlace directo (no se despliega). Se pliega a solo íconos con el botón
// de la cabecera; plegado, cada grupo abre una lista flotante con sus submódulos.

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useContext, useEffect, useRef, useState } from "react";
import { AppContext } from "@/components/AppContext";
import { Icon } from "@/components/ui/Icon";
import { availableIn } from "@/lib/menu";
import { api } from "@/lib/api";
import type { ModuleItem, PermissionsResponse } from "@/lib/types";

export type SideGroup = { title: string; icon: string; highlight?: boolean; items: ModuleItem[] };

const OPEN_KEY = "escudo.menu.groups";
const hrefOf = (m: ModuleItem) => `/m/${m.code.toLowerCase()}`;

export function AppSidebar({ groups, showHome, brand, brandCompact, collapsed, mobile, onClose }: {
  groups: SideGroup[];
  showHome: boolean;
  brand: React.ReactNode;
  brandCompact: React.ReactNode;
  collapsed: boolean;
  mobile?: boolean;
  onClose?: () => void;
}) {
  const pathname = usePathname();
  // Panel de la consultora (M-CON, prototipo R.CON): arriba para los consultores y para la cuenta con plan Consultora.
  const app = useContext(AppContext);
  const consultancy = !!app && (app.company.role.code === "consultant" || app.modules.some((m) => m.code === "CON" && m.role_can_use && (m.mode === "enabled" || m.mode === "read_only")));
  const ingOn = !!app && app.modules.some((m) => m.code === "ING" && m.role_can_use && m.mode === "enabled");
  // Portería solo para quien opera la garita: permiso ing.access_events.create (M-ING §4), no para quien solo consulta eventos.
  const [gateOperator, setGateOperator] = useState(false);
  const companyId = app?.company.company_id;
  useEffect(() => {
    if (!ingOn) return setGateOperator(false);
    let live = true;
    api<PermissionsResponse>("/me/permissions", { withCompany: true })
      .then((r) => live && setGateOperator(r.data.includes("ing.access_events.create")))
      .catch(() => live && setGateOperator(false));
    return () => {
      live = false;
    };
  }, [ingOn, companyId]);
  const gate = ingOn && gateOperator;
  const activeCode = pathname.startsWith("/m/") ? pathname.split("/")[2]?.toUpperCase() : null;
  const activeGroup = groups.find((g) => g.items.some((m) => m.code === activeCode))?.title ?? null;

  // Grupos abiertos (el del submódulo abierto siempre se ve). Por defecto, abiertos los tres primeros (prototipo).
  const [open, setOpen] = useState<Record<string, boolean>>({});
  useEffect(() => {
    try {
      setOpen(JSON.parse(localStorage.getItem(OPEN_KEY) ?? "{}"));
    } catch {
      /* sin preferencias guardadas */
    }
  }, []);
  const isOpen = (g: SideGroup, i: number) => g.title === activeGroup || (open[g.title] ?? i < 3);
  const toggleGroup = (t: string, now: boolean) => {
    const next = { ...open, [t]: !now };
    setOpen(next);
    try {
      localStorage.setItem(OPEN_KEY, JSON.stringify(next));
    } catch {
      /* el navegador no guarda: igual funciona */
    }
  };

  // Plegado: lista flotante del grupo elegido.
  const [flyout, setFlyout] = useState<{ title: string; top: number } | null>(null);
  const flyRef = useRef<HTMLDivElement>(null);
  useEffect(() => setFlyout(null), [pathname, collapsed]);
  useEffect(() => {
    if (!flyout) return;
    const close = (e: MouseEvent) => !flyRef.current?.contains(e.target as Node) && setFlyout(null);
    const esc = (e: KeyboardEvent) => e.key === "Escape" && setFlyout(null);
    document.addEventListener("mousedown", close);
    document.addEventListener("keydown", esc);
    return () => {
      document.removeEventListener("mousedown", close);
      document.removeEventListener("keydown", esc);
    };
  }, [flyout]);
  const flyGroup = flyout ? groups.find((g) => g.title === flyout.title) : null;

  return (
    <div className="flex h-full flex-col">
      <div className={`flex h-16 shrink-0 items-center border-b border-white/10 ${collapsed ? "justify-center px-2" : "justify-between gap-2 px-4"}`}>
        {collapsed ? (
          <Link href="/inicio" className="grid h-10 w-10 place-items-center" title="Inicio">{brandCompact}</Link>
        ) : (
          <>
            <Link href="/inicio" className="min-w-0 overflow-hidden">{brand}</Link>
            {mobile && (
              <button type="button" onClick={onClose} className="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-white/70 hover:bg-white/10 hover:text-white" aria-label="Cerrar menú">
                <Icon name="close" size={18} />
              </button>
            )}
          </>
        )}
      </div>

      <nav className={`side-scroll flex-1 overflow-y-auto py-3 text-sm ${collapsed ? "px-2" : "px-3"}`} aria-label="Módulos">
        {showHome && <Solo href="/inicio" icon="home" label="Inicio" active={pathname === "/inicio"} collapsed={collapsed} />}
        {consultancy && <Solo href="/consultora" icon="briefcase" label="Panel de la consultora" active={pathname === "/consultora"} collapsed={collapsed} />}
        {/* Portería (M-ING): acceso directo solo para quien opera la garita (módulo habilitado y permiso de registrar ingresos). */}
        {gate && <Solo href="/garita" icon="idcard" label="Portería" active={pathname === "/garita"} collapsed={collapsed} />}

        <div className={`mt-2 space-y-1 border-t border-white/10 pt-2`}>
          {groups.map((g, i) => {
            // Un solo submódulo: enlace directo, sin desplegar.
            if (g.items.length === 1) {
              const m = g.items[0];
              return (
                <Solo key={g.title} href={hrefOf(m)} icon={g.icon} label={m.name} active={m.code === activeCode}
                  collapsed={collapsed} highlight={g.highlight} locked={m.mode === "locked"} title={m.mode === "locked" ? `Disponible en ${availableIn(m.required_plan, m.name)}` : (m.solves ?? m.name)} />
              );
            }
            if (collapsed) {
              const here = g.title === activeGroup;
              return (
                <button key={g.title} type="button" title={g.title} aria-label={g.title} aria-expanded={flyout?.title === g.title}
                  onClick={(e) => setFlyout(flyout?.title === g.title ? null : { title: g.title, top: (e.currentTarget as HTMLElement).getBoundingClientRect().top })}
                  className={`grid h-10 w-full place-items-center rounded-lg transition ${here ? "bg-white text-side" : "text-white/75 hover:bg-white/10 hover:text-white"}`}>
                  <Icon name={g.icon} size={19} />
                </button>
              );
            }
            const o = isOpen(g, i);
            return (
              <div key={g.title}>
                <button type="button" onClick={() => toggleGroup(g.title, o)} aria-expanded={o}
                  className="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left font-semibold text-white/90 transition hover:bg-white/10">
                  <Icon name={g.icon} size={17} className="shrink-0 text-white/70" />
                  <span className="flex-1 truncate">{g.title}</span>
                  <Icon name="chevronDown" size={14} className={`shrink-0 text-white/75 transition-transform ${o ? "" : "-rotate-90"}`} />
                </button>
                {o && (
                  <ul className="mb-1 mt-0.5 space-y-0.5 pl-[1.9rem]">
                    {g.items.map((m) => (
                      <li key={m.code}><Item m={m} active={m.code === activeCode} /></li>
                    ))}
                  </ul>
                )}
              </div>
            );
          })}
        </div>
      </nav>

      {collapsed && flyout && flyGroup && (
        <div ref={flyRef} role="menu" aria-label={flyGroup.title}
          className="side-scroll fixed left-[4.75rem] z-50 max-h-[70vh] w-72 overflow-y-auto rounded-xl border border-white/10 bg-side p-2 text-sm text-white shadow-2xl"
          style={{ top: Math.max(12, Math.min(flyout.top - 8, (typeof window !== "undefined" ? window.innerHeight : 800) - 360)) }}>
          <p className="px-2.5 pb-2 pt-1 font-semibold text-white/90">{flyGroup.title}</p>
          <ul className="space-y-0.5">
            {flyGroup.items.map((m) => (
              <li key={m.code}><Item m={m} active={m.code === activeCode} /></li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

/** Submódulo del menú: solo texto (prototipo), con candado si no está en el plan. */
function Item({ m, active }: { m: ModuleItem; active: boolean }) {
  const locked = m.mode === "locked";
  return (
    <Link href={hrefOf(m)} aria-current={active ? "page" : undefined}
      title={locked ? `Disponible en ${availableIn(m.required_plan, m.name)}` : (m.solves ?? m.name)}
      className={`flex items-center gap-2 rounded-md px-2.5 py-1.5 transition ${active ? "bg-white font-semibold text-side" : locked ? "text-white/65 hover:bg-white/5" : "text-white/80 hover:bg-white/10 hover:text-white"}`}>
      <span className="min-w-0 flex-1 truncate">{m.name}</span>
      {locked && <Icon name="lock" size={13} className="shrink-0" />}
      {m.mode === "read_only" && <span className="shrink-0 rounded bg-white/10 px-1 text-[9px] font-bold uppercase">Lectura</span>}
    </Link>
  );
}

/** Enlace suelto con ícono (Inicio y grupos de un solo submódulo). */
function Solo({ href, icon, label, active, collapsed, highlight, locked, title }: {
  href: string; icon: string; label: string; active: boolean; collapsed: boolean; highlight?: boolean; locked?: boolean; title?: string;
}) {
  return (
    <Link href={href} title={collapsed ? label : title} aria-label={collapsed ? label : undefined} aria-current={active ? "page" : undefined}
      className={`flex items-center rounded-lg font-semibold transition ${collapsed ? "h-10 justify-center" : "gap-2.5 px-2.5 py-2"} ${
        active ? "bg-white text-side" : highlight ? "text-amber-300 hover:bg-white/10" : locked ? "text-white/65 hover:bg-white/5" : "text-white/90 hover:bg-white/10"}`}>
      <Icon name={icon} size={collapsed ? 19 : 17} className="shrink-0" />
      {!collapsed && <span className="min-w-0 flex-1 truncate">{label}</span>}
      {!collapsed && locked && <Icon name="lock" size={13} className="shrink-0" />}
    </Link>
  );
}
