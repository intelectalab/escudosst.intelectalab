"use client";

// Estructura de la aplicación de empresa (docs/07-pantallas-y-ux.md §2):
// menú lateral por grupos, cabecera con la empresa activa y cajón en el celular.
// En el menú: solo módulos que el rol puede usar; los no contratados con candado;
// AUL/VTA desactivados por la empresa no aparecen.

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { AppBrand } from "@/components/AppBrand";
import { AppContext, type AppState } from "@/components/AppContext";
import { HelpButton } from "@/components/HelpButton";
import { LegalPendingGate } from "@/components/LegalPendingGate";
import { NotificationBell } from "@/components/NotificationBell";
import { SupportRequestBanner } from "@/components/SupportRequestBanner";
import { Icon } from "@/components/ui/Icon";
import { formatDate } from "@/lib/accounts";
import { SubscriptionBanner } from "@/components/SubscriptionBanner";
import { api, ApiError } from "@/lib/api";
import { availableIn, MENU_GROUPS } from "@/lib/menu";
import { session } from "@/lib/session";
import type { CompanyOption, ModuleItem, User } from "@/lib/types";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [state, setState] = useState<AppState | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [drawer, setDrawer] = useState(false);

  useEffect(() => {
    const companyId = session.getCompanyId();
    if (!session.getToken()) return router.replace("/login");

    api<User>("/me")
      .then(async (user) => {
        // El equipo de la plataforma solo entra a una empresa con un acceso de soporte autorizado (docs/05 §4.6).
        if (user.is_platform_staff && !companyId) return router.replace("/plataforma");
        if (!companyId) return router.replace("/select-company");
        const companies = await api<{ data: CompanyOption[] }>("/me/companies");
        const company = companies.data.find((c) => c.company_id === companyId);
        if (!company) {
          if (user.is_platform_staff) {
            session.clearCompany();
            return router.replace("/plataforma/soporte");
          }
          return router.replace("/select-company");
        }
        const modules = await api<{ data: ModuleItem[] }>("/me/modules", { withCompany: true });
        setState({ user, company, modules: modules.data, companiesCount: companies.data.length });
      })
      .catch((err) => {
        if (err instanceof ApiError && err.status === 401) router.replace("/login");
        else if (err instanceof ApiError && err.status === 404) router.replace("/select-company");
        else setError(err instanceof Error ? err.message : "No pudimos cargar la aplicación.");
      });
  }, [router]);

  useEffect(() => setDrawer(false), [pathname]);

  function leaveSupport() {
    session.clearCompany();
    router.replace("/plataforma/soporte");
  }

  async function logout() {
    await api("/auth/logout", { method: "POST" }).catch(() => undefined);
    session.clear();
    router.replace("/login");
  }

  if (error) return <p className="p-8 text-bad">{error}</p>;
  if (!state) return <p className="p-8 text-muted">Cargando…</p>;

  const byCode = new Map(state.modules.map((m) => [m.code, m]));
  const visible = (m: ModuleItem | undefined): m is ModuleItem => !!m && m.role_can_use && m.mode !== "disabled_by_company";
  const groups = MENU_GROUPS.map((g) => ({ ...g, items: g.codes.map((c) => byCode.get(c)).filter(visible) })).filter((g) => g.items.length > 0);
  const dsh = byCode.get("DSH");
  const isSupport = state.company.role.code === "platform_support";
  const canSeeSupport = ["account_admin", "sst_manager"].includes(state.company.role.code);

  const nav = (
    <nav className="flex-1 overflow-y-auto p-3 space-y-4 text-sm" aria-label="Módulos">
      {visible(dsh) && (
        <NavLink href="/inicio" active={pathname === "/inicio"}>
          Inicio
        </NavLink>
      )}
      {groups.map((g) => (
        <div key={g.title}>
          <p className={`px-2 pb-1 text-xs uppercase tracking-wide ${g.highlight ? "text-amber-300" : "text-white/50"}`}>{g.title}</p>
          <ul>
            {g.items.map((m) => (
              <li key={m.code}>
                <NavLink
                  href={`/m/${m.code.toLowerCase()}`}
                  active={pathname === `/m/${m.code.toLowerCase()}`}
                  muted={m.mode === "locked"}
                  title={m.mode === "locked" ? `Disponible en ${availableIn(m.required_plan, m.name)}` : (m.solves ?? m.name)}
                >
                  <span className="truncate">{m.name}</span>
                  {m.mode === "locked" && <span aria-label="No incluido en tu plan">🔒</span>}
                  {m.mode === "read_only" && <span className="text-[10px] uppercase">Lectura</span>}
                </NavLink>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </nav>
  );

  return (
    <AppContext.Provider value={state}>
      {isSupport && (
        <div className="sticky top-0 z-50 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-amber-ink px-4 py-2 text-sm text-white">
          <Icon name="eye" size={16} />
          <span>
            <b>Sesión de soporte</b> en {state.company.name} · solo lectura, sin datos médicos
            {state.company.expires_at && ` · vence ${formatDate(state.company.expires_at, true)}`}
          </span>
          <button onClick={leaveSupport} className="rounded-md bg-white/20 px-2.5 py-0.5 font-semibold hover:bg-white/30">
            Volver al panel
          </button>
        </div>
      )}
      <div className="min-h-screen flex">
        {/* Menú en escritorio */}
        <aside className="hidden md:flex w-72 shrink-0 flex-col bg-side text-white/90 sticky top-0 h-screen">
          <div className="p-5 border-b border-white/10">
            <Link href="/inicio">
              <AppBrand light />
            </Link>
          </div>
          {nav}
        </aside>

        {/* Cajón en el celular */}
        {drawer && (
          <div className="fixed inset-0 z-40 md:hidden" role="dialog" aria-modal="true" aria-label="Menú">
            <button className="absolute inset-0 bg-black/40" aria-label="Cerrar menú" onClick={() => setDrawer(false)} />
            <aside className="relative flex h-full w-72 flex-col bg-side text-white/90">
              <div className="flex items-center justify-between p-5 border-b border-white/10">
                <AppBrand light />
                <button onClick={() => setDrawer(false)} aria-label="Cerrar menú" className="text-xl">
                  ✕
                </button>
              </div>
              {nav}
            </aside>
          </div>
        )}

        <div className="flex-1 min-w-0 flex flex-col">
          <header className="flex items-center justify-between gap-3 border-b border-line bg-surface px-4 md:px-6 py-3">
            <div className="flex min-w-0 items-center gap-3">
              <button className="md:hidden rounded-lg border border-line px-3 py-2" aria-label="Abrir menú" onClick={() => setDrawer(true)}>
                ☰
              </button>
              <div className="min-w-0">
                <p className="font-display font-semibold truncate">{state.company.name}</p>
                <p className="text-sm text-muted truncate">
                  RUC {state.company.ruc} · {state.company.role.name}
                </p>
              </div>
            </div>
            <div className="flex items-center gap-2 text-sm">
              <HelpButton />
              {!isSupport && <NotificationBell />}
              {canSeeSupport && (
                <Link href="/soporte" className="hidden rounded-lg px-3 py-2 text-muted hover:bg-bg hover:text-fg lg:block" title="Accesos de soporte">
                  Soporte
                </Link>
              )}
              {state.companiesCount > 1 && (
                <Link href="/select-company" className="rounded-lg border border-line px-3 py-2 hover:bg-bg">
                  Cambiar empresa
                </Link>
              )}
              <Link href="/cuenta" className="hidden sm:block rounded-lg px-3 py-2 text-muted hover:bg-bg hover:text-fg" title="Mi cuenta">
                {state.user.full_name}
              </Link>
              <button onClick={logout} className="rounded-lg border border-line px-3 py-2 hover:bg-bg">
                Salir
              </button>
            </div>
          </header>
          <main className="flex-1 p-4 md:p-6">
            {canSeeSupport && <SupportRequestBanner />}
            {!isSupport && <SubscriptionBanner />}
            {!isSupport && <LegalPendingGate />}
            {children}
          </main>
        </div>
      </div>
    </AppContext.Provider>
  );
}

function NavLink({ href, active, muted, title, children }: { href: string; active: boolean; muted?: boolean; title?: string; children: React.ReactNode }) {
  return (
    <Link
      href={href}
      title={title}
      aria-current={active ? "page" : undefined}
      className={`flex items-center justify-between gap-2 rounded-md px-2 py-1.5 ${active ? "bg-white text-side font-semibold" : muted ? "text-white/40 hover:bg-white/5" : "hover:bg-white/10"}`}
    >
      {children}
    </Link>
  );
}
