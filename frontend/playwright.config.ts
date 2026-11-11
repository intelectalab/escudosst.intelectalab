// Pruebas de interfaz de punta a punta (Playwright) sobre el servidor de desarrollo y la API local.
// Usa el Google Chrome instalado (channel «chrome»): no descarga navegadores.
// Ejecutar: npm run test:e2e  (con el frontend en http://localhost:3000 y la API en Docker).

import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./e2e",
  outputDir: "./test-results",
  timeout: 5 * 60_000,
  expect: { timeout: 60_000 },
  retries: 1,
  workers: 2,
  fullyParallel: true,
  reporter: [["list"], ["html", { outputFolder: "e2e-report", open: "never" }]],
  globalSetup: "./e2e/global-setup.ts",
  globalTeardown: "./e2e/global-teardown.ts",
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:3000",
    channel: "chrome",
    // Sin el service worker de la app (modo sin conexión): si no, page.route y los eventos de respuesta no ven las
    // llamadas a la API que pasan por él.
    serviceWorkers: "block",
    locale: "es-PE",
    timezoneId: "America/Lima",
    viewport: { width: 1366, height: 860 },
    screenshot: "only-on-failure",
    trace: "retain-on-failure",
    actionTimeout: 30_000,
    navigationTimeout: 120_000,
  },
  projects: [{ name: "chrome", use: { ...devices["Desktop Chrome"], channel: "chrome", viewport: { width: 1366, height: 860 } } }],
});
