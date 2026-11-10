"use client";

// EscudoSST · Panel de la plataforma (docs/07-pantallas-y-ux.md §1.11, SUS).
// Solo para el equipo de EscudoSST (users.is_platform_staff). No muestra datos de negocio de las empresas.
// El menú sigue al prototipo; lo que aún no se construye aparece deshabilitado («Próximamente»).
// En escritorio el menú se puede contraer: quedan los íconos y, al pasar el mouse, se despliega con los nombres.
// La preferencia se guarda en este navegador.

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { AppBrand } from "@/components/AppBrand";
import { NotificationsBell } from "@/components/platform/NotificationsBell";
import { UserMenu } from "@/components/platform/UserMenu";
import { PlatformUserContext } from "@/components/PlatformContext";
import { Icon } from "@/components/ui/Icon";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import type { User } from "@/lib/types";

type Item = { href: string; label: string; icon: string; ready: boolean };

// Menú del panel del dueño según docs/07-pantallas-y-ux.md §5.6 y docs/03 §11:
// Cuentas (y suscripciones), Facturación, Planes, Catálogos, Métricas, Soporte, Salud; más Bitácora y Sitio web.
const SECTIONS: { title: string; items: Item[] }[] = [
  {
    title: "Clientes",
    items: [
      { href: "/plataforma/cuentas", label: "Cuentas y suscripciones", icon: "building", ready: true },
      { href: "/plataforma/facturacion", label: "Facturación", icon: "file", ready: true },
      { href: "/plataforma/metricas", label: "Métricas", icon: "grid", ready: true },
      { href: "/plataforma/soporte", label: "Accesos de soporte", icon: "shield", ready: true },
    ],
  },
  {
    title: "Configuración",
    items: [
      { href: "/plataforma/planes", label: "Planes y módulos", icon: "tag", ready: true },
      { href: "/plataforma/catalogos", label: "Catálogos globales", icon: "box", ready: true },
      { href: "/plataforma/proveedores", label: "Proveedores y claves", icon: "plug", ready: true },
      { href: "/plataforma/sitio", label: "Sitio web", icon: "globe", ready: true },
    ],
  },
  {
    title: "Operación",
    items: [
      { href: "/plataforma/equipo", label: "Equipo de la plataforma", icon: "people", ready: true },
      { href: "/plataforma/bitacora", label: "Bitácora de auditoría", icon: "history", ready: true },
      { href: "/plataforma/salud", label: "Salud del sistema", icon: "heart", ready: true },
    ],
  },
];

const COLLAPSE_KEY = "escudo.sidebar";

const EXTRA: Record<string, [string, string]> = {
  "/plataforma/cuenta": ["Mi cuenta", "user"],
  "/plataforma/avisos": ["Avisos", "bell"],
};

/** Sección actual (para la barra superior). */
function current(pathname: string): [string, string] | null {
  for (const s of SECTIONS) for (const i of s.items) if (pathname === i.href || pathname.startsWith(i.href + "/")) return [i.label, i.icon];
  return EXTRA[pathname] ?? null;
}

export default function PlatformLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [user, setUser] = useState<User | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [drawer, setDrawer] = useState(false);
  const [collapsed, setCollapsed] = useState(false);

  useEffect(() => {
    if (!session.getToken()) return router.replace("/login");
    api<User>("/me")
      .then((u) => (u.is_platform_staff ? setUser(u) : router.replace("/inicio")))
      .catch((err) => {
        if (err instanceof ApiError && err.status === 401) router.replace("/login");
        else setError(err instanceof Error ? err.message : "No pudimos cargar el panel.");
      });
  }, [router]);

  useEffect(() => setCollapsed(window.localStorage.getItem(COLLAPSE_KEY) === "collapsed"), []);
  useEffect(() => setDrawer(false), [pathname]);

  function toggle() {
    setCollapsed((c) => {
      window.localStorage.setItem(COLLAPSE_KEY, c ? "open" : "collapsed");
      return !c;
    });
  }

  async function logout() {
    await api("/auth/logout", { method: "POST" }).catch(() => undefined);
    session.clear();
    router.replace("/login");
  }

  if (error) return <p className="p-8 text-bad">{error}</p>;
  if (!user) return <p className="p-8 text-muted">Cargando…</p>;

  // Con el menú contraído, los textos se ocultan y aparecen al pasar el mouse (el menú se despliega encima).
  const fade = (mini: boolean) => (mini ? "opacity-0 transition-opacity duration-150 group-hover/side:opacity-100" : "");

  const nav = (mini: boolean) => (
    <nav className="flex-1 space-y-3 overflow-y-auto overflow-x-hidden px-2.5 py-3 text-[13.5px] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Panel de la plataforma">
      {SECTIONS.map((s) => (
        <div key={s.title}>
          <div className="relative h-5">
            <p className={`absolute inset-x-0 top-0 whitespace-nowrap px-3 text-[10.5px] font-bold uppercase tracking-[0.12em] text-white/40 ${fade(mini)}`}>{s.title}</p>
            {mini && <span className="absolute left-4 right-4 top-2 h-px bg-white/10 transition-opacity group-hover/side:opacity-0" aria-hidden="true" />}
          </div>
          <ul className="space-y-0.5">
            {s.items.map((item) => {
              const active = pathname === item.href || pathname.startsWith(item.href + "/");
              const tile = `grid h-8 w-8 shrink-0 place-items-center rounded-lg transition-colors ${active ? "bg-gradient-to-br from-brand to-[#3a9bea] text-white shadow-md shadow-brand/40" : "bg-white/[0.06] text-white/75 group-hover/item:bg-white/15 group-hover/item:text-white"}`;
              return (
                <li key={item.href}>
                  {item.ready ? (
                    <Link
                      href={item.href}
                      aria-current={active ? "page" : undefined}
                      title={mini ? item.label : undefined}
                      className={`group/item relative flex items-center gap-3 whitespace-nowrap rounded-xl py-1.5 pl-[7px] pr-3 transition-colors ${active ? "bg-white/[0.12] font-semibold text-white" : "text-white/75 hover:bg-white/[0.06] hover:text-white"}`}
                    >
                      {active && <span className="absolute -left-2.5 top-1/2 h-6 w-1 -translate-y-1/2 rounded-r-full bg-[#5fb4f5]" aria-hidden="true" />}
                      <span className={tile}>
                        <Icon name={item.icon} size={17} />
                      </span>
                      <span className={fade(mini)}>{item.label}</span>
                    </Link>
                  ) : (
                    <span className="flex cursor-not-allowed items-center gap-3 whitespace-nowrap rounded-xl py-1.5 pl-[7px] pr-3 text-white/35" title="Próximamente">
                      <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white/[0.04]">
                        <Icon name={item.icon} size={17} />
                      </span>
                      <span className={`flex-1 ${fade(mini)}`}>{item.label}</span>
                      <Icon name="lock" size={13} />
                    </span>
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );

  const brand = (mini: boolean) => (
    <div className="overflow-hidden whitespace-nowrap border-b border-white/10 px-[17px] py-4">
      <div className={mini ? "[&_.font-display]:opacity-0 [&_.font-display]:transition-opacity group-hover/side:[&_.font-display]:opacity-100" : ""}>
        <AppBrand light />
      </div>
      <div
        className={`overflow-hidden transition-all duration-200 ${mini ? "mt-0 max-h-0 opacity-0 group-hover/side:mt-3 group-hover/side:max-h-16 group-hover/side:opacity-100" : "mt-3 max-h-16"}`}
      >
        <p className="flex items-center gap-2 rounded-lg bg-white/[0.08] px-3 py-1.5 text-xs text-white/70 ring-1 ring-white/10">
          <span className="h-1.5 w-1.5 rounded-full bg-[#4ade80] shadow-[0_0_8px_#4ade80]" aria-hidden="true" />
          <span>
            <b className="font-semibold text-white">Plataforma</b> · panel interno
          </span>
        </p>
      </div>
    </div>
  );

  const here = current(pathname);
  const toggleLabel = collapsed ? "Fijar el menú abierto" : "Contraer el menú";

  return (
    <PlatformUserContext.Provider value={user}>
      <div className="flex min-h-screen">
        {/* Hueco que ocupa el menú en escritorio; el menú va fijo encima para poder desplegarse sobre el contenido. */}
        <div className={`hidden shrink-0 transition-[width] duration-200 md:block ${collapsed ? "w-[68px]" : "w-60"}`} aria-hidden="true" />
        <aside
          className={`group/side fixed inset-y-0 left-0 z-40 hidden flex-col overflow-hidden bg-gradient-to-b from-side via-side to-[#082848] text-white/90 transition-[width,box-shadow] duration-200 md:flex ${
            collapsed ? "w-[68px] hover:w-60 hover:shadow-2xl" : "w-60"
          }`}
        >
          {brand(collapsed)}
          {nav(collapsed)}
          <div className="border-t border-white/10 px-2.5 py-2.5">
            <button type="button" onClick={toggle} className="group/item flex w-full items-center gap-3 whitespace-nowrap rounded-xl py-1.5 pl-[7px] pr-3 text-[13px] text-white/60 hover:bg-white/[0.06] hover:text-white" aria-label={toggleLabel}>
              <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white/[0.06]">
                <Icon name={collapsed ? "chevron" : "back"} size={16} />
              </span>
              <span className={fade(collapsed)}>{toggleLabel}</span>
            </button>
          </div>
        </aside>

        {drawer && (
          <div className="fixed inset-0 z-50 md:hidden" role="dialog" aria-modal="true" aria-label="Menú">
            <button className="absolute inset-0 bg-black/40" aria-label="Cerrar menú" onClick={() => setDrawer(false)} />
            <aside className="relative flex h-full w-72 flex-col bg-gradient-to-b from-side to-[#082848] text-white/90">
              {brand(false)}
              {nav(false)}
            </aside>
          </div>
        )}

        <div className="flex min-w-0 flex-1 flex-col">
          <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur md:px-6">
            <div className="flex min-w-0 items-center gap-2">
              <button className="grid h-10 w-10 place-items-center rounded-xl text-muted hover:bg-bg hover:text-fg md:hidden" aria-label="Abrir menú" onClick={() => setDrawer(true)}>
                <Icon name="menu" size={20} />
              </button>
              <button className="hidden h-10 w-10 place-items-center rounded-xl text-muted hover:bg-bg hover:text-fg md:grid" aria-label={toggleLabel} title={toggleLabel} onClick={toggle}>
                <Icon name="sidebar" size={19} />
              </button>
              {here && (
                <p className="flex min-w-0 items-center gap-2 font-display font-semibold">
                  <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-soft text-brand-strong">
                    <Icon name={here[1]} size={16} />
                  </span>
                  <span className="truncate">{here[0]}</span>
                </p>
              )}
            </div>
            <div className="flex items-center gap-1.5">
              <NotificationsBell />
              <span className="mx-1 hidden h-8 w-px bg-line sm:block" aria-hidden="true" />
              <UserMenu name={user.full_name} email={user.email} role={user.platform_role === "support" ? "Soporte de la plataforma" : "Dueño de la plataforma"} accountHref="/plataforma/cuenta" noticesHref="/plataforma/avisos" onLogout={logout} />
            </div>
          </header>
          <main className="flex-1 p-3 sm:p-4 xl:p-5">{children}</main>
        </div>
      </div>
    </PlatformUserContext.Provider>
  );
}
