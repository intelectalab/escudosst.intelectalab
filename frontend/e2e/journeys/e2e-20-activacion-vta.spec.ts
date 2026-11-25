// E2E-20 (docs/12 §5) · Activación por empresa: empresa con la venta de cursos (VTA) desactivada → intentar activar VTA
// sin el aula (AUL) se rechaza → activar AUL y VTA → desactivar VTA → sus datos quedan intactos y sin uso.
// Empresa: Industrias Andinas (plan Profesional, VTA viene apagado; M-VTA RN-VTA-01, M-AUL RN-AUL-21, docs/03 §7.1).
// Con VTA desactivado por la empresa el módulo no aparece en el menú ni se puede usar (docs/03 §7.1, docs/07: no se muestra);
// las ofertas ya creadas se conservan y el catálogo público responde «no disponible» (M-VTA criterio 1).
// Al terminar se deja la empresa como estaba (AUL activo, VTA desactivado).

import type { Locator, Page } from "@playwright/test";
import { expect, expectHealthy, settle, test } from "../fixtures";
import { apiRaw, openAs, whoByEmail, type Who } from "./helpers";
import { lit, one, psqlRows, slugSuffix } from "./_vta";

const ANDINAS = "01900000-0000-7000-8000-000000000101";

test.use({ user: null });

test.describe.serial("E2E-20 · Activación de la venta de cursos por empresa", () => {
  const sfx = slugSuffix();
  let admin: Who;
  let snapshot: string[][] = [];
  let course = "";
  let offer = "";

  test.beforeAll(() => {
    admin = whoByEmail("profesional@escudo.test", ANDINAS);
    snapshot = psqlRows(`select module_code, mode, company_enabled, source from feature_flags where company_id = ${lit(ANDINAS)} and module_code in ('AUL', 'VTA') and deleted_at is null order by module_code`);
    // Una oferta de curso que la empresa ya tenía (para comprobar que desactivar no borra datos).
    course = one(`insert into courses (company_id, code, title, hours, status, published_at) values (${lit(ANDINAS)}, ${lit(`E2E20-${sfx}`.slice(0, 20).toUpperCase())}, ${lit(`Curso E2E-20 ${sfx}`)}, 2, 'published', now()) returning id`);
    offer = one(`insert into course_offers (company_id, course_id, slug, title, list_price, is_public, status) values (${lit(ANDINAS)}, ${lit(course)}, ${lit(`e2e20-${sfx}`)}, ${lit(`Curso E2E-20 ${sfx}`)}, 50, true, 'published') returning id`);
  });

  test.afterAll(async ({ playwright }) => {
    // Vuelve al estado inicial por la misma vía que la empresa (y, si algo falla, directo en la base).
    const req = await playwright.request.newContext();
    try {
      const before = Object.fromEntries(snapshot.map(([code, , enabled]) => [code, enabled === "t"]));
      await apiRaw(req, admin, "/aul/settings", { method: "PUT", data: { vta: false } });
      await apiRaw(req, admin, "/aul/settings", { method: "PUT", data: { aul: before.AUL ?? true } });
      if (before.VTA) await apiRaw(req, admin, "/aul/settings", { method: "PUT", data: { vta: true } });
    } finally {
      await req.dispose();
      for (const [code, mode, enabled, source] of snapshot) {
        one(`update feature_flags set mode = ${lit(mode)}, company_enabled = ${enabled === "t"}, source = ${lit(source)} where company_id = ${lit(ANDINAS)} and module_code = ${lit(code)} and deleted_at is null returning id`);
      }
      one(`update course_offers set deleted_at = now(), status = 'archived' where id = ${lit(offer)} returning id`);
      one(`update courses set deleted_at = now() where id = ${lit(course)} returning id`);
    }
  });

  test("con VTA desactivado no aparece y el catálogo público no está disponible", async ({ browser }) => {
    test.setTimeout(6 * 60_000);
    expect(Object.fromEntries(snapshot.map(([c, m]) => [c, m]))).toMatchObject({ AUL: "enabled", VTA: "disabled_by_company" });
    const { context, page } = await openAs(browser, admin);
    try {
      await expectVtaHidden(page);
      await page.goto(`/cursos/${ANDINAS}`, { waitUntil: "domcontentloaded" });
      await expect(page.getByText("Catálogo no disponible")).toBeVisible({ timeout: 120_000 });
    } finally {
      await context.close();
    }
  });

  test("activar VTA sin el aula se rechaza; con el aula activa, se activa", async ({ browser }) => {
    test.setTimeout(8 * 60_000);
    const { context, page } = await openAs(browser, admin);
    try {
      await page.goto("/m/aul", { waitUntil: "domcontentloaded" });
      await settle(page, 180_000);
      const aul = page.getByRole("checkbox", { name: /Aula virtual activa/ });
      const vta = page.getByRole("checkbox", { name: /Venta de cursos activa/ });
      const status = page.locator("section p[role=status]");
      await expect(aul).toBeChecked({ timeout: 120_000 });
      await expect(vta).not.toBeChecked();

      // Sin aula: la venta no se puede activar.
      await toggle(page, aul);
      await expect(status).toHaveText("Cambio guardado.", { timeout: 60_000 });
      await expect(aul).not.toBeChecked();
      await toggle(page, vta);
      await expect(status).toHaveText("Para vender cursos activa primero el aula virtual.", { timeout: 60_000 });
      await expect(vta).not.toBeChecked();
      expect(one(`select mode from feature_flags where company_id = ${lit(ANDINAS)} and module_code = 'VTA' and deleted_at is null`)).toBe("disabled_by_company");

      // Aula y luego venta.
      await toggle(page, aul);
      await expect(status).toHaveText("Cambio guardado.", { timeout: 60_000 });
      await toggle(page, vta);
      await expect(status).toHaveText("Cambio guardado.", { timeout: 60_000 });
      await expect(vta).toBeChecked();
      // Con la venta activa, el aula no se puede desactivar.
      await toggle(page, aul);
      await expect(status).toHaveText(/No se puede desactivar el aula mientras la venta de cursos esté activa/, { timeout: 60_000 });
      await expect(aul).toBeChecked();
      await expectHealthy(page);

      // El módulo aparece con la oferta que ya existía y el catálogo público la muestra.
      await page.goto(`/m/vta?t=course_offers`, { waitUntil: "domcontentloaded" });
      await settle(page, 180_000);
      await expect(page.getByRole("heading", { level: 1, name: "Venta de cursos y pagos" })).toBeVisible({ timeout: 120_000 });
      await expect(page.locator(`text=Curso E2E-20 ${sfx} >> visible=true`).first()).toBeVisible({ timeout: 120_000 });
      await page.goto(`/cursos/${ANDINAS}`, { waitUntil: "domcontentloaded" });
      await expect(page.getByText(`Curso E2E-20 ${sfx}`).first()).toBeVisible({ timeout: 120_000 });
    } finally {
      await context.close();
    }
  });

  test("al desactivar VTA los datos se conservan pero el módulo ya no se usa", async ({ browser, playwright }) => {
    test.setTimeout(6 * 60_000);
    const { context, page } = await openAs(browser, admin);
    try {
      await page.goto("/m/aul", { waitUntil: "domcontentloaded" });
      await settle(page, 180_000);
      const vta = page.getByRole("checkbox", { name: /Venta de cursos activa/ });
      await expect(vta).toBeChecked({ timeout: 120_000 });
      await toggle(page, vta);
      await expect(page.locator("section p[role=status]")).toHaveText("Cambio guardado.", { timeout: 60_000 });
      await expect(vta).not.toBeChecked();

      await expectVtaHidden(page);
      await page.goto(`/cursos/${ANDINAS}`, { waitUntil: "domcontentloaded" });
      await expect(page.getByText("Catálogo no disponible")).toBeVisible({ timeout: 120_000 });
    } finally {
      await context.close();
    }
    // Datos intactos; la API del módulo ya no acepta escrituras.
    expect(one(`select count(*) from course_offers where id = ${lit(offer)} and deleted_at is null and status = 'published'`)).toBe("1");
    const req = await playwright.request.newContext();
    try {
      const sell = await apiRaw(req, admin, "/vta/sell", { method: "POST", data: { course_offer_id: offer, buyer_type: "person", document_type: "DNI", document_number: "71234567", first_name: "No", last_name: "Vende", email: `e2e20.${sfx}@example.test` } });
      expect(sell.status).toBe(403);
    } finally {
      await req.dispose();
    }
  });
});

/** Toca el interruptor y espera la respuesta del servidor (el estado del control cambia al responder). */
async function toggle(page: Page, box: Locator) {
  await Promise.all([
    page.waitForResponse((r) => r.url().endsWith("/aul/settings") && r.request().method() === "PUT", { timeout: 60_000 }),
    box.click(),
  ]);
}

/** VTA no está en el menú y su pantalla dice «Página no disponible». */
async function expectVtaHidden(page: Page) {
  await page.goto("/inicio", { waitUntil: "domcontentloaded" });
  await settle(page, 180_000);
  await expect(page.getByRole("navigation", { name: "Módulos" }).locator('a[href="/m/vta"]')).toHaveCount(0);
  await page.goto("/m/vta", { waitUntil: "domcontentloaded" });
  await settle(page, 180_000);
  await expect(page.getByRole("heading", { name: "Página no disponible" })).toBeVisible({ timeout: 120_000 });
}
