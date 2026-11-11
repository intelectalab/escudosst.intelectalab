// Base de las pruebas: abre el navegador ya con la sesión del usuario elegido (test.use({ user: "jefe" })) y vigila
// que la página no tenga errores sin capturar en la consola ni llamadas a la API que fallen con 5xx.

import { test as base, expect, type Page } from "@playwright/test";
import { sessionFor, type UserKey } from "./support/auth";

export const API_URL = process.env.E2E_API_URL ?? "http://localhost:8000/api/v1";

type Problems = { console: string[]; server: string[] };

// Mensajes de consola que no son fallas de la aplicación.
const IGNORED_CONSOLE = [
  /Failed to load resource: the server responded with a status of 4\d\d/i, // 401/403/404/422 esperados (p. ej. validación)
  /Download the React DevTools/i,
  /\[Fast Refresh\]/i,
  /\[HMR\]/i,
  /ERR_ABORTED/i,
  /net::ERR_FAILED/i, // peticiones canceladas al cambiar de página
  /net::ERR_NETWORK_CHANGED|net::ERR_CONNECTION_(RESET|CLOSED|REFUSED)/i, // cortes de red del equipo, no de la app
];

export const test = base.extend<{ user: UserKey | null; problems: Problems; apiToken: string | null; companyId: string | null }>({
  user: ["jefe", { option: true }],

  apiToken: async ({ user }, use) => use(user ? sessionFor(user).token : null),
  companyId: async ({ user }, use) => use(user ? sessionFor(user).company_id : null),

  problems: [
    async ({ page }, use, testInfo) => {
      const problems: Problems = { console: [], server: [] };
      page.on("console", (msg) => {
        if (msg.type() !== "error") return;
        const text = msg.text();
        if (IGNORED_CONSOLE.some((r) => r.test(text))) return;
        problems.console.push(text.slice(0, 400));
      });
      page.on("pageerror", (err) => problems.console.push(`pageerror: ${err.message.slice(0, 400)}`));
      page.on("response", (res) => {
        if (res.status() >= 500 && res.url().includes("/api/")) problems.server.push(`${res.status()} ${res.request().method()} ${res.url()}`);
      });
      await use(problems);
      if (testInfo.status === testInfo.expectedStatus) {
        expect(problems.server, "llamadas a la API con error 5xx").toEqual([]);
        expect(problems.console, "errores sin capturar en la consola").toEqual([]);
      }
    },
    { auto: true },
  ],

  page: async ({ page, user }, use) => {
    if (user) {
      const s = sessionFor(user);
      await page.addInitScript(
        ([token, company]) => {
          // Solo la primera vez en esta pestaña: si la prueba cierra la sesión, no se vuelve a poner.
          if (window.sessionStorage.getItem("escudo.e2e")) return;
          window.sessionStorage.setItem("escudo.e2e", "1");
          window.sessionStorage.setItem("escudo.token", token);
          if (company) window.sessionStorage.setItem("escudo.companyId", company);
          // Sin el recorrido de bienvenida ni avisos que tapen la pantalla.
          try {
            window.localStorage.setItem("escudo.updates.seen", String(Date.now()));
          } catch {
            /* sin almacenamiento */
          }
        },
        [s.token, s.company_id ?? ""] as const,
      );
    }
    await use(page);
  },
});

export { expect };

/** Espera a que la pantalla termine de cargar (sin «Cargando…» a la vista). */
export async function settle(page: Page, timeout = 120_000) {
  await page.waitForLoadState("domcontentloaded");
  await expect(page.getByText(/^Cargando…$/).first()).toBeHidden({ timeout });
  // Esqueletos de carga (bloques que «laten»): se espera a que desaparezcan, sin fallar si alguno queda (p. ej. un
  // indicador «en vivo» que late siempre).
  await expect(page.locator("main .animate-pulse").first()).toBeHidden({ timeout: Math.min(timeout, 45_000) }).catch(() => undefined);
}

/** Comprueba que no se muestre la pantalla de error de Next.js ni «Página no disponible». */
export async function expectHealthy(page: Page) {
  await expect(page.locator("nextjs-portal [data-nextjs-dialog], [data-nextjs-dialog-overlay]")).toHaveCount(0);
  await expect(page.getByRole("heading", { name: "Página no disponible" })).toHaveCount(0);
  await expect(page.getByText(/Application error|Unhandled Runtime Error/)).toHaveCount(0);
}

/** Llamada directa a la API con la sesión de la prueba (para preparar o comprobar datos). */
export async function apiJson<T>(page: Page, token: string | null, company: string | null, path: string, init: { method?: string; data?: unknown } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (token) headers.Authorization = `Bearer ${token}`;
  if (company) headers["X-Company-Id"] = company;
  const res = await page.request.fetch(`${API_URL}${path}`, { method: init.method ?? "GET", headers, data: init.data, timeout: 120_000 });
  if (!res.ok()) throw new Error(`${init.method ?? "GET"} ${path} → ${res.status()}: ${(await res.text()).slice(0, 300)}`);
  return (res.status() === 204 ? null : await res.json()) as T;
}

export type ModuleItem = { code: string; name: string; mode: string; role_can_use: boolean; required_plan: string };

/** Módulos del usuario en la empresa activa (lo mismo que arma el menú). */
export async function myModules(page: Page, token: string | null, company: string | null) {
  return (await apiJson<{ data: ModuleItem[] }>(page, token, company, "/me/modules")).data;
}

/** Espera a que React tome la página (hidratación): antes, un clic o lo escrito en un campo se pierden. */
export async function waitForHydration(page: Page, selector = "button", timeout = 60_000) {
  await page.waitForFunction(
    (sel) => {
      const el = document.querySelector(sel);
      return !!el && Object.keys(el).some((k) => k.startsWith("__reactFiber") || k.startsWith("__reactProps"));
    },
    selector,
    { timeout },
  );
}
