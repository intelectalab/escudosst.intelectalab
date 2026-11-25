// E2E-04 (docs/12 §5): inspección con hallazgo → acción correctiva → evidencia → verificación por otra persona.
// El Jefe SST ejecuta el checklist de una inspección programada; el «No» (con su foto) crea el hallazgo; desde el hallazgo
// crea la acción correctiva para otra persona; la responsable agrega la evidencia y la envía a verificación (no puede
// verificarla ella misma); el Jefe SST la verifica como eficaz: el hallazgo queda resuelto y la inspección, cerrada.

import type { Locator } from "@playwright/test";
import { expect, expectHealthy, settle, test } from "../fixtures";
import { api, apiRaw, day, DEMO_COMPANY, openAs, pngPixel, psql, uniq, who } from "./helpers";

test.use({ user: "jefe" });

/** Elige una opción de un campo de búsqueda (RefSelect). */
async function pickRef(combo: Locator, text: string) {
  await combo.click();
  await combo.fill(text);
  const opt = combo.page().getByRole("option", { name: new RegExp(text) }).first();
  await expect(opt).toBeVisible({ timeout: 180_000 });
  await opt.click();
}

const TEMPLATE = "Inspección de botiquín";

test("E2E-04 · inspección con hallazgo → acción → evidencia → verificación por otra persona", async ({ page, browser }) => {
  test.setTimeout(30 * 60_000);
  const jefe = who("jefe");
  const otro = who("aprobador");
  const tag = uniq("E2E04");
  const site = psql(`select id from sites where company_id = '${DEMO_COMPANY}' and deleted_at is null order by created_at limit 1`);
  const tpl = psql(`select id from inspection_templates where name = '${TEMPLATE}' and status = 'active' and deleted_at is null order by created_at limit 1`);
  expect(tpl, "plantilla de demostración").toBeTruthy();

  const ins = (await api(page.request, jefe, "/records/INS/inspections", {
    method: "POST",
    data: { title: `Inspección ${tag}`, inspection_type: "first_aid", template_id: tpl, site_id: site, scheduled_on: day(0) },
  })).data.id as string;
  let finding = "";
  let action = "";

  try {
    // ------------------------------------------------ 1. Checklist con un «No» (con foto) → hallazgo
    await test.step("se ejecuta el checklist y el «No» crea un hallazgo", async () => {
      await page.goto(`/m/ins?t=inspections&open=${ins}`, { waitUntil: "domcontentloaded" });
      await settle(page);
      const card = page.getByRole("dialog", { name: new RegExp(`Inspección ${tag}`) });
      await expect(card).toBeVisible({ timeout: 240_000 });
      await card.getByRole("button", { name: "Ejecutar checklist" }).click();
      const list = page.getByRole("dialog", { name: "Checklist de la inspección" });
      await expect(list).toBeVisible();
      const groups = list.getByRole("radiogroup");
      await expect(groups).toHaveCount(3);
      await groups.nth(0).getByRole("radio", { name: "Sí" }).click();
      await groups.nth(1).getByRole("radio", { name: "No" }).click();
      await groups.nth(2).getByRole("radio", { name: "Sí" }).click();
      await expect(list.getByText("Este «no cumple» necesita foto de evidencia.")).toBeVisible();
      await list.locator('input[type="file"]').setInputFiles({ name: "botiquin.png", mimeType: "image/png", buffer: pngPixel() });
      await expect(list.getByText(/Foto adjunta/)).toBeVisible({ timeout: 240_000 });
      await list.getByRole("button", { name: "Finalizar inspección" }).click();
      await expect(list).toBeHidden({ timeout: 300_000 });
      await expect(card.getByText(/Finalizada: .* 1 hallazgo\(s\)/)).toBeVisible({ timeout: 180_000 });

      const checklist = await api(page.request, jefe, `/ins/inspections/${ins}/checklist`);
      expect(checklist.data.findings).toHaveLength(1);
      finding = checklist.data.findings[0].id;
    });

    // ------------------------------------------------ 2. Del hallazgo a la acción correctiva (para otra persona)
    await test.step("desde el hallazgo se crea la acción correctiva", async () => {
      await page.goto(`/m/ins?t=findings&open=${finding}`, { waitUntil: "domcontentloaded" });
      await settle(page);
      await expect(page.getByText("Este hallazgo aún no tiene acción correctiva.")).toBeVisible({ timeout: 240_000 });
      await page.getByRole("button", { name: "Crear acción correctiva" }).click();
      const modal = page.getByRole("dialog", { name: "Nueva acción correctiva" });
      await modal.locator("label", { hasText: "Qué se va a hacer" }).locator("input").fill(`Reponer el botiquín ${tag}`);
      await pickRef(modal.getByRole("combobox", { name: "Responsable" }), "Patricia Salazar");
      await modal.locator('input[type="date"]').fill(day(7));
      await modal.getByRole("button", { name: "Crear", exact: true }).click();
      await expect(modal).toBeHidden({ timeout: 300_000 });
      await expect.poll(async () => (await api(page.request, jefe, `/records/INS/findings/${finding}`)).data.corrective_action_id ?? "", { timeout: 240_000 }).not.toBe("");
      action = (await api(page.request, jefe, `/records/INS/findings/${finding}`)).data.corrective_action_id;
    });

    // ------------------------------------------------ 3. La responsable agrega la evidencia y la envía a verificación
    await test.step("la responsable sube la evidencia y la envía a verificación", async () => {
      const { context, page: resp } = await openAs(browser, otro);
      try {
        await resp.goto(`/m/acr?t=corrective_actions&open=${action}`, { waitUntil: "domcontentloaded" });
        await settle(resp);
        const proc = resp.getByRole("region", { name: "Proceso de la acción" });
        await expect(proc).toBeVisible({ timeout: 240_000 });
        await expect(proc.getByText("Evidencias (0)")).toBeVisible();
        // Sin evidencia no se envía a verificación.
        await proc.getByRole("button", { name: "Enviar a verificación" }).click();
        await expect(proc.getByRole("alert")).toBeVisible({ timeout: 240_000 });
        await proc.getByLabel("Nueva evidencia").fill(`Botiquín repuesto ${tag}`);
        await proc.getByLabel("Foto o documento de la evidencia").setInputFiles({ name: "repuesto.png", mimeType: "image/png", buffer: pngPixel() });
        await proc.getByRole("button", { name: "Agregar" }).click();
        await expect(proc.getByText("Evidencias (1)")).toBeVisible({ timeout: 120_000 });
        await expect(proc.getByText(`Botiquín repuesto ${tag}`)).toBeVisible();
        await proc.getByRole("button", { name: "Enviar a verificación" }).click();
        await expect.poll(async () => (await api(resp.request, otro, `/records/ACR/corrective_actions/${action}`)).data.status, { timeout: 120_000 }).toBe("pending_verification");
        // Quien ejecutó la acción no la verifica.
        const self = await apiRaw(resp.request, otro, `/records/ACR/corrective_actions/${action}`, { method: "PATCH", data: { status: "verified", effectiveness: "effective" } });
        expect(self.status).toBe(422);
        await expectHealthy(resp);
      } finally {
        await context.close();
      }
    });

    // ------------------------------------------------ 4. Otra persona verifica: hallazgo resuelto, inspección cerrada
    await test.step("el Jefe SST la verifica como eficaz", async () => {
      await page.goto(`/m/acr?t=corrective_actions&open=${action}`, { waitUntil: "domcontentloaded" });
      await settle(page);
      const proc = page.getByRole("region", { name: "Proceso de la acción" });
      await expect(proc.getByText("Evidencias (1)")).toBeVisible({ timeout: 240_000 });
      await proc.getByRole("button", { name: "Verificar" }).click();
      const modal = page.getByRole("dialog", { name: "Verificar la acción" });
      await modal.getByLabel(/Observaciones de la verificación/).fill("Revisado en sitio: botiquín completo.");
      await modal.getByRole("button", { name: "Fue eficaz", exact: true }).click();
      await expect(modal).toBeHidden({ timeout: 300_000 });
      await expect.poll(async () => (await api(page.request, jefe, `/records/ACR/corrective_actions/${action}`)).data.status, { timeout: 240_000 }).toBe("verified");
      expect((await api(page.request, jefe, `/records/INS/findings/${finding}`)).data.status).toBe("resolved");
      expect((await api(page.request, jefe, `/records/INS/inspections/${ins}`)).data.status).toBe("closed");

      await page.goto(`/m/acr?t=corrective_actions&open=${action}`, { waitUntil: "domcontentloaded" });
      await settle(page);
      await expect(page.getByText("Verificada como eficaz. Su origen quedó atendido.")).toBeVisible({ timeout: 240_000 });
      await expectHealthy(page);
    });
  } finally {
    psql(`update inspections set deleted_at = now() where id = '${ins}'`);
    if (finding) psql(`update findings set deleted_at = now() where id = '${finding}'`);
    if (action) psql(`update corrective_actions set deleted_at = now() where id = '${action}'`);
  }
});
