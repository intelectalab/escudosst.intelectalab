"use client";

// Estructura de la aplicación de empresa (docs/07-pantallas-y-ux.md §2):
// menú lateral por grupos, cabecera con la empresa activa y cajón en el celular.
// En el menú: solo módulos que el rol puede usar; los no contratados con candado;
// AUL/VTA desactivados por la empresa no aparecen.

import { usePathname, useRouter } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { CompanyBrand } from "@/components/addons/WhiteLabel";
import { AppContext, type AppState } from "@/components/AppContext";
import { GlobalSearch } from "@/components/GlobalSearch";
import { HelpButton } from "@/components/HelpButton";
import { ProductUpdatesButton } from "@/components/ProductUpdatesButton";
import { AppSidebar, type SideGroup } from "@/components/AppSidebar";
import { UserMenu } from "@/components/UserMenu";
import { MobileBar } from "@/components/MobileBar";
import { LegalPendingGate } from "@/components/LegalPendingGate";
import { NotificationBell } from "@/components/NotificationBell";
import { OfflineIndicator } from "@/components/OfflineIndicator";
import { SupportRequestBanner } from "@/components/SupportRequestBanner";
import { Icon } from "@/components/ui/Icon";
import { formatDate } from "@/lib/accounts";
import { SubscriptionBanner } from "@/components/SubscriptionBanner";
import { api, ApiError } from "@/lib/api";
import { MENU_GROUPS } from "@/lib/menu";
import { pendingCount } from "@/lib/offline";
import { session } from "@/lib/session";
import type { CompanyOption, ModuleItem, User } from "@/lib/types";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [state, setState] = useState<AppState | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [drawer, setDrawer] = useState(false);
  // Menú plegado (solo íconos) con el botón de la cabecera, como el prototipo; la elección se guarda en el navegador.
  const [collapsed, setCollapsed] = useState(false);
  useEffect(() => {
    try {
      const saved = localStorage.getItem("escudo.menu.collapsed");
      setCollapsed(saved === "1");
    } catch {
      setCollapsed(false);
    }
  }, []);
  function toggleCollapsed() {
    setCollapsed((c) => {
      try {
        localStorage.setItem("escudo.menu.collapsed", c ? "0" : "1");
      } catch {
        /* sin almacenamiento */
      }
      return !c;
    });
  }

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

  // Administrador de contratista (docs/05 §4.4, M-CTR SCR-CTR-05): solo ve su portal, su cuenta y la ayuda.
  const contractorOnly = state?.company.role.code === "contractor_admin";
  useEffect(() => {
    if (contractorOnly && !["/m/ctr", "/cuenta", "/soporte"].some((p) => pathname.startsWith(p))) router.replace("/m/ctr");
  }, [contractorOnly, pathname, router]);

  function leaveSupport() {
    session.clearCompany();
    router.replace("/plataforma/soporte");
  }

  async function logout() {
    // Registros guardados sin conexión: se avisa antes de salir (docs/16 §8).
    const pending = await pendingCount().catch(() => 0);
    if (pending > 0 && !window.confirm(`Tienes ${pending} registro(s) sin sincronizar. Quedarán guardados en este equipo y se enviarán cuando vuelvas a ingresar. ¿Salir igual?`)) return;
    await api("/auth/logout", { method: "POST" }).catch(() => undefined);
    session.clear();
    router.replace("/login");
  }

  if (error) return <p className="p-8 text-bad">{error}</p>;
  if (!state) return <p className="p-8 text-muted">Cargando…</p>;

  const byCode = new Map(state.modules.map((m) => [m.code, m]));
  const visible = (m: ModuleItem | undefined): m is ModuleItem => !!m && m.role_can_use && m.mode !== "disabled_by_company";
  const groups: SideGroup[] = MENU_GROUPS.map((g) => ({ title: g.title, icon: g.icon, highlight: g.highlight, items: g.codes.map((c) => byCode.get(c)).filter(visible) })).map((g) => (contractorOnly ? { ...g, items: g.items.filter((m) => m.code === "CTR") } : g)).filter((g) => g.items.length > 0);
  const dsh = byCode.get("DSH");
  const isSupport = state.company.role.code === "platform_support";
  const canSeeSupport = ["account_admin", "sst_manager"].includes(state.company.role.code);

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
      <div className="flex min-h-screen">
        {/* Menú en escritorio y tablet (se pliega a solo íconos) */}
        <aside className={`sticky top-0 z-30 hidden h-screen shrink-0 flex-col bg-side text-white/90 transition-[width] duration-200 md:flex ${collapsed ? "w-[4.5rem]" : "w-[17rem]"}`}>
          <Suspense>
            <AppSidebar
              groups={groups}
              showHome={visible(dsh) && !contractorOnly}
              brand={<CompanyBrand companyId={state.company.company_id} />}
              brandCompact={<CompanyBrand companyId={state.company.company_id} compact />}
              collapsed={collapsed}
            />
          </Suspense>
        </aside>

        {/* Cajón en el celular */}
        {drawer && (
          <div className="fixed inset-0 z-50 md:hidden" role="dialog" aria-modal="true" aria-label="Menú">
            <button className="absolute inset-0 bg-black/40" aria-label="Cerrar menú" onClick={() => setDrawer(false)} />
            <aside className="relative flex h-full w-[85vw] max-w-72 flex-col bg-side text-white/90 shadow-2xl">
              <Suspense>
                <AppSidebar
                  groups={groups}
                  showHome={visible(dsh) && !contractorOnly}
                  brand={<CompanyBrand companyId={state.company.company_id} />}
                  brandCompact={null}
                  collapsed={false}
                  mobile
                  onClose={() => setDrawer(false)}
                />
              </Suspense>
            </aside>
          </div>
        )}

        <div className="flex min-w-0 flex-1 flex-col">
          <header className="sticky top-0 z-40 flex h-16 items-center justify-between gap-3 border-b border-line bg-surface px-3 sm:px-5">
            <div className="flex min-w-0 items-center gap-3">
              <button className="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-line hover:bg-bg" aria-label="Abrir o plegar el menú" title="Abrir o plegar el menú"
                onClick={() => (window.innerWidth < 768 ? setDrawer(true) : toggleCollapsed())}>
                <Icon name="menu" size={20} />
              </button>
              <div className="min-w-0">
                <p className="truncate font-display font-semibold leading-tight">{state.company.name}</p>
                <p className="truncate text-xs text-muted sm:text-sm">
                  RUC {state.company.ruc} · {state.company.role.name}
                </p>
              </div>
            </div>
            <GlobalSearch />
            <div className="flex shrink-0 items-center gap-1.5 sm:gap-2">
              <ProductUpdatesButton />
              <HelpButton screenName={pathname === "/inicio" ? "Inicio" : state.modules.find((m) => pathname.startsWith(`/m/${m.code.toLowerCase()}`))?.name} />
              {!isSupport && <NotificationBell />}
              <UserMenu
                name={state.user.full_name}
                email={state.user.email}
                role={state.company.role.name}
                company={state.company.name}
                canSeeSupport={canSeeSupport}
                canSwitch={state.companiesCount > 1}
                onLogout={logout}
              />
            </div>
          </header>
          <main className="flex-1 p-3 pb-20 sm:p-4 sm:pb-20 md:pb-4 lg:p-5">
            {canSeeSupport && <SupportRequestBanner />}
            {!isSupport && <SubscriptionBanner />}
            {!isSupport && <OfflineIndicator />}
            {!isSupport && <LegalPendingGate />}
            {children}
          </main>
        </div>
      </div>
      {!isSupport && <MobileBar role={state.company.role.code} modules={contractorOnly ? state.modules.filter((m) => m.code === "CTR") : state.modules} />}
    </AppContext.Provider>
  );
}
