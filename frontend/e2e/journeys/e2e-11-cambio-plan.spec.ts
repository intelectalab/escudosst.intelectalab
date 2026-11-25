// E2E-11 (docs/12 §5): bajar de plan deja en solo lectura los módulos que salen, sin borrar datos; subir de plan los
// vuelve editables. Con la empresa del plan Profesional de la demostración (Industrias Andinas) y el módulo ATS, que no
// está en Esencial. El equipo de la plataforma cambia el plan; al terminar se deja la suscripción y sus módulos como
// estaban, pase lo que pase.

import type { Page } from "@playwright/test";
import { expect, expectHealthy, settle, test } from "../fixtures";
import { api, apiRaw, day, openAs, psql, uniq, who, whoByEmail } from "./helpers";

const ANDINAS = "01900000-0000-7000-8000-000000000101";

test.use({ user: null });

async function openAts(page: Page) {
  await page.goto("/m/ats", { waitUntil: "domcontentloaded" });
  await settle(page);
  await expectHealthy(page);
  const section = page.locator('main section[data-records="ats_forms"]:not([data-nested])');
  await expect(section.locator("table").first()).toBeVisible({ timeout: 120_000 });
  return section;
}

test("E2E-11 · bajar de plan deja el módulo en solo lectura y subir lo vuelve editable", async ({ browser, request }) => {
  test.setTimeout(8 * 60_000);
  const platform = who("plataforma");
  const admin = whoByEmail("profesional@escudo.test", ANDINAS);
  const tag = uniq("E2E-11");

  const sub = psql(`select s.id || '|' || s.plan_id || '|' || s.status from subscriptions s where s.company_id = '${ANDINAS}' and s.deleted_at is null order by s.started_at desc limit 1`).split("|");
  const [subId, planId, status] = sub;
  const flags = psql(`select id || '|' || mode || '|' || source from feature_flags where company_id = '${ANDINAS}'`).split(/\r?\n/).filter(Boolean).map((l) => l.split("|"));
  const planCode = psql(`select code from plans where id = '${planId}'`);
  expect(planCode, "la empresa de demostración está en Profesional").toBe("P");

  const { context, page } = await openAs(browser, admin);
  let atsId: string | null = null;
  try {
    await test.step("con Profesional, el ATS es editable y tiene un registro", async () => {
      const site = (await api<{ data: { id: string }[] }>(request, admin, "/records/ATS/ats_forms/options/site_id")).data[0].id;
      atsId = (await api<{ data: { id: string } }>(request, admin, "/records/ATS/ats_forms", { method: "POST", data: { task: `${tag} izaje de vigas`, site_id: site, performed_on: day() } })).data.id;
      const section = await openAts(page);
      await expect(page.getByText("Este módulo está en solo lectura.")).toHaveCount(0);
      await expect(section.getByRole("button", { name: "Nuevo ATS", exact: true })).toBeVisible();
      await expect(section.getByRole("cell", { name: `${tag} izaje de vigas`, exact: true })).toBeVisible();
    });

    await test.step("la plataforma baja la suscripción a Esencial", async () => {
      const r = await api<{ data: { direction: string } }>(request, platform, `/internal/subscriptions/${subId}/change-plan`, { method: "POST", data: { plan: "E", reason: `${tag} pedido del cliente` } });
      expect(r.data.direction).toBe("downgrade");
    });

    await test.step("el módulo queda en solo lectura con los datos intactos", async () => {
      const section = await openAts(page);
      await expect(page.getByRole("status").filter({ hasText: "Este módulo está en solo lectura. Tus datos se conservan." })).toBeVisible();
      await expect(section.getByRole("cell", { name: `${tag} izaje de vigas`, exact: true })).toBeVisible();
      await expect(section.getByRole("button", { name: "Nuevo ATS", exact: true })).toHaveCount(0);
      // La ficha se abre sin poder editar.
      await section.getByRole("cell", { name: `${tag} izaje de vigas`, exact: true }).click();
      const dialog = page.getByRole("dialog").filter({ has: page.locator('[aria-label="Partes de la ficha"]') });
      await expect(dialog).toBeVisible();
      await expect(dialog.getByText("Solo lectura.")).toBeVisible();
      await page.keyboard.press("Escape");
      // Tampoco por la API: ni crear ni editar.
      const edit = await apiRaw(request, admin, `/records/ATS/ats_forms/${atsId}`, { method: "PATCH", data: { task: `${tag} cambiado` } });
      expect(edit.status, JSON.stringify(edit.body)).toBe(403);
      const row = await api<{ data: { task: string } }>(request, admin, `/records/ATS/ats_forms/${atsId}`);
      expect(row.data.task).toBe(`${tag} izaje de vigas`);
    });

    await test.step("la plataforma vuelve a subir a Profesional y el módulo es editable", async () => {
      const r = await api<{ data: { direction: string } }>(request, platform, `/internal/subscriptions/${subId}/change-plan`, { method: "POST", data: { plan: "P", reason: `${tag} vuelve a Profesional` } });
      expect(r.data.direction).toBe("upgrade");
      const section = await openAts(page);
      await expect(page.getByText("Este módulo está en solo lectura.")).toHaveCount(0);
      await expect(section.getByRole("button", { name: "Nuevo ATS", exact: true })).toBeVisible();
      await section.getByRole("cell", { name: `${tag} izaje de vigas`, exact: true }).click();
      const dialog = page.getByRole("dialog").filter({ has: page.locator('[aria-label="Partes de la ficha"]') });
      await expect(dialog.getByText("Edita y guarda los cambios.")).toBeVisible();
      await page.keyboard.press("Escape");
      const edit = await apiRaw(request, admin, `/records/ATS/ats_forms/${atsId}`, { method: "PATCH", data: { task: `${tag} izaje de vigas (revisado)` } });
      expect(edit.status, JSON.stringify(edit.body)).toBe(200);
    });
  } finally {
    await context.close();
    // La suscripción y los módulos de la empresa, como estaban.
    psql(`update subscriptions set plan_id = '${planId}', status = '${status}' where id = '${subId}'`);
    for (const [id, mode, source] of flags) psql(`update feature_flags set mode = '${mode}', source = '${source}' where id = '${id}'`);
    if (atsId) psql(`update ats_forms set deleted_at = now() where id = '${atsId}'`);
  }
});
