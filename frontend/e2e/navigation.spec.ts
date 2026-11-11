// Navegación de la aplicación de empresa con el Jefe SST: cada enlace del menú, el plegado, el celular, la ayuda,
// las novedades, el menú del usuario y la búsqueda global.

import { MENU_GROUPS } from "../lib/menu";
import { apiJson, expect, expectHealthy, myModules, settle, test } from "./fixtures";

test.describe("Menú lateral", () => {
  for (const group of MENU_GROUPS) {
    test(`grupo «${group.title}»: cada enlace abre su módulo`, async ({ page, apiToken, companyId }) => {
      test.setTimeout(15 * 60_000);
      const modules = await myModules(page, apiToken, companyId);
      const visible = group.codes
        .map((c) => modules.find((m) => m.code === c))
        .filter((m): m is NonNullable<typeof m> => !!m && m.role_can_use && m.mode !== "disabled_by_company");
      test.skip(visible.length === 0, "El rol no ve ningún módulo de este grupo");

      await page.goto("/inicio", { waitUntil: "domcontentloaded" });
      await settle(page);
      const nav = page.getByRole("navigation", { name: "Módulos" }).first();
      for (const m of visible) {
        await test.step(`${m.code} · ${m.name}`, async () => {
          const link = nav.locator(`a[href="/m/${m.code.toLowerCase()}"]`);
          // Si el grupo está cerrado, se abre con su botón.
          if (!(await link.isVisible())) await nav.getByRole("button", { name: group.title, exact: true }).click();
          await link.click();
          await expect(page).toHaveURL(new RegExp(`/m/${m.code.toLowerCase()}(\\?|$)`));
          await expect(page.getByRole("heading", { level: 1, name: m.name, exact: true })).toBeVisible();
          await expect(link).toHaveAttribute("aria-current", "page");
          await settle(page);
          await expectHealthy(page);
        });
      }
    });
  }

  test("Inicio abre el tablero", async ({ page }) => {
    await page.goto("/m/ins", { waitUntil: "domcontentloaded" });
    await settle(page);
    await page.getByRole("navigation", { name: "Módulos" }).getByRole("link", { name: "Inicio", exact: true }).click();
    await expect(page).toHaveURL(/\/inicio$/);
    await expect(page.getByRole("heading", { level: 1, name: /^Hola, / })).toBeVisible();
    await expectHealthy(page);
  });

  test("Portería aparece para quien opera la garita y abre /garita", async ({ page }) => {
    await page.goto("/inicio", { waitUntil: "domcontentloaded" });
    await settle(page);
    const gate = page.getByRole("navigation", { name: "Módulos" }).getByRole("link", { name: "Portería" });
    // El acceso aparece cuando llegan los permisos (ing.access_events.create).
    await gate.waitFor({ timeout: 60_000 }).catch(() => undefined);
    test.skip(!(await gate.isVisible()), "El usuario no opera la garita");
    await gate.click();
    await expect(page).toHaveURL(/\/garita$/);
    await settle(page);
    await expectHealthy(page);
  });

  test("el botón plegar deja solo íconos y vuelve a abrir", async ({ page }) => {
    await page.goto("/inicio", { waitUntil: "domcontentloaded" });
    await settle(page);
    const aside = page.locator("aside").first();
    const toggle = page.getByRole("button", { name: "Abrir o plegar el menú" });
    const wide = (await aside.boundingBox())!.width;
    await toggle.click();
    await expect.poll(async () => (await aside.boundingBox())!.width).toBeLessThan(wide - 100);
    // Plegado: un grupo abre su lista flotante.
    const group = MENU_GROUPS.find((g) => g.codes.length > 1)!;
    await aside.getByRole("button", { name: group.title, exact: true }).click();
    await expect(page.getByRole("menu", { name: group.title })).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(page.getByRole("menu", { name: group.title })).toBeHidden();
    await toggle.click();
    await expect.poll(async () => (await aside.boundingBox())!.width).toBeGreaterThan(wide - 10);
  });
});

test.describe("Celular (375 px)", () => {
  test.use({ viewport: { width: 375, height: 800 }, isMobile: true, hasTouch: true });

  test("el cajón del menú abre, navega y se cierra", async ({ page }) => {
    await page.goto("/inicio", { waitUntil: "domcontentloaded" });
    await settle(page);
    await expect(page.locator("aside").first()).toBeHidden();
    const toggle = page.getByRole("button", { name: "Abrir o plegar el menú" });
    await toggle.click();
    const drawer = page.getByRole("dialog", { name: "Menú" });
    await expect(drawer).toBeVisible();
    // Un grupo cerrado se abre en el cajón y su enlace navega.
    await drawer.getByRole("button", { name: "Eventos y mejora", exact: true }).click();
    await drawer.locator('a[href="/m/acr"]').click();
    await expect(page).toHaveURL(/\/m\/acr/);
    await expect(drawer).toBeHidden();
    await toggle.click();
    await expect(drawer).toBeVisible();
    await drawer.getByRole("button", { name: "Cerrar menú" }).last().click();
    await expect(drawer).toBeHidden();
  });

  test("la barra inferior tiene accesos y navegan", async ({ page }) => {
    await page.goto("/inicio", { waitUntil: "domcontentloaded" });
    await settle(page);
    const bar = page.getByRole("navigation", { name: "Accesos rápidos" });
    await expect(bar).toBeVisible();
    const links = bar.getByRole("link");
    const n = await links.count();
    expect(n).toBeGreaterThan(0);
    for (let i = 0; i < n; i++) {
      const href = (await links.nth(i).getAttribute("href"))!;
      await links.nth(i).click();
      await expect(page).toHaveURL(new RegExp(`${href.replace(/[/?]/g, "\\$&")}(\\?|$)`));
      await expect(links.nth(i)).toHaveAttribute("aria-current", "page");
      await settle(page);
      await expectHealthy(page);
    }
    // Sin desplazamiento horizontal de la página.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
  });

  test("la búsqueda se abre en el celular", async ({ page }) => {
    await page.goto("/inicio", { waitUntil: "domcontentloaded" });
    await settle(page);
    await page.getByRole("button", { name: "Buscar trabajador, módulo o registro" }).click();
    await expect(page.getByRole("combobox", { name: "Búsqueda global" })).toBeFocused();
    await page.getByRole("button", { name: "Cerrar", exact: true }).click();
    await expect(page.getByRole("combobox", { name: "Búsqueda global" })).toBeHidden();
  });
});

test.describe("Cabecera", () => {
  test("la ayuda abre su panel con «Para esta pantalla»", async ({ page }) => {
    await page.goto("/m/ins", { waitUntil: "domcontentloaded" });
    await settle(page);
    await page.getByRole("button", { name: /Ayuda/ }).first().click();
    const help = page.getByRole("dialog", { name: "Ayuda" });
    await expect(help).toBeVisible();
    await expect(help.getByRole("heading", { name: "Para esta pantalla" })).toBeVisible();
    await expect(help.getByText("Pantalla actual:")).toBeVisible();
    await help.getByPlaceholder("Buscar en la ayuda").fill("inspección");
    await help.getByRole("button", { name: "Buscar", exact: true }).click();
    await expect(help.getByText("Resultados", { exact: true })).toBeVisible();
    await help.getByRole("button", { name: "Cerrar", exact: true }).click();
    await expect(help).toBeHidden();
  });

  test("Novedades abre la lista de lo nuevo", async ({ page }) => {
    await page.goto("/inicio", { waitUntil: "domcontentloaded" });
    await settle(page);
    await page.getByRole("button", { name: /Novedades/ }).click();
    await expect(page.getByText("Lo nuevo en EscudoSST")).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(page.getByText("Lo nuevo en EscudoSST")).toBeHidden();
  });

  test("el menú del usuario lleva a cada opción", async ({ page }) => {
    const items: [RegExp, RegExp][] = [
      [/Mi cuenta y seguridad/, /\/cuenta$/],
      [/Mi firma/, /\/cuenta#firma$/],
      [/Accesos de soporte/, /\/soporte$/],
    ];
    const userButton = page.locator('header button[aria-haspopup="menu"]');
    for (const [name, url] of items) {
      await page.goto("/inicio", { waitUntil: "domcontentloaded" });
      await settle(page);
      await userButton.click();
      await page.getByRole("menuitem", { name }).click();
      await expect(page).toHaveURL(url);
      await settle(page);
      await expectHealthy(page);
    }
    // «Cerrar sesión» no se pulsa aquí (cerraría la sesión compartida de las pruebas): solo se comprueba que esté.
    await userButton.click();
    await expect(page.getByRole("menuitem", { name: /Cerrar sesión/ })).toBeEnabled();
  });

  test("la búsqueda global encuentra un trabajador y abre su ficha 360°", async ({ page, apiToken, companyId }) => {
    const list = await apiJson<{ data: { id: string; full_name: string }[] }>(page, apiToken, companyId, "/records/TRB/workers");
    test.skip(list.data.length === 0, "La empresa de demostración no tiene trabajadores");
    const worker = list.data[0];
    await page.goto("/inicio", { waitUntil: "domcontentloaded" });
    await settle(page);
    await page.getByRole("combobox", { name: "Búsqueda global" }).fill(worker.full_name);
    const results = page.getByRole("listbox", { name: "Resultados de la búsqueda" });
    await expect(results).toBeVisible();
    await results.getByRole("option").filter({ hasText: worker.full_name }).first().click();
    await expect(page).toHaveURL(new RegExp(`/m/trb/${worker.id}$`));
    await expect(page.getByRole("heading", { level: 1, name: worker.full_name })).toBeVisible();
    await settle(page);
    await expectHealthy(page);
  });

  test("la búsqueda global abre un módulo por su nombre", async ({ page }) => {
    await page.goto("/inicio", { waitUntil: "domcontentloaded" });
    await settle(page);
    await page.getByRole("combobox", { name: "Búsqueda global" }).fill("Inspecciones");
    await page.getByRole("listbox", { name: "Resultados de la búsqueda" }).getByRole("option").filter({ hasText: "Inspecciones" }).first().click();
    await expect(page).toHaveURL(/\/m\/ins/);
  });
});
