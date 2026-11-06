"use client";

// EscudoSST · Panel de la plataforma (docs/07-pantallas-y-ux.md §1.11, SUS).
// Solo para el equipo de EscudoSST (users.is_platform_staff). No muestra datos de negocio de las empresas.
// El menú sigue al prototipo; lo que aún no se construye aparece deshabilitado («Próximamente»).

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Brand } from "@/components/Brand";
import { PlatformUserContext } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
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
      { href: "/plataforma/sitio", label: "Sitio web", icon: "globe", ready: true },
    ],
  },
  {
    title: "Operación",
    items: [
      { href: "/plataforma/bitacora", label: "Bitácora de auditoría", icon: "history", ready: true },
      { href: "/plataforma/salud", label: "Salud del sistema", icon: "heart", ready: true },
    ],
  },
];

const initials = (name: string) =>
  name
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();

export default function PlatformLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [user, setUser] = useState<User | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [drawer, setDrawer] = useState(false);

  useEffect(() => {
    if (!session.getToken()) return router.replace("/login");
    api<User>("/me")
      .then((u) => (u.is_platform_staff ? setUser(u) : router.replace("/inicio")))
      .catch((err) => {
        if (err instanceof ApiError && err.status === 401) router.replace("/login");
        else setError(err instanceof Error ? err.message : "No pudimos cargar el panel.");
      });
  }, [router]);

  useEffect(() => setDrawer(false), [pathname]);

  async function logout() {
    await api("/auth/logout", { method: "POST" }).catch(() => undefined);
    session.clear();
    router.replace("/login");
  }

  if (error) return <p className="p-8 text-bad">{error}</p>;
  if (!user) return <p className="p-8 text-muted">Cargando…</p>;

  const nav = (
    <nav className="flex-1 space-y-5 overflow-y-auto p-3 text-sm" aria-label="Panel de la plataforma">
      {SECTIONS.map((s) => (
        <div key={s.title}>
          <p className="px-2 pb-1.5 text-[11px] font-bold uppercase tracking-wider text-white/45">{s.title}</p>
          <ul className="space-y-0.5">
            {s.items.map((item) => {
              const active = pathname === item.href || pathname.startsWith(item.href + "/");
              return (
                <li key={item.href}>
                  {item.ready ? (
                    <Link
                      href={item.href}
                      aria-current={active ? "page" : undefined}
                      className={`flex items-center gap-2.5 rounded-lg px-2.5 py-2 ${active ? "bg-white font-semibold text-side" : "hover:bg-white/10"}`}
                    >
                      <Icon name={item.icon} />
                      {item.label}
                    </Link>
                  ) : (
                    <span className="flex cursor-not-allowed items-center gap-2.5 rounded-lg px-2.5 py-2 text-white/35" title="Próximamente">
                      <Icon name={item.icon} />
                      <span className="flex-1">{item.label}</span>
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

  const brand = (
    <div className="space-y-3 border-b border-white/10 p-5">
      <Brand light />
      <div className="rounded-lg bg-white/10 px-3 py-2">
        <p className="text-sm font-semibold text-white">Plataforma EscudoSST</p>
        <p className="text-xs text-white/60">Panel interno</p>
      </div>
    </div>
  );

  return (
    <PlatformUserContext.Provider value={user}>
      <div className="flex min-h-screen">
        <aside className="sticky top-0 hidden h-screen w-64 shrink-0 flex-col bg-side text-white/90 md:flex">
          {brand}
          {nav}
          <p className="p-4 text-xs text-white/40">Sin acceso a datos de negocio de las empresas.</p>
        </aside>

        {drawer && (
          <div className="fixed inset-0 z-40 md:hidden" role="dialog" aria-modal="true" aria-label="Menú">
            <button className="absolute inset-0 bg-black/40" aria-label="Cerrar menú" onClick={() => setDrawer(false)} />
            <aside className="relative flex h-full w-72 flex-col bg-side text-white/90">
              {brand}
              {nav}
            </aside>
          </div>
        )}

        <div className="flex min-w-0 flex-1 flex-col">
          <header className="sticky top-0 z-30 flex items-center justify-between gap-3 border-b border-line bg-surface/95 px-4 py-3 backdrop-blur md:px-6">
            <div className="flex min-w-0 items-center gap-3">
              <button className="rounded-lg border border-line px-3 py-2 md:hidden" aria-label="Abrir menú" onClick={() => setDrawer(true)}>
                ☰
              </button>
              <p className="truncate font-display font-semibold"><span className="hidden sm:inline">EscudoSST · </span>Panel de la plataforma</p>
            </div>
            <div className="flex items-center gap-2 text-sm">
              <Link href="/plataforma/cuenta" className="flex items-center gap-2.5 rounded-lg px-2 py-1.5 hover:bg-bg" title="Mi cuenta">
                <span className="hidden text-right leading-tight sm:block">
                  <b className="block">{user.full_name}</b>
                  <span className="text-xs text-muted">Dueño de la plataforma</span>
                </span>
                <span className="grid h-9 w-9 place-items-center rounded-full bg-brand-soft font-bold text-brand-strong">{initials(user.full_name)}</span>
              </Link>
              <Button variant="secondary" size="sm" icon="logout" onClick={logout} aria-label="Salir">
                <span className="hidden sm:inline">Salir</span>
              </Button>
            </div>
          </header>
          <main className="flex-1 p-4 md:p-6">{children}</main>
        </div>
      </div>
    </PlatformUserContext.Provider>
  );
}
