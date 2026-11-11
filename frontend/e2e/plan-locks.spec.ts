// Candados por plan: con la empresa del plan Esencial, los módulos que no están en su plan muestran la pantalla
// «Disponible en …» con el enlace a los planes, y el menú los marca con candado.

import { expect, expectHealthy, myModules, settle, test } from "./fixtures";

test.use({ user: "esencial" });

test("los módulos fuera del plan muestran el candado", async ({ page, apiToken, companyId }) => {
  test.setTimeout(5 * 60_000);
  const modules = await myModules(page, apiToken, companyId);
  const locked = modules.filter((m) => m.mode === "locked" && m.role_can_use);
  expect(locked.length, "el plan Esencial tiene módulos bloqueados").toBeGreaterThan(0);

  // Uno de plan superior y uno de complemento (si hay), más algunos al azar fijo.
  const sample = [locked.find((m) => m.required_plan !== "+"), locked.find((m) => m.required_plan === "+"), ...locked.slice(0, 4)].filter(
    (m, i, a): m is NonNullable<typeof m> => !!m && a.findIndex((x) => x?.code === m.code) === i,
  );
  for (const m of sample) {
    await test.step(`${m.code} · ${m.name}`, async () => {
      await page.goto(`/m/${m.code.toLowerCase()}`, { waitUntil: "domcontentloaded" });
      await settle(page, 90_000);
      await expect(page.getByRole("heading", { level: 1, name: m.name, exact: true })).toBeVisible();
      await expect(page.getByRole("heading", { name: /^Disponible en / })).toBeVisible();
      const link = page.getByRole("link", { name: m.required_plan === "+" ? /Ver complementos/ : /Comparar planes/ });
      await expect(link).toHaveAttribute("href", m.required_plan === "+" ? "/complementos" : "/planes");
      await expect(page.getByText("tus datos no se borran")).toBeVisible();
      // No se muestran registros del módulo.
      await expect(page.locator("section[data-records]")).toHaveCount(0);
      await expectHealthy(page);
    });
  }
});

test("el menú marca con candado lo que no está en el plan", async ({ page, apiToken, companyId }) => {
  const modules = await myModules(page, apiToken, companyId);
  const locked = modules.find((m) => m.mode === "locked" && m.role_can_use && m.code !== "SUN");
  test.skip(!locked, "Sin módulos bloqueados");
  await page.goto("/inicio", { waitUntil: "domcontentloaded" });
  await settle(page, 90_000);
  const link = page.getByRole("navigation", { name: "Módulos" }).locator(`a[href="/m/${locked!.code.toLowerCase()}"]`);
  if (!(await link.isVisible())) {
    // Abre el grupo del módulo.
    const groups = page.getByRole("navigation", { name: "Módulos" }).locator("button[aria-expanded=false]");
    for (let i = (await groups.count()) - 1; i >= 0; i--) await groups.nth(i).click();
  }
  await expect(link).toHaveAttribute("title", /^Disponible en /);
});

test("un módulo del plan sí abre sus registros", async ({ page }) => {
  await page.goto("/m/ins", { waitUntil: "domcontentloaded" });
  await settle(page, 90_000);
  await expect(page.getByRole("heading", { name: /^Disponible en / })).toHaveCount(0);
  await expect(page.locator("section[data-records]").first()).toBeVisible();
});
