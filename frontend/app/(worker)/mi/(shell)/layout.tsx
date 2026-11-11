"use client";

// App del trabajador (PWA, docs/07 §5.2): estructura simple para el celular, sin el menú de gestión.
// Cabecera con la empresa y tu nombre, botón para volver y «Salir». Sin sesión: ingreso con PIN si este equipo ya se
// activó, o el ingreso normal.

import { usePathname, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { AppContext, type AppState } from "@/components/AppContext";
import { LegalPendingGate } from "@/components/LegalPendingGate";
import { OfflineIndicator } from "@/components/OfflineIndicator";
import { Icon } from "@/components/ui/Icon";
import { WorkerContext } from "@/components/worker/WorkerContext";
import { api, ApiError } from "@/lib/api";
import { pendingCount } from "@/lib/offline";
import { session } from "@/lib/session";
import type { CompanyOption, ModuleItem, User } from "@/lib/types";
import { errorText, workerApi, workerDevice, type WorkerHome } from "@/lib/workerApp";

export default function WorkerLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [app, setApp] = useState<AppState | null>(null);
  const [home, setHome] = useState<WorkerHome | null>(null);
  const [error, setError] = useState<string | null>(null);

  const reload = useCallback(async () => {
    setHome(await workerApi<WorkerHome>(""));
  }, []);

  useEffect(() => {
    if (!session.getToken()) {
      router.replace(workerDevice.get() ? "/mi/ingresar" : "/login");
      return;
    }
    if (!session.getCompanyId()) {
      const d = workerDevice.get();
      if (d) session.setCompanyId(d.company_id);
      else return router.replace("/select-company");
    }
    const companyId = session.getCompanyId();
    Promise.all([api<User>("/me"), api<{ data: CompanyOption[] }>("/me/companies")])
      .then(async ([user, companies]) => {
        const company = companies.data.find((c) => c.company_id === companyId);
        if (!company) return router.replace("/select-company");
        const [modules, h] = await Promise.all([api<{ data: ModuleItem[] }>("/me/modules", { withCompany: true }), workerApi<WorkerHome>("")]);
        setApp({ user, company, modules: modules.data, companiesCount: companies.data.length });
        setHome(h);
      })
      .catch((e) => {
        if (e instanceof ApiError && e.status === 401) router.replace(workerDevice.get() ? "/mi/ingresar" : "/login");
        else setError(errorText(e));
      });
  }, [router]);

  async function logout() {
    const pending = await pendingCount().catch(() => 0);
    if (pending > 0 && !window.confirm(`Tienes ${pending} reporte(s) sin enviar. Se guardan en este equipo y se envían cuando vuelvas a ingresar. ¿Salir igual?`)) return;
    await api("/auth/logout", { method: "POST" }).catch(() => undefined);
    session.clear();
    router.replace(workerDevice.get() ? "/mi/ingresar" : "/login");
  }

  if (error) {
    return (
      <main className="mx-auto min-h-screen max-w-lg space-y-3 bg-bg p-4">
        <p role="alert" className="rounded-2xl border border-line bg-surface p-4 text-bad">{error}</p>
        <button onClick={logout} className="h-12 w-full rounded-xl border border-line bg-surface font-semibold">Salir</button>
      </main>
    );
  }
  if (!app || !home) return <main className="min-h-screen bg-bg p-4 text-muted">Cargando…</main>;

  const isHome = pathname === "/mi";
  return (
    <AppContext.Provider value={app}>
      <WorkerContext.Provider value={{ home, reload }}>
        <div className="min-h-screen bg-bg text-fg">
          <header className="sticky top-0 z-40 border-b border-line bg-surface pt-[env(safe-area-inset-top)]">
            <div className="mx-auto flex h-16 max-w-lg items-center gap-2 px-4">
              {!isHome && (
                <button onClick={() => (window.history.length > 1 ? router.back() : router.push("/mi"))} aria-label="Volver"
                  className="grid h-12 w-12 shrink-0 place-items-center rounded-xl border border-line hover:border-brand">
                  <Icon name="back" size={20} />
                </button>
              )}
              <div className="min-w-0 flex-1">
                <p className="truncate font-display font-semibold leading-tight">{home.company.name}</p>
                <p className="truncate text-sm text-muted">{home.worker.full_name}</p>
              </div>
              <button onClick={logout} className="inline-flex h-12 shrink-0 items-center gap-1.5 rounded-xl border border-line px-3 text-sm font-semibold hover:border-brand">
                <Icon name="logout" size={18} /> Salir
              </button>
            </div>
          </header>
          <main className="mx-auto max-w-lg space-y-4 px-4 pb-[calc(2rem+env(safe-area-inset-bottom))] pt-4">
            <OfflineIndicator />
            <LegalPendingGate />
            {children}
          </main>
        </div>
      </WorkerContext.Provider>
    </AppContext.Provider>
  );
}
