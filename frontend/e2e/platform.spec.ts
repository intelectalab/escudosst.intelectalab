// Panel de la plataforma (equipo de EscudoSST): cada página principal carga con su título y sus partes.

import { expect, expectHealthy, settle, test } from "./fixtures";

test.use({ user: "plataforma" });

const PAGES: { path: string; title: RegExp; texts?: (string | RegExp)[] }[] = [
  { path: "/plataforma", title: /./ },
  { path: "/plataforma/cuentas", title: /Cuentas y suscripciones/ },
  { path: "/plataforma/planes", title: /Planes/ },
  { path: "/plataforma/consultas", title: /Consultas de empresas/ },
  { path: "/plataforma/asistente", title: /Asistente IA/ },
  { path: "/plataforma/sitio", title: /./ },
  { path: "/plataforma/salud", title: /./, texts: ["Copias de seguridad"] },
  { path: "/plataforma/metricas", title: /./ },
  { path: "/plataforma/bitacora", title: /./ },
  { path: "/plataforma/equipo", title: /./ },
  { path: "/plataforma/facturacion", title: /./ },
  { path: "/plataforma/avisos", title: /./ },
];

for (const p of PAGES) {
  test(`página ${p.path}`, async ({ page }) => {
    await page.goto(p.path, { waitUntil: "domcontentloaded" });
    await settle(page, 90_000);
    await expect(page).toHaveURL(new RegExp(`${p.path}$`));
    await expect(page.locator("main .animate-pulse")).toHaveCount(0, { timeout: 90_000 });
    await expect(page.locator("main").getByRole("heading").first()).toHaveText(p.title, { timeout: 30_000 });
    for (const t of p.texts ?? []) await expect(page.getByText(t).first()).toBeVisible();
    await expectHealthy(page);
  });
}

test("catálogos: cada pestaña, incluido el catálogo SCAT", async ({ page }) => {
  await page.goto("/plataforma/catalogos", { waitUntil: "domcontentloaded" });
  await settle(page, 90_000);
  await expect(page.getByRole("heading", { level: 1, name: /Catálogos globales/ })).toBeVisible();
  const tabs = page.getByRole("tablist", { name: "Catálogos" }).getByRole("tab");
  const names = (await tabs.allTextContents()).map((s) => s.trim());
  expect(names.join("|")).toContain("Catálogo SCAT");
  for (let i = 0; i < names.length; i++) {
    await tabs.nth(i).click();
    await expect(tabs.nth(i)).toHaveAttribute("aria-selected", "true");
    await settle(page);
    await expectHealthy(page);
  }
  await page.getByRole("tab", { name: /Catálogo SCAT/ }).click();
  await expect(page.locator("main table, main ul").first()).toBeVisible();
});

test("proveedores: copias de seguridad externas y WhatsApp con sus campos", async ({ page }) => {
  await page.goto("/plataforma/proveedores", { waitUntil: "domcontentloaded" });
  await settle(page, 90_000);
  await expect(page.getByRole("heading", { level: 1, name: /Proveedores y claves/ })).toBeVisible();
  for (const name of ["WhatsApp", "Copias de seguridad externas"]) {
    const card = page.locator("section").filter({ has: page.getByRole("heading", { level: 2, name, exact: true }) });
    await expect(card).toBeVisible();
    await expect(card.getByText("Proveedor", { exact: true })).toBeVisible();
    await expect(card.getByText("Modo", { exact: true })).toBeVisible();
    expect(await card.locator("input, select").count(), `${name}: campos de configuración`).toBeGreaterThan(2);
  }
  await expectHealthy(page);
});

test("sitio: las secciones del administrador del sitio abren", async ({ page }) => {
  test.setTimeout(20 * 60_000);
  await page.goto("/plataforma/sitio", { waitUntil: "domcontentloaded" });
  await settle(page, 90_000);
  const links = await page
    .locator('a[href^="/plataforma/sitio/"]')
    .evaluateAll((as) => [...new Set(as.map((a) => a.getAttribute("href")!).filter((h) => /^\/plataforma\/sitio\/[a-z-]+$/.test(h)))]);
  expect(links.length).toBeGreaterThan(3);
  for (const href of links) {
    await test.step(href, async () => {
      await page.goto(href, { waitUntil: "domcontentloaded" });
      await settle(page, 90_000);
      await expect(page.locator("main .animate-pulse")).toHaveCount(0, { timeout: 90_000 });
      await expect(page.locator("main").getByRole("heading").first()).toBeVisible();
      await expectHealthy(page);
    });
  }
});
